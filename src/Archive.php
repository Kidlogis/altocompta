<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Archives d'exercice : instantané figé et empreint.
 *
 * Volet « conservation et archivage » de l'article 286, I bis du CGI. À la
 * clôture, l'intégralité des écritures de l'exercice — y compris les
 * annulées, qui font partie de l'histoire — est sérialisée puis empreinte
 * en SHA-256. L'archive permet, des années plus tard, de rapporter la preuve
 * que ce qui a été déclaré n'a pas bougé depuis : il suffit de recalculer
 * l'empreinte du contenu conservé et de la comparer à celle enregistrée.
 *
 * L'archive est en lecture seule une fois créée : aucune méthode ne la
 * modifie. Clore à nouveau un exercice rouvert produit une archive
 * supplémentaire, horodatée, sans écraser la précédente — l'historique des
 * états successifs est lui aussi une information.
 */
final class Archive
{
    /** Version du format sérialisé, pour qu'une relecture future sache l'interpréter. */
    /** 2 : les écritures portent leur éclatement HT / TVA quand elles en ont un (assujettis). */
    public const FORMAT = 2;

    /**
     * Construit et enregistre l'archive d'un exercice.
     *
     * @return array{0:?int, 1:string} identifiant de l'archive, ou motif d'échec
     */
    public static function creer(int $userId, int $exerciceId): array
    {
        $exercice = Exercice::trouver($userId, $exerciceId);
        if ($exercice === null) {
            return [null, 'Exercice introuvable.'];
        }

        return Database::transaction(static function () use ($userId, $exerciceId, $exercice): array {
            $contenu = self::instantane($userId, $exerciceId, $exercice);

            $json = json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return [null, 'Sérialisation de l\'archive impossible.'];
            }

            $empreinte = hash('sha256', $json);
            $sequence  = Journal::derniereSequence($userId);

            $id = Database::inserer('archives_exercice', [
                'user_id'        => $userId,
                'exercice_id'    => $exerciceId,
                'cree_le'        => date('Y-m-d H:i:s'),
                'nb_recettes'    => $contenu['totaux']['nb_recettes'],
                'nb_depenses'    => $contenu['totaux']['nb_depenses'],
                'total_recettes' => $contenu['totaux']['total_recettes'],
                'total_depenses' => $contenu['totaux']['total_depenses'],
                'resultat'       => $contenu['totaux']['resultat'],
                'sequence_fin'   => $sequence,
                'empreinte'      => $empreinte,
                'contenu'        => $json,
            ]);

            // L'archivage est lui-même un acte, consigné comme les autres.
            Journal::consigner($userId, 'archivage', 'archive', $id, $exerciceId, [
                'empreinte'    => $empreinte,
                'sequence_fin' => $sequence,
                'totaux'       => $contenu['totaux'],
            ]);

            return [$id, ''];
        });
    }

    /**
     * Instantané complet. Les écritures annulées sont incluses : une archive
     * qui ne garderait que les écritures actives perdrait la trace de ce qui
     * a été corrigé, ce que l'inaltérabilité cherche justement à préserver.
     */
    private static function instantane(int $userId, int $exerciceId, array $exercice): array
    {
        $recettes = Database::tous(
            'SELECT numero_chrono, date_encaissement, client, nature, montant, montant_ht, montant_tva, taux_tva, est_avoir,
                    mode_reglement, poste_2035, reference_facture, statut, motif_annulation,
                    rectifie_id, contrepasse_id, cree_le
               FROM recettes WHERE user_id = :u AND exercice_id = :e
              ORDER BY numero_chrono',
            ['u' => $userId, 'e' => $exerciceId]
        );

        $depenses = Database::tous(
            'SELECT id, date_decaissement, fournisseur, libelle, montant, montant_ht, montant_tva, taux_tva,
                    tva_non_recuperable, est_remboursement,
                    poste_2035, quote_part_pro, mode_reglement, statut, motif_annulation,
                    rectifie_id, contrepasse_id, cree_le
               FROM depenses WHERE user_id = :u AND exercice_id = :e
              ORDER BY date_decaissement, id',
            ['u' => $userId, 'e' => $exerciceId]
        );

        $calc = Declaration::calculer($userId, $exerciceId);

        $actives = static fn(array $lignes): array
            => array_values(array_filter($lignes, static fn($l) => $l['statut'] === 'active'));

        return [
            'format'    => self::FORMAT,
            'genere_le' => date('c'),
            'exercice'  => [
                'annee'      => (int) $exercice['annee'],
                'date_debut' => $exercice['date_debut'],
                'date_fin'   => $exercice['date_fin'],
                'statut'     => $exercice['statut'],
            ],
            'declarant' => self::declarant($userId),
            'totaux'    => [
                'nb_recettes'    => count($actives($recettes)),
                'nb_depenses'    => count($actives($depenses)),
                // Une correction laisse deux lignes : l'écriture annulée et son écriture
                // d'annulation. On les compte à part, sans les confondre.
                'nb_annulees'    => count(array_filter($recettes, static fn($l) => $l['statut'] === 'annulee'))
                                  + count(array_filter($depenses, static fn($l) => $l['statut'] === 'annulee')),
                'nb_ecritures_annulation' => count(array_filter($recettes, static fn($l) => $l['statut'] === 'annulation'))
                                  + count(array_filter($depenses, static fn($l) => $l['statut'] === 'annulation')),
                'total_recettes' => round((float) $calc['total_recettes'], 2),
                'total_depenses' => round((float) $calc['total_depenses'], 2),
                'resultat'       => round((float) $calc['resultat'], 2),
            ],
            'declaration' => [
                'recettes' => array_map(static fn($r) => ['total' => $r['total']], $calc['recettes']),
                'depenses' => array_map(static fn($d) => ['total' => $d['total']], $calc['depenses']),
            ],
            'recettes' => $recettes,
            'depenses' => $depenses,
        ];
    }

    /**
     * Identité du déclarant telle qu'elle est au moment de la clôture : si la
     * fiche change ensuite — déménagement, nouvelle dénomination —, l'archive
     * garde ce qui figurait sur la déclaration effectivement déposée.
     */
    private static function declarant(int $userId): array
    {
        $u = Database::un(
            'SELECT nom, ' . implode(', ', Utilisateur::COLONNES_PROFIL) . ' FROM users WHERE id = :i',
            ['i' => $userId]
        ) ?? [];
        return $u + ['nom' => '', 'denomination' => null, 'siret' => null];
    }

    /** @return array<int,array> archives d'un exercice, la plus récente d'abord */
    public static function pourExercice(int $userId, int $exerciceId): array
    {
        return Database::tous(
            'SELECT id, cree_le, nb_recettes, nb_depenses, total_recettes, total_depenses,
                    resultat, sequence_fin, empreinte
               FROM archives_exercice
              WHERE user_id = :u AND exercice_id = :e ORDER BY cree_le DESC, id DESC',
            ['u' => $userId, 'e' => $exerciceId]
        );
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un(
            'SELECT * FROM archives_exercice WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]
        );
    }

    /**
     * Recalcule l'empreinte du contenu conservé et la compare à celle
     * enregistrée : c'est l'opération qui rapporte la preuve d'intégrité.
     *
     * @return array{valide:bool, attendue:string, calculee:string}
     */
    public static function verifier(int $userId, int $id): array
    {
        $a = self::trouver($userId, $id);
        if ($a === null) {
            return ['valide' => false, 'attendue' => '', 'calculee' => ''];
        }
        $calculee = hash('sha256', (string) $a['contenu']);
        return [
            'valide'   => hash_equals((string) $a['empreinte'], $calculee),
            'attendue' => (string) $a['empreinte'],
            'calculee' => $calculee,
        ];
    }

    public static function nb(int $userId, int $exerciceId): int
    {
        return (int) Database::valeur(
            'SELECT COUNT(*) FROM archives_exercice WHERE user_id = :u AND exercice_id = :e',
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }
}
