<?php
declare(strict_types=1);

namespace Bnc;

final class Depense
{
    /** @return array{0:string, 1:array} clause WHERE et parametres */
    private static function filtrer(int $userId, int $exerciceId, array $filtres): array
    {
        $sql = ' WHERE d.user_id = :u AND d.exercice_id = :e';
        $p = ['u' => $userId, 'e' => $exerciceId];

        if (!empty($filtres['q'])) {
            $sql .= ' AND (d.fournisseur LIKE :q OR d.libelle LIKE :q)';
            $p['q'] = '%' . $filtres['q'] . '%';
        }
        if (!empty($filtres['poste'])) {
            $sql .= ' AND d.poste_2035 = :poste';
            $p['poste'] = $filtres['poste'];
        }
        if (!empty($filtres['sans_justificatif'])) {
            $sql .= " AND d.statut = 'active' AND NOT EXISTS (SELECT 1 FROM justificatifs j2 WHERE j2.depense_id = d.id)";
        }
        // Vue épurée : seulement les écritures qui comptent. Le registre, lui,
        // montre toujours tout — corrections comprises.
        if (!empty($filtres['sans_corrections'])) {
            $sql .= " AND d.statut = 'active'";
        }
        // Assujetti : les écritures dont l'éclatement HT / TVA reste à faire.
        if (!empty($filtres['a_ventiler'])) {
            $sql .= " AND d.statut = 'active' AND d.montant_ht IS NULL";
        }
        return [$sql, $p];
    }

