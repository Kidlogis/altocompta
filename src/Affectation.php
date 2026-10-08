<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Passage d'une opération bancaire brute vers le livre des recettes
 * ou le registre des dépenses.
 */
final class Affectation
{
    public static function enAttente(int $userId, int $exerciceId, int $limite = 200): array
    {
        return Database::tous(
            "SELECT t.*, r.motif AS regle_motif, c.libelle AS compte_libelle,
                    cm.libelle AS categorie_libelle,
                    cc.libelle AS contrepartie_compte, a.date_operation AS contrepartie_date
               FROM transactions_brutes t
          LEFT JOIN regles_affectation r  ON r.id = t.suggestion_regle
          LEFT JOIN comptes_bancaires c   ON c.id = t.compte_id
          LEFT JOIN transactions_brutes a ON a.id = t.contrepartie_id
          LEFT JOIN comptes_bancaires cc  ON cc.id = a.compte_id
          LEFT JOIN categories_metier cm  ON cm.id = t.suggestion_categorie
              WHERE t.user_id = :u AND t.exercice_id = :e AND t.statut = 'nouveau'
              ORDER BY t.date_operation DESC, t.id DESC
              LIMIT " . (int) $limite,
            ['u' => $userId, 'e' => $exerciceId]
        );
    }

    /** Libellés lisibles des motifs d'exclusion, pour l'affichage. */
    public const MOTIFS_EXCLUSION = [
        'prive'            => 'Privé',
        'virement_interne' => 'Virement interne',
        'tva'              => 'TVA',
        'apport_retrait'   => 'Apport ou retrait',
        'doublon'          => 'Doublon',
        'autre'            => 'Autre',
    ];

    /**
     * Opérations écartées de la 2035 (ignorées), avec leur motif — pour
     * qu'un mouvement exclu reste consultable et justifiable, au lieu de
     * disparaître silencieusement une fois le bouton Ignorer utilisé.
     */
    public static function exclues(int $userId, int $exerciceId, int $limite = 300): array
    {
        return Database::tous(
            "SELECT t.*, c.libelle AS compte_libelle
               FROM transactions_brutes t
          LEFT JOIN comptes_bancaires c ON c.id = t.compte_id
              WHERE t.user_id = :u AND t.exercice_id = :e AND t.statut = 'ignore'
              ORDER BY t.traite_le DESC, t.id DESC
              LIMIT " . (int) $limite,
            ['u' => $userId, 'e' => $exerciceId]
        );
    }

