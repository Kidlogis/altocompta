<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Contrôle des doublons probables parmi les opérations bancaires importées.
 *
 * Une même opération peut arriver deux fois sans que rien ne les relie : une
 * opération « en attente » puis sa version définitive, au libellé et à la
 * date différents ; le même relevé reçu d'une banque en ligne et d'un
 * agrégateur ; deux comptes qui se recoupent. Leurs empreintes diffèrent,
 * aucun index unique ne les rapproche.
 *
 * Ce contrôle ne supprime rien et ne décide rien : il propose des paires à
 * examiner. Deux opérations identiques d'un même compte, le même jour, sont
 * en revanche écartées d'office — c'est ce que fait une banque quand elle
 * crédite deux fois le même montant au même tiers, et les fusionner ferait
 * disparaître du chiffre d'affaires.
 */
final class Doublons
{
    /** Écart de date toléré entre les deux moitiés : une opération en attente porte souvent la date de l'achat, la définitive celle du débit. */
    private const FENETRE_JOURS = 4;

    /** Part minimale des mots communs, rapportée au plus court des deux libellés. */
    private const SEUIL_SIMILARITE = 0.6;

    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /** Mots techniques des banques, sans valeur pour reconnaître un tiers. */
    private const MOTS_VIDES = [
        'vir', 'virement', 'sepa', 'inst', 'instantane', 'prlv', 'prelevement', 'carte', 'cb',
        'paiement', 'pmt', 'achat', 'retrait', 'ref', 'rum', 'fr', 'com', 'www', 'par', 'pour', 'sur',
        'de', 'du', 'des', 'la', 'le', 'les', 'et', 'ei', 'sas', 'sarl', 'eurl', 'sa', 'no', 'num',
    ];

    /**
     * Mots significatifs d'un libellé : sans accents, sans nombres, références
     * ni dates, sans les mots techniques des banques.
     *
     * @return array<int,string>
     */
    public static function mots(string $libelle): array
    {
        // Table plutôt que l'extension intl : elle n'est pas dans les prérequis du
        // projet, et un hébergement qui ne l'active pas ferait planter la page.
        $s = strtr(mb_strtolower($libelle), self::ACCENTS);
        $mots = [];
        foreach (preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $m) {
            if (preg_match('/\d/', $m) || strlen($m) < 2 || in_array($m, self::MOTS_VIDES, true)) {
                continue;
            }
            $mots[$m] = $m;
        }
        return array_values($mots);
    }

    /** Deux libellés désignent-ils vraisemblablement le même tiers ? */
    public static function similaires(string $a, string $b): bool
    {
        $ma = self::mots($a);
        $mb = self::mots($b);
        if (!$ma || !$mb) {
            // Rien d'exploitable après nettoyage : on ne conclut que sur l'identité stricte.
            return mb_strtolower(trim($a)) === mb_strtolower(trim($b)) && trim($a) !== '';
        }
        $communs = count(array_intersect($ma, $mb));
        return $communs >= 1 && $communs / min(count($ma), count($mb)) >= self::SEUIL_SIMILARITE;
    }

