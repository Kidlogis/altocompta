<?php
declare(strict_types=1);

namespace Bnc;

final class Recette
{
    /** @return array{0:string, 1:array} clause WHERE et parametres */
    private static function filtrer(int $userId, int $exerciceId, array $filtres): array
    {
        $sql = ' WHERE user_id = :u AND exercice_id = :e';
        $p   = ['u' => $userId, 'e' => $exerciceId];

        if (!empty($filtres['q'])) {
            $sql .= ' AND (client LIKE :q OR nature LIKE :q OR reference_facture LIKE :q)';
            $p['q'] = '%' . $filtres['q'] . '%';
        }
        // Vue épurée : seulement les écritures qui comptent. Le registre, lui,
        // montre toujours tout — corrections comprises.
        if (!empty($filtres['sans_corrections'])) {
            $sql .= " AND statut = 'active'";
        }
        // Assujetti : les écritures dont l'éclatement HT / TVA reste à faire.
        if (!empty($filtres['a_ventiler'])) {
            $sql .= " AND statut = 'active' AND montant_ht IS NULL";
        }
        return [$sql, $p];
    }

    public static function lister(int $userId, int $exerciceId, array $filtres = [],
                                  ?int $limite = null, int $decalage = 0): array
    {
        [$where, $p] = self::filtrer($userId, $exerciceId, $filtres);
        $sql = 'SELECT * FROM recettes' . $where . ' ORDER BY date_encaissement DESC, COALESCE(contrepasse_id, id) DESC, (statut = \'annulation\') DESC, numero_chrono DESC';
        if ($limite !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limite, max(0, $decalage));
        }
        return Database::tous($sql, $p);
    }

    public static function compter(int $userId, int $exerciceId, array $filtres = []): int
    {
        [$where, $p] = self::filtrer($userId, $exerciceId, $filtres);
        return (int) Database::valeur('SELECT COUNT(*) FROM recettes' . $where, $p, 0);
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un('SELECT * FROM recettes WHERE id = :i AND user_id = :u', ['i' => $id, 'u' => $userId]);
    }

    /** Total des recettes retenues : hors taxes pour un assujetti dont l'écriture est éclatée, sinon le montant encaissé. */
    public static function total(int $userId, int $exerciceId): float
    {
        return (float) Database::valeur(
            'SELECT COALESCE(SUM(' . Tva::exprRecette($userId) . "),0) FROM recettes
              WHERE user_id = :u AND exercice_id = :e AND statut = 'active'",
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }

    /**
     * Le numéro chronologique doit être continu et sans trou : il est
     * attribué au moment de l'insertion, sous verrou, dans la transaction.
     */
    public static function creer(int $userId, int $exerciceId, array $d): int
    {
        return Database::transaction(static function () use ($userId, $exerciceId, $d): int {
            $suivant = (int) Database::valeur(
                'SELECT COALESCE(MAX(numero_chrono),0) + 1 FROM recettes
                  WHERE user_id = :u AND exercice_id = :e FOR UPDATE',
                ['u' => $userId, 'e' => $exerciceId], 1
            );

            $id = Database::inserer('recettes', [
                'user_id'           => $userId,
                'exercice_id'       => $exerciceId,
                'numero_chrono'     => $suivant,
                'date_encaissement' => $d['date_encaissement'],
                'client'            => $d['client'],
                'nature'            => $d['nature'],
                'montant'           => $d['montant'],
                'montant_ht'        => $d['montant_ht'] ?? null,
                'montant_tva'       => $d['montant_tva'] ?? null,
                'taux_tva'          => $d['taux_tva'] ?? null,
                'est_avoir'         => $d['est_avoir'] ?? 0,
                'mode_reglement'    => $d['mode_reglement'] ?? 'virement',
                'poste_2035'        => $d['poste_2035'] ?? 'AA',
                'categorie_id'      => $d['categorie_id'] ?? null,
                'statut'            => 'active',
                'rectifie_id'       => $d['rectifie_id'] ?? null,
                'reference_facture' => $d['reference_facture'] ?? null,
                'altoviz_invoice_id'=> $d['altoviz_invoice_id'] ?? null,
                'notes'             => $d['notes'] ?? null,
                'cree_le'           => date('Y-m-d H:i:s'),
            ]);

            Journal::consigner($userId, 'creation', 'recette', $id, $exerciceId, [
                'apres' => self::instantane(self::trouver($userId, $id) ?? []),
            ]);

            return $id;
        });
    }

    /**
     * Instantané d'une écriture pour le journal : seuls les champs qui
     * engagent la déclaration, pour qu'une comparaison avant/après reste
     * lisible et ne dépende pas des colonnes techniques.
     */
    private static function instantane(array $r): array
    {
        return [
            'numero_chrono'     => isset($r['numero_chrono']) ? (int) $r['numero_chrono'] : null,
            'date_encaissement' => $r['date_encaissement'] ?? null,
            'client'            => $r['client'] ?? null,
            'nature'            => $r['nature'] ?? null,
            'montant'           => isset($r['montant']) ? (string) $r['montant'] : null,
            'est_avoir'         => (int) ($r['est_avoir'] ?? 0),
            'mode_reglement'    => $r['mode_reglement'] ?? null,
            'poste_2035'        => $r['poste_2035'] ?? null,
            'reference_facture' => $r['reference_facture'] ?? null,
            'statut'            => $r['statut'] ?? null,
        ] + Tva::instantane($r, Tva::CHAMPS_RECETTE);
    }

    /**
     * Rectifie une écriture : l'originale est annulée par une écriture
     * d'annulation (montant opposé), puis la nouvelle écriture est inscrite.
     * Le registre montre donc les trois lignes, reliées entre elles, et la
     * somme des montants imprimés reste égale au total déclaré.
     *
     * @return array{0:?int, 1:string} identifiant de la rectificative, ou motif d'échec
     */
    public static function rectifier(int $userId, int $id, array $d, string $motif = ''): array
    {
        $origine = self::trouver($userId, $id);
        if ($origine === null) {
            return [null, 'Écriture introuvable.'];
        }
        if ($origine['statut'] !== 'active') {
            return [null, 'Cette écriture est déjà annulée : elle ne peut plus être rectifiée.'];
        }

        $exercice = Exercice::trouver($userId, (int) $origine['exercice_id']);
        if ($exercice !== null && $exercice['statut'] !== 'ouvert') {
            return [null, 'L\'exercice de cette écriture est clos.'];
        }

        // L'éclatement HT / TVA n'est pas perdu par un appelant qui ne le mentionne pas (voir Depense::rectifier).
        $d = Tva::conserver($d, $origine, Tva::CHAMPS_RECETTE);

        $nouveau = Database::transaction(static function () use ($userId, $id, $d, $motif, $origine): int {
            $contre = self::contrepasser($userId, $origine, $motif !== '' ? $motif : 'Rectifiée');

            Journal::consigner($userId, 'rectification', 'recette', $id,
                (int) $origine['exercice_id'], [
                    'avant'            => self::instantane($origine),
                    'motif'            => $motif,
                    'contrepassation'  => $contre,
                ]);

            $nouveauId = self::creer($userId, (int) $origine['exercice_id'], $d + ['rectifie_id' => $id]);

            // La pièce justifie l'opération, pas telle ligne : elle suit la correction.
            Justificatif::deplacer($userId, 'recette', $id, $nouveauId);
            Affectation::relier($userId, 'recette', $id, $nouveauId);
            return $nouveauId;
        });

        return [$nouveau, ''];
    }

    /**
     * Annule une écriture sans la supprimer : elle garde son numéro, une
     * écriture d'annulation au montant opposé vient s'inscrire à sa suite, et
     * l'opération bancaire dont elle est issue retourne dans la file à traiter.
     */
    public static function annuler(int $userId, int $id, string $motif = ''): string
    {
        $r = self::trouver($userId, $id);
        if ($r === null) {
            return 'Écriture introuvable.';
        }
        if ($r['statut'] !== 'active') {
            return 'Cette écriture est déjà annulée.';
        }

        $exercice = Exercice::trouver($userId, (int) $r['exercice_id']);
        if ($exercice !== null && $exercice['statut'] !== 'ouvert') {
            return 'L\'exercice de cette écriture est clos.';
        }

        Database::transaction(static function () use ($userId, $id, $motif, $r): void {
            $contre = self::contrepasser($userId, $r, $motif);
            Journal::consigner($userId, 'annulation', 'recette', $id, (int) $r['exercice_id'], [
                'avant'           => self::instantane($r),
                'motif'           => $motif,
                'contrepassation' => $contre,
            ]);
            Affectation::liberer($userId, 'recette', $id);
        });

        return '';
    }

    /**
     * Marque l'originale « annulée » et inscrit l'écriture d'annulation.
     *
     * L'annulation prend la date de l'originale : elle doit la compenser dans
     * la même période, sans quoi un total mensuel ou annuel pourrait bouger
     * selon le jour où l'on corrige. C'est le numéro (donc l'ordre
     * d'enregistrement) et l'horodatage de création qui disent quand la
     * correction a eu lieu — et le journal chaîné en garde l'heure exacte.
     *
     * @return array{id:int, numero:int} l'écriture d'annulation créée
     */
    private static function contrepasser(int $userId, array $origine, string $motif): array
    {
        Database::modifier('recettes', [
            'statut'           => 'annulee',
            'annule_le'        => date('Y-m-d H:i:s'),
            'motif_annulation' => mb_substr($motif, 0, 255) ?: null,
        ], 'id = :i AND user_id = :u', ['i' => (int) $origine['id'], 'u' => $userId]);

        return self::inscrireAnnulation($userId, $origine, $motif);
    }

    /**
     * Inscrit seulement l'écriture d'annulation d'une écriture déjà marquée
     * annulée. Public pour la reprise des corrections faites avant que
     * l'annulation ne s'inscrive au registre.
     *
     * @return array{id:int, numero:int}
     */
    public static function inscrireAnnulation(int $userId, array $origine, string $motif): array
    {
        return Corrections::recette($userId, $origine, $motif);
    }

    /** Rapproche une référence de facture d'une recette déjà saisie. */
    public static function parReference(int $userId, string $reference): ?array
    {
        return Database::un(
            'SELECT * FROM recettes WHERE user_id = :u AND reference_facture = :r LIMIT 1',
            ['u' => $userId, 'r' => $reference]
        );
    }
}