    public static function nbExclues(int $userId, int $exerciceId): int
    {
        return (int) Database::valeur(
            "SELECT COUNT(*) FROM transactions_brutes
              WHERE user_id = :u AND exercice_id = :e AND statut = 'ignore'",
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }

    /**
     * Rouvre une opération ignorée par erreur : elle retourne dans la file
     * d'attente, prête à être affectée normalement. Un virement interne a
     * deux lignes ; rouvrir l'une ne rouvre pas l'autre automatiquement,
     * qui reste consultable ici avec son propre bouton.
     */
    public static function rouvrir(int $userId, int $txId): string
    {
        $tx = self::trouver($userId, $txId);
        if ($tx === null || $tx['statut'] !== 'ignore') {
            return 'Opération introuvable ou déjà en attente.';
        }

        $exercice = Exercice::trouver($userId, (int) $tx['exercice_id']);
        if ($exercice !== null && $exercice['statut'] !== 'ouvert') {
            return 'L\'exercice de cette opération est clos.';
        }

        Database::modifier('transactions_brutes', [
            'statut'          => 'nouveau',
            'motif_exclusion' => null,
            'traite_le'       => null,
        ], 'id = :i AND user_id = :u', ['i' => $txId, 'u' => $userId]);
        return '';
    }

    public static function nbEnAttente(int $userId, int $exerciceId): int
    {
        return (int) Database::valeur(
            "SELECT COUNT(*) FROM transactions_brutes
              WHERE user_id = :u AND exercice_id = :e AND statut = 'nouveau'",
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un(
            'SELECT * FROM transactions_brutes WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]
        );
    }

    /**
     * Affecte une opération. $poste est requis pour une dépense.
     * Retourne '' si tout s'est bien passé, le motif du refus sinon.
     */
    public static function affecter(int $userId, int $txId, string $type,
                                    ?string $poste = null, ?string $motif = null,
                                    ?int $categorieId = null): string
    {
        // Le formateur choisit une catégorie métier ; le poste 2035 en découle.
        if ($categorieId !== null && $categorieId > 0) {
            $deduit = CategorieMetier::poste($userId, $categorieId);
            if ($deduit !== null) {
                $poste = $deduit;
            }
        }

        $tx = self::trouver($userId, $txId);
        if ($tx === null) {
            return 'Opération introuvable.';
        }
        if ($tx['statut'] !== 'nouveau') {
            return 'Cette opération a déjà été traitée.';
        }

        $ex = Exercice::trouver($userId, (int) $tx['exercice_id']);
        if ($ex === null || !Exercice::ouvert($ex)) {
            return 'L\'exercice de cette opération est clos.';
        }

        if ($type === 'virement_interne') {
            return VirementInterne::valider($userId, $txId);
        }

        if ($type === 'hors_bnc') {
            return self::ignorer($userId, $txId, (string) ($motif ?? 'prive'));
        }

        // Une immobilisation ne se crée pas à la volée : la durée et le mode
        // d'amortissement doivent être choisis. On renvoie vers le formulaire,
        // pré-rempli avec l'opération.
        if ($type === 'immobilisation') {
            return 'REDIRECTION:/immobilisations/nouvelle?tx=' . (int) $txId;
        }

        // Le signe porte l'information : un credit affecte en recette est une
        // recette positive, un debit affecte en recette est un avoir. Prendre
        // la valeur absolue enregistrerait un avoir a l'envers et fausserait
        // le resultat du double du montant.
        $signe   = (float) $tx['montant'];
        $inverse = ($signe < 0 && $type === 'recette') || ($signe > 0 && $type === 'depense');

        try {
            return Database::transaction(static function () use ($userId, $tx, $type, $poste, $signe, $inverse, $categorieId): string {

                if ($type === 'recette') {
                    // Si une recette porte déjà la référence de facture, on rapproche au lieu de dupliquer.
                    $ref = self::referenceFacture($tx['libelle']);
                    if ($ref !== null && Recette::parReference($userId, $ref) !== null) {
                        self::marquer($userId, (int) $tx['id'], 'ignore', null, null);
                        return '';
                    }

                    $id = Recette::creer($userId, (int) $tx['exercice_id'], [
                        'date_encaissement' => $tx['date_operation'],
                        'client'            => self::deviner($tx['libelle']),
                        'nature'            => $tx['libelle'],
                        'montant'           => round($signe, 2),
                        'est_avoir'         => $inverse ? 1 : 0,
                        'mode_reglement'    => self::mode($tx['libelle']),
                        'poste_2035'        => $poste && Poste2035::existe($poste, 'recette') ? $poste : 'AA',
                        // Le poste n'est une indication que s'il a été donné : le poste par défaut (AA) n'en est pas une.
                        'categorie_id'      => $categorieId ?: ($tx['suggestion_categorie'] ?: ($poste && Poste2035::existe($poste, 'recette') ? CategorieMetier::uniquePourPoste($userId, $poste, 'recette') : null)),
                        'reference_facture' => $ref,
                    ]);
                    Justificatif::transferer($userId, (int) $tx['id'], 'recette', $id);
                    self::marquer($userId, (int) $tx['id'], 'affecte', 'recette', $id);
                    return '';
                }

                if (!$poste || !Poste2035::existe($poste, 'depense')) {
                    return 'Choisir un poste 2035 pour cette dépense.';
                }

                // Une depense est portee positivement : le debit (montant negatif
                // au releve) devient un montant positif. Un credit affecte en
                // depense est donc un remboursement recu, enregistre en negatif.
                $id = Depense::creer($userId, (int) $tx['exercice_id'], [
                    'date_decaissement' => $tx['date_operation'],
                    'fournisseur'       => self::deviner($tx['libelle']),
                    'libelle'           => $tx['libelle'],
                    'montant'           => round(-$signe, 2),
                    'est_remboursement' => $inverse ? 1 : 0,
                    'poste_2035'        => $poste,
                    // Une catégorie choisie, sinon suggérée, sinon l'unique catégorie du poste s'il n'y en a qu'une : une règle
                    // d'affectation ne connaît qu'un poste, et la fiche de la dépense a besoin d'une catégorie.
                    'categorie_id'      => $categorieId ?: ($tx['suggestion_categorie'] ?: CategorieMetier::uniquePourPoste($userId, (string) $poste, 'depense')),
                    'quote_part_pro'    => 100,
                    'mode_reglement'    => self::mode($tx['libelle']),
                ]);
                Justificatif::transferer($userId, (int) $tx['id'], 'depense', $id);
                self::marquer($userId, (int) $tx['id'], 'affecte', 'depense', $id);
                return '';
            });
        } catch (\Throwable $e) {
            return 'Affectation impossible : ' . $e->getMessage();
        }
    }

    /**
     * Écarte une opération de la 2035 en gardant la trace du motif : un
     * virement personnel, un mouvement de TVA et un apport ne se confondent
     * pas, même s'ils sortent tous du résultat.
     */
    public static function ignorer(int $userId, int $txId, string $motif = 'prive'): string
    {
        $motifs = ['prive', 'virement_interne', 'tva', 'apport_retrait', 'doublon', 'autre'];
        Database::modifier('transactions_brutes', [
            'statut'          => 'ignore',
            'motif_exclusion' => in_array($motif, $motifs, true) ? $motif : 'autre',
            'traite_le'       => date('Y-m-d H:i:s'),
        ], "id = :i AND user_id = :u AND statut = 'nouveau'", ['i' => $txId, 'u' => $userId]);
        return '';
    }

    /**
     * Redonne à l'opération bancaire sa place dans la file quand l'écriture qui
     * en est issue est annulée sans remplaçante. Sans cela, l'opération restait
     * « affectée » à une écriture annulée : ni dans les livres, ni dans la file
     * à traiter, donc perdue de vue.
     *
     * @return int nombre d'opérations libérées
     */
    public static function liberer(int $userId, string $type, int $ecritureId): int
    {
        return Database::modifier('transactions_brutes',
            ['statut' => 'nouveau', 'affecte_type' => null, 'affecte_id' => null, 'traite_le' => null],
            "user_id = :u AND affecte_type = :t AND affecte_id = :i AND statut = 'affecte'",
            ['u' => $userId, 't' => $type, 'i' => $ecritureId]
        );
    }

    /** Suit une correction : l'opération bancaire pointe vers la nouvelle écriture, non vers l'annulée. */
    public static function relier(int $userId, string $type, int $ancienId, int $nouveauId): int
    {
        return Database::modifier('transactions_brutes', ['affecte_id' => $nouveauId],
            'user_id = :u AND affecte_type = :t AND affecte_id = :i',
            ['u' => $userId, 't' => $type, 'i' => $ancienId]
        );
    }

    /** Rattache une opération bancaire à l'immobilisation créée depuis elle. */
    public static function lierImmobilisation(int $userId, int $txId, int $immoId): void
    {
        Justificatif::transferer($userId, $txId, 'immobilisation', $immoId);
        self::marquer($userId, $txId, 'affecte', 'immobilisation', $immoId);
    }

    /** Applique en bloc les suggestions des règles. Retourne le nombre d'opérations traitées. */
    public static function validerSuggestions(int $userId, int $exerciceId): int
    {
        $n = 0;
        foreach (self::enAttente($userId, $exerciceId, 500) as $tx) {
            $type = $tx['suggestion_type'];
            if ($type === null) {
                continue;
            }
            // Une dépense sans poste suggéré reste à trancher à la main,
            // et une immobilisation demande durée et mode : jamais en bloc.
            if ($type === 'depense' && empty($tx['suggestion_poste'])) {
                continue;
            }
            if ($type === 'immobilisation' || $type === 'virement_interne') {
                continue;
            }
            if (self::affecter($userId, (int) $tx['id'], $type, $tx['suggestion_poste'],
                               null, (int) ($tx['suggestion_categorie'] ?? 0)) === '') {
                $n++;
            }
        }
        return $n;
    }

    private static function marquer(int $userId, int $txId, string $statut, ?string $type, ?int $cibleId): void
    {
        Database::modifier('transactions_brutes', [
            'statut'       => $statut,
            'affecte_type' => $type,
            'affecte_id'   => $cibleId,
            'traite_le'    => date('Y-m-d H:i:s'),
        ], 'id = :i AND user_id = :u', ['i' => $txId, 'u' => $userId]);
    }

    /** Extrait une référence de facture du libellé bancaire, si elle s'y trouve. */
    public static function referenceFacture(string $libelle): ?string
    {
        if (preg_match('/\b(FA[-_ ]?\d{4}[-_ ]?\d{3,6}|FACT[-_ ]?\d{3,10})\b/i', $libelle, $m)) {
            return strtoupper(str_replace([' ', '_'], '-', $m[1]));
        }
        return null;
    }

    /** Devine le tiers à partir du libellé bancaire, en retirant les préfixes techniques. */
    public static function deviner(string $libelle): string
    {
        $s = preg_replace(
            '/^(VIR(EMENT)?( SEPA)?|PRLV( SEPA)?|PRELEVEMENT|CB|CARTE|PAIEMENT( CB)?|ACHAT CB|CHQ|CHEQUE|REM(ISE)?)\s+/i',
            '', trim($libelle)
        ) ?? $libelle;
        $s = preg_replace('/\b(REF|RUM|ID)[-: ]?\S+/i', '', $s) ?? $s;
        $s = preg_replace('/\s{2,}/', ' ', $s) ?? $s;
        $s = trim($s, " -–—:");
        return mb_substr($s !== '' ? $s : $libelle, 0, 190);
    }

    private static function mode(string $libelle): string
    {
        $l = mb_strtoupper($libelle);
        return match (true) {
            str_contains($l, 'PRLV') || str_contains($l, 'PRELEV') => 'prelevement',
            str_contains($l, 'VIR')                                => 'virement',
            str_contains($l, 'CB') || str_contains($l, 'CARTE')    => 'carte',
            str_contains($l, 'CHQ') || str_contains($l, 'CHEQUE')  => 'cheque',
            default                                                => 'autre',
        };
    }
}