    /**
     * Du plus récent au plus ancien. Une ligne d'annulation est rangée juste
     * à côté de l'écriture qu'elle annule — sans cela, celles inscrites après
     * coup (ids élevés) se retrouveraient toutes en tête de liste, loin de leur
     * original —, de sorte qu'une correction se lit d'un seul regard : la
     * nouvelle écriture, l'annulation, puis l'originale.
     */
    public static function lister(int $userId, int $exerciceId, array $filtres = [],
                                  ?int $limite = null, int $decalage = 0): array
    {
        [$where, $p] = self::filtrer($userId, $exerciceId, $filtres);
        $sql = 'SELECT d.*, (SELECT COUNT(*) FROM justificatifs j WHERE j.depense_id = d.id) AS nb_justif
                  FROM depenses d' . $where . ' ORDER BY d.date_decaissement DESC, COALESCE(d.contrepasse_id, d.id) DESC, (d.statut = \'annulation\') DESC, d.id DESC';
        if ($limite !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', $limite, max(0, $decalage));
        }
        return Database::tous($sql, $p);
    }

    public static function compter(int $userId, int $exerciceId, array $filtres = []): int
    {
        [$where, $p] = self::filtrer($userId, $exerciceId, $filtres);
        return (int) Database::valeur('SELECT COUNT(*) FROM depenses d' . $where, $p, 0);
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un('SELECT * FROM depenses WHERE id = :i AND user_id = :u', ['i' => $id, 'u' => $userId]);
    }

    /**
     * Total déductible : chaque dépense n'entre que pour sa quote-part professionnelle. Pour un assujetti à la
     * TVA, c'est le montant hors taxes (plus la TVA non récupérable) qui entre, quand l'écriture est éclatée.
     */
    public static function total(int $userId, int $exerciceId): float
    {
        return (float) Database::valeur(
            'SELECT COALESCE(SUM(' . Tva::exprDepense($userId) . " * quote_part_pro / 100),0)
               FROM depenses WHERE user_id = :u AND exercice_id = :e AND statut = 'active'",
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }

    public static function parPoste(int $userId, int $exerciceId): array
    {
        return Database::tous(
            'SELECT d.poste_2035, p.libelle, SUM(' . Tva::exprDepense($userId, 'd.') . ' * d.quote_part_pro / 100) AS total, COUNT(*) AS nb
               FROM depenses d JOIN postes_2035 p ON p.code = d.poste_2035
              WHERE d.user_id = :u AND d.exercice_id = :e AND d.statut = \'active\'
              GROUP BY d.poste_2035, p.libelle ORDER BY total DESC',
            ['u' => $userId, 'e' => $exerciceId]
        );
    }

    public static function creer(int $userId, int $exerciceId, array $d): int
    {
        return Database::transaction(static function () use ($userId, $exerciceId, $d): int {
            $id = Database::inserer('depenses', [
                'user_id'           => $userId,
                'exercice_id'       => $exerciceId,
                'date_decaissement' => $d['date_decaissement'],
                'fournisseur'       => $d['fournisseur'],
                'libelle'           => $d['libelle'],
                'montant'           => $d['montant'],
                'montant_ht'          => $d['montant_ht'] ?? null,
                'montant_tva'         => $d['montant_tva'] ?? null,
                'taux_tva'            => $d['taux_tva'] ?? null,
                'tva_non_recuperable' => $d['tva_non_recuperable'] ?? null,
                'est_remboursement' => $d['est_remboursement'] ?? 0,
                'poste_2035'        => $d['poste_2035'],
                'categorie_id'      => $d['categorie_id'] ?? null,
                'quote_part_pro'    => $d['quote_part_pro'] ?? 100,
                'mode_reglement'    => $d['mode_reglement'] ?? 'carte',
                'statut'            => 'active',
                'rectifie_id'       => $d['rectifie_id'] ?? null,
                'notes'             => $d['notes'] ?? null,
                'cree_le'           => date('Y-m-d H:i:s'),
            ]);

            Journal::consigner($userId, 'creation', 'depense', $id, $exerciceId, [
                'apres' => self::instantane(self::trouver($userId, $id) ?? []),
            ]);

            return $id;
        });
    }

    /** Instantané pour le journal : les champs qui engagent la déclaration. */
    private static function instantane(array $d): array
    {
        return [
            'date_decaissement' => $d['date_decaissement'] ?? null,
            'fournisseur'       => $d['fournisseur'] ?? null,
            'libelle'           => $d['libelle'] ?? null,
            'montant'           => isset($d['montant']) ? (string) $d['montant'] : null,
            'est_remboursement' => (int) ($d['est_remboursement'] ?? 0),
            'poste_2035'        => $d['poste_2035'] ?? null,
            'quote_part_pro'    => isset($d['quote_part_pro']) ? (int) $d['quote_part_pro'] : null,
            'mode_reglement'    => $d['mode_reglement'] ?? null,
            'statut'            => $d['statut'] ?? null,
        ] + Tva::instantane($d, Tva::CHAMPS_DEPENSE);
    }

    /**
     * Rectifie une dépense : l'originale est annulée par une écriture
     * d'annulation (montant opposé), puis la nouvelle écriture est inscrite.
     * Le registre montre les trois lignes, reliées entre elles.
     *
     * Les justificatifs suivent la correction et l'opération bancaire pointe
     * désormais vers la nouvelle écriture.
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

        // Un appelant qui reconstruit l'écriture à la main (une extension) ne mentionne pas l'éclatement HT / TVA :
        // on le reprend de l'originale plutôt que de le perdre, tant que le TTC n'a pas changé.
        $d = Tva::conserver($d, $origine, Tva::CHAMPS_DEPENSE);

        $nouveau = Database::transaction(static function () use ($userId, $id, $d, $motif, $origine): int {
            $contre = self::contrepasser($userId, $origine, $motif !== '' ? $motif : 'Rectifiée');

            Journal::consigner($userId, 'rectification', 'depense', $id,
                (int) $origine['exercice_id'], [
                    'avant'           => self::instantane($origine),
                    'motif'           => $motif,
                    'contrepassation' => $contre,
                ]);

            $nouveauId = self::creer($userId, (int) $origine['exercice_id'], $d + ['rectifie_id' => $id]);

            // La pièce justifie l'opération, pas telle ligne : elle suit la correction.
            Justificatif::deplacer($userId, 'depense', $id, $nouveauId);
            Affectation::relier($userId, 'depense', $id, $nouveauId);
            return $nouveauId;
        });

        return [$nouveau, ''];
    }

    /**
     * Annule une dépense sans la supprimer : une écriture d'annulation au
     * montant opposé s'inscrit à sa suite. Les justificatifs restent sur
     * l'originale — ils documentent une écriture qui a existé — et l'opération
     * bancaire dont elle est issue retourne dans la file à traiter.
     */
    public static function annuler(int $userId, int $id, string $motif = ''): string
    {
        $d = self::trouver($userId, $id);
        if ($d === null) {
            return 'Écriture introuvable.';
        }
        if ($d['statut'] !== 'active') {
            return 'Cette écriture est déjà annulée.';
        }

        $exercice = Exercice::trouver($userId, (int) $d['exercice_id']);
        if ($exercice !== null && $exercice['statut'] !== 'ouvert') {
            return 'L\'exercice de cette écriture est clos.';
        }

        Database::transaction(static function () use ($userId, $id, $motif, $d): void {
            $contre = self::contrepasser($userId, $d, $motif);
            Journal::consigner($userId, 'annulation', 'depense', $id, (int) $d['exercice_id'], [
                'avant'           => self::instantane($d),
                'motif'           => $motif,
                'contrepassation' => $contre,
            ]);
            Affectation::liberer($userId, 'depense', $id);
        });

        return '';
    }

    /**
     * Marque l'originale « annulée » et inscrit l'écriture d'annulation, à la
     * date de l'originale pour la compenser dans la même période.
     *
     * @return array{id:int} l'écriture d'annulation créée
     */
    private static function contrepasser(int $userId, array $origine, string $motif): array
    {
        Database::modifier('depenses', [
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
     * @return array{id:int}
     */
    public static function inscrireAnnulation(int $userId, array $origine, string $motif): array
    {
        return Corrections::depense($userId, $origine, $motif);
    }

    public static function nbSansJustificatif(int $userId, int $exerciceId): int
    {
        return (int) Database::valeur(
            "SELECT COUNT(*) FROM depenses d
              WHERE d.user_id = :u AND d.exercice_id = :e AND d.statut = 'active'
                AND NOT EXISTS (SELECT 1 FROM justificatifs j WHERE j.depense_id = d.id)",
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }
}