    /**
     * Paires d'opérations qui ressemblent à un doublon, la plus ancienne d'abord.
     *
     * @return array<int,array{a:array, b:array, raison:string}>
     */
    public static function probables(int $userId, int $limite = 100): array
    {
        $paires = Database::tous(
            'SELECT a.id AS a_id, b.id AS b_id
               FROM transactions_brutes a
               JOIN transactions_brutes b
                 ON b.user_id = a.user_id AND b.montant = a.montant AND b.id > a.id
                AND ABS(DATEDIFF(a.date_operation, b.date_operation)) <= ' . self::FENETRE_JOURS . '
              WHERE a.user_id = :u AND a.montant <> 0
                AND NOT (a.statut = \'ignore\' AND a.motif_exclusion = \'doublon\')
                AND NOT (b.statut = \'ignore\' AND b.motif_exclusion = \'doublon\')
                AND NOT EXISTS (SELECT 1 FROM doublons_ecartes e
                                 WHERE e.user_id = a.user_id AND e.tx_a = a.id AND e.tx_b = b.id)
              ORDER BY a.date_operation DESC, a.id DESC
              LIMIT 1000',
            ['u' => $userId]
        );
        if (!$paires) {
            return [];
        }

        $ids = [];
        foreach ($paires as $p) {
            $ids[(int) $p['a_id']] = true;
            $ids[(int) $p['b_id']] = true;
        }
        $liste = implode(',', array_map('intval', array_keys($ids)));
        $lignes = [];
        foreach (Database::tous(
            "SELECT t.*, c.libelle AS compte_nom
               FROM transactions_brutes t LEFT JOIN comptes_bancaires c ON c.id = t.compte_id
              WHERE t.user_id = :u AND t.id IN ($liste)", ['u' => $userId]
        ) as $l) {
            $lignes[(int) $l['id']] = $l;
        }

        $sortie = [];
        foreach ($paires as $p) {
            $a = $lignes[(int) $p['a_id']] ?? null;
            $b = $lignes[(int) $p['b_id']] ?? null;
            if ($a === null || $b === null) {
                continue;
            }
            $raison = self::raison($a, $b);
            if ($raison !== null) {
                $sortie[] = ['a' => $a, 'b' => $b, 'raison' => $raison];
                if (count($sortie) >= $limite) {
                    break;
                }
            }
        }
        return $sortie;
    }

    /** Pourquoi ces deux lignes semblent n'en faire qu'une — ou null si elles n'y ressemblent pas. */
    private static function raison(array $a, array $b): ?string
    {
        // Jumelles légitimes : même source, même compte, même jour, même libellé.
        // Une banque qui crédite deux fois le même tiers du même montant produit
        // exactement cela ; les rapprocher ferait disparaître une opération réelle.
        $memeCompte = ($a['compte_id'] ?? null) === ($b['compte_id'] ?? null)
                   && (string) ($a['compte'] ?? '') === (string) ($b['compte'] ?? '');
        $memeLibelle = mb_strtolower(trim((string) $a['libelle'])) === mb_strtolower(trim((string) $b['libelle']));
        if ($a['source'] === $b['source'] && $memeCompte && $memeLibelle && $a['date_operation'] === $b['date_operation']) {
            return null;
        }

        if (!self::similaires((string) $a['libelle'], (string) $b['libelle'])) {
            return null;
        }

        if ($a['source'] !== $b['source']) {
            return 'Même opération reçue de deux sources (' . $a['source'] . ' et ' . $b['source'] . ').';
        }
        if (!$memeCompte) {
            return 'Même opération présente sur deux comptes.';
        }
        if ($a['date_operation'] !== $b['date_operation'] || !$memeLibelle) {
            return 'Libellé ou date différents pour un même montant : une opération en attente puis sa version définitive ?';
        }
        return 'Opérations très proches.';
    }

    public static function compter(int $userId): int
    {
        static $cache = [];
        return $cache[$userId] ??= count(self::probables($userId, 500));
    }

    /** Mémorise que deux opérations ne sont pas un doublon : la paire ne sera plus proposée. */
    public static function ecarter(int $userId, int $x, int $y): void
    {
        [$a, $b] = $x < $y ? [$x, $y] : [$y, $x];
        $ok = Database::valeur(
            'SELECT COUNT(*) FROM transactions_brutes WHERE user_id = :u AND id IN (:a, :b)',
            ['u' => $userId, 'a' => $a, 'b' => $b], 0
        );
        if ((int) $ok !== 2) {
            return;
        }
        Database::executer(
            'INSERT IGNORE INTO doublons_ecartes (user_id, tx_a, tx_b, cree_le) VALUES (:u, :a, :b, :c)',
            ['u' => $userId, 'a' => $a, 'b' => $b, 'c' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Garde une des deux opérations et écarte l'autre.
     *
     * Si l'écartée a déjà donné une écriture, celle-ci est annulée (avec son
     * écriture d'annulation, comme toute correction) : un doublon d'import ne
     * doit pas rester dans le livre. Rien n'est supprimé.
     *
     * @return string message d'erreur, ou '' si tout s'est bien passé
     */
    public static function resoudre(int $userId, int $garderId, int $ecarterId): string
    {
        if ($garderId === $ecarterId) {
            return 'Il faut deux opérations distinctes.';
        }
        $garde  = Affectation::trouver($userId, $garderId);
        $ecarte = Affectation::trouver($userId, $ecarterId);
        if ($garde === null || $ecarte === null) {
            return 'Opération introuvable.';
        }

        if ($ecarte['statut'] === 'affecte') {
            $erreur = match ($ecarte['affecte_type']) {
                'recette' => Recette::annuler($userId, (int) $ecarte['affecte_id'], 'Doublon d\'import'),
                'depense' => Depense::annuler($userId, (int) $ecarte['affecte_id'], 'Doublon d\'import'),
                default   => 'Cette opération a donné une immobilisation : l\'annuler d\'abord dans le registre des immobilisations.',
            };
            if ($erreur !== '') {
                return $erreur;
            }
            // L'annulation a rendu l'opération à la file ; on l'en retire aussitôt.
        }

        Database::modifier('transactions_brutes', [
            'statut'          => 'ignore',
            'motif_exclusion' => 'doublon',
            'affecte_type'    => null,
            'affecte_id'      => null,
            'traite_le'       => date('Y-m-d H:i:s'),
        ], "id = :i AND user_id = :u AND statut IN ('nouveau','ignore')", ['i' => $ecarterId, 'u' => $userId]);

        return '';
    }
}
