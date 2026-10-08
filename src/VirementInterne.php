<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Détection des virements entre comptes de l'utilisateur.
 *
 * Un virement interne apparaît deux fois : en débit sur le compte émetteur,
 * en crédit sur le compte destinataire. Traité naïvement, le crédit devient
 * une recette et le débit une dépense — l'erreur atteint alors le double du
 * montant viré. Ces paires sont donc appariées et exclues de la 2035, tout
 * en restant tracées.
 */
final class VirementInterne
{
    /** Écart de date toléré entre les deux moitiés d'un virement. */
    public const JOURS_TOLERANCE = 5;

    /**
     * Cherche la contrepartie d'une opération : même montant en sens opposé,
     * sur un autre compte de l'utilisateur, à quelques jours d'intervalle.
     */
    public static function chercherContrepartie(int $userId, array $tx): ?array
    {
        if (empty($tx['compte_id'])) {
            return null;
        }
        $montant = (float) $tx['montant'];

        return Database::un(
            'SELECT t.*, c.libelle AS compte_libelle
               FROM transactions_brutes t
               JOIN comptes_bancaires c ON c.id = t.compte_id
              WHERE t.user_id = :u
                AND t.id <> :id
                AND t.compte_id IS NOT NULL
                AND t.compte_id <> :compte
                AND t.contrepartie_id IS NULL
                AND ABS(t.montant + :montant) < 0.005
                AND ABS(DATEDIFF(t.date_operation, :date)) <= ' . self::JOURS_TOLERANCE . '
              ORDER BY ABS(DATEDIFF(t.date_operation, :date2)) ASC
              LIMIT 1',
            [
                'u' => $userId, 'id' => (int) $tx['id'],
                'compte' => (int) $tx['compte_id'],
                'montant' => $montant,
                'date' => $tx['date_operation'], 'date2' => $tx['date_operation'],
            ]
        );
    }

    /**
     * Parcourt les opérations en attente et apparie celles qui se répondent.
     * Rien n'est affecté automatiquement : la paire est seulement suggérée,
     * la validation reste à l'utilisateur.
     *
     * @return int nombre de paires repérées
     */
    public static function detecter(int $userId, int $exerciceId): int
    {
        $enAttente = Database::tous(
            "SELECT * FROM transactions_brutes
              WHERE user_id = :u AND exercice_id = :e
                AND statut = 'nouveau' AND compte_id IS NOT NULL AND contrepartie_id IS NULL
              ORDER BY date_operation, id",
            ['u' => $userId, 'e' => $exerciceId]
        );

        $paires = 0;
        $deja = [];

        foreach ($enAttente as $tx) {
            if (isset($deja[(int) $tx['id']])) {
                continue;
            }
            $autre = self::chercherContrepartie($userId, $tx);
            if ($autre === null || isset($deja[(int) $autre['id']])) {
                continue;
            }

            self::apparier($userId, (int) $tx['id'], (int) $autre['id']);
            $deja[(int) $tx['id']] = true;
            $deja[(int) $autre['id']] = true;
            $paires++;
        }
        return $paires;
    }

    /** Marque deux opérations comme les deux moitiés d'un même virement. */
    public static function apparier(int $userId, int $idA, int $idB): void
    {
        Database::transaction(static function () use ($userId, $idA, $idB): void {
            foreach ([[$idA, $idB], [$idB, $idA]] as [$id, $autre]) {
                Database::modifier('transactions_brutes', [
                    'contrepartie_id' => $autre,
                    'suggestion_type' => 'virement_interne',
                ], 'id = :i AND user_id = :u', ['i' => $id, 'u' => $userId]);
            }
        });
    }

    /**
     * Écarte les deux moitiés d'un virement : hors 2035, mais tracées avec
     * leur motif, et non noyées dans un « ignoré » indifférencié.
     */
    public static function valider(int $userId, int $txId): string
    {
        $tx = Affectation::trouver($userId, $txId);
        if ($tx === null) {
            return 'Opération introuvable.';
        }

        $ids = [$txId];
        if (!empty($tx['contrepartie_id'])) {
            $ids[] = (int) $tx['contrepartie_id'];
        }

        Database::transaction(static function () use ($userId, $ids): void {
            foreach ($ids as $id) {
                Database::modifier('transactions_brutes', [
                    'statut'          => 'ignore',
                    'motif_exclusion' => 'virement_interne',
                    'traite_le'       => date('Y-m-d H:i:s'),
                ], 'id = :i AND user_id = :u', ['i' => $id, 'u' => $userId]);
            }
        });
        return '';
    }

    /** Paires repérées mais pas encore validées. */
    public static function pairesEnAttente(int $userId, int $exerciceId): array
    {
        return Database::tous(
            "SELECT t.*, c.libelle AS compte_libelle,
                    a.date_operation AS c_date, a.libelle AS c_libelle, a.montant AS c_montant,
                    ca.libelle AS c_compte
               FROM transactions_brutes t
               JOIN transactions_brutes a ON a.id = t.contrepartie_id
          LEFT JOIN comptes_bancaires c  ON c.id = t.compte_id
          LEFT JOIN comptes_bancaires ca ON ca.id = a.compte_id
              WHERE t.user_id = :u AND t.exercice_id = :e
                AND t.statut = 'nouveau' AND t.montant < 0
              ORDER BY t.date_operation DESC",
            ['u' => $userId, 'e' => $exerciceId]
        );
    }
}
