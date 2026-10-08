<?php
declare(strict_types=1);

namespace Bnc;

/**
 * TVA : éclatement HT / TVA d'une écriture, pour les assujettis.
 *
 * Le principe qui commande tout : `montant` est TOUJOURS le TTC, celui du virement. L'affectation, les doublons,
 * les virements internes, les règles et les connecteurs bancaires ne connaissent que lui, et ne changent pas.
 * L'éclatement se fait ensuite, sur la fiche de l'écriture, dans trois colonnes à côté (montant_ht, montant_tva,
 * taux_tva) et, pour une dépense, une quatrième (tva_non_recuperable).
 *
 * Pour un assujetti, le résultat de la 2035 se calcule hors taxes. Une écriture pas encore éclatée compte donc
 * pour son TTC — faute de mieux — et la déclaration le signale (« à ventiler ») tant qu'il en reste.
 *
 * Tout ce qui suit calcule en CENTIMES entiers : des flottants additionneraient des écarts d'un centime que
 * l'administration, elle, ne tolère pas.
 */
final class Tva
{
    /** Taux métropolitains courants. Un autre taux (DOM, 8,5 %) se saisit à la main. */
    public const TAUX = [20.0, 10.0, 5.5, 2.1, 0.0];

    public const CHAMPS_RECETTE = ['montant_ht', 'montant_tva', 'taux_tva'];
    public const CHAMPS_DEPENSE = ['montant_ht', 'montant_tva', 'taux_tva', 'tva_non_recuperable'];

    /** @var array<int,bool> */
    private static array $cache = [];

    /** L'utilisateur est-il assujetti à la TVA ? Sinon, rien de tout ceci n'apparaît. */
    public static function actif(int $userId): bool
    {
        return self::$cache[$userId] ??= (Database::valeur('SELECT tva_regime FROM users WHERE id = :u', ['u' => $userId]) === 'assujetti');
    }

    public static function oublier(): void
    {
        self::$cache = [];
    }

    // --- Ce qui entre dans le résultat -------------------------------------------

    /**
     * Expression SQL du montant d'une recette retenu pour le résultat : le HT s'il est éclaté, le TTC sinon,
     * et toujours le TTC pour qui n'est pas assujetti.
     */
    public static function exprRecette(int $userId, string $alias = ''): string
    {
        return self::actif($userId) ? "COALESCE({$alias}montant_ht, {$alias}montant)" : "{$alias}montant";
    }

    /**
     * Idem pour une dépense : le HT plus la TVA non récupérable (qui reste une charge), ou le TTC si l'écriture
     * n'est pas éclatée. La quote-part professionnelle s'applique ensuite à ce montant.
     */
    public static function exprDepense(int $userId, string $alias = ''): string
    {
        return self::actif($userId)
            ? "COALESCE({$alias}montant_ht + COALESCE({$alias}tva_non_recuperable, 0), {$alias}montant)"
            : "{$alias}montant";
    }

    /** Le même calcul, sur une ligne déjà lue (registres, exports, PDF). */
    public static function retenu(array $ligne, bool $depense, bool $actif): float
    {
        if (!$actif || ($ligne['montant_ht'] ?? null) === null) {
            return (float) $ligne['montant'];
        }
        return round((float) $ligne['montant_ht'] + ($depense ? (float) ($ligne['tva_non_recuperable'] ?? 0) : 0.0), 2);
    }

    /** TVA déductible d'une dépense : la TVA de la facture, moins la part non récupérable, au prorata professionnel. */
    public static function deductible(array $ligne): float
    {
        if (($ligne['montant_tva'] ?? null) === null) {
            return 0.0;
        }
        return round(((float) $ligne['montant_tva'] - (float) ($ligne['tva_non_recuperable'] ?? 0)) * (int) ($ligne['quote_part_pro'] ?? 100) / 100, 2);
    }

    // --- Lecture et validation de l'éclatement ----------------------------------------

    /**
     * Lit l'éclatement saisi sur la fiche d'une écriture.
     *
     * On peut donner le HT, la TVA, ou seulement le taux : le reste se déduit du TTC, qui ne bouge jamais. Donner
     * HT et TVA ensemble exige qu'ils totalisent le TTC au centime. Rien n'est « arrangé » en silence.
     *
     * @param ?float $ttc montant de l'écriture ; null si lui-même invalide (l'erreur est signalée ailleurs)
     * @return array{0:array<string,?string>, 1:list<string>} champs normalisés (null = pas d'éclatement), erreurs
     */
    public static function lire(array $in, ?float $ttc, bool $depense): array
    {
        $vide = array_fill_keys($depense ? self::CHAMPS_DEPENSE : self::CHAMPS_RECETTE, null);
        $brut = static fn(string $k): string => trim((string) ($in[$k] ?? ''));
        [$sHt, $sTva, $sTaux, $sNr] = [$brut('montant_ht'), $brut('montant_tva'), $brut('taux_tva'), $depense ? $brut('tva_non_recuperable') : ''];

        if ($sHt === '' && $sTva === '' && $sTaux === '') {
            return [$vide, $sNr !== '' && $sNr !== '0' ? ['Une TVA non récupérable suppose que la TVA soit renseignée.'] : []];
        }
        if ($ttc === null) {
            return [$vide, []];
        }

        $err = [];
        $cts = static fn(float $v): int => (int) round($v * 100);
        $ttcC = $cts($ttc);

        $taux = null;
        if ($sTaux !== '') {
            $t = Montant::lire($sTaux);
            if ($t === null || $t < 0 || $t > 100) {
                $err[] = 'Le taux de TVA doit être un nombre de 0 à 100.';
            } else {
                $taux = round($t, 2);
            }
        }
        $htC = $tvaC = null;
        if ($sHt !== '') {
            $v = Montant::lire($sHt);
            $v === null ? $err[] = 'Le montant HT doit être un nombre.' : $htC = $cts($v);
        }
        if ($sTva !== '') {
            $v = Montant::lire($sTva);
            $v === null ? $err[] = 'Le montant de TVA doit être un nombre.' : $tvaC = $cts($v);
        }
        if ($err) {
            return [$vide, $err];
        }

        if ($htC === null && $tvaC === null) {                 // seulement le taux
            $htC  = (int) round($ttcC / (1 + $taux / 100));
            $tvaC = $ttcC - $htC;
        } elseif ($tvaC === null) {                            // le HT seul
            $tvaC = $ttcC - $htC;
        } elseif ($htC === null) {                             // la TVA seule
            $htC = $ttcC - $tvaC;
        } elseif ($htC + $tvaC !== $ttcC) {
            return [$vide, ['HT + TVA doit égaler le montant TTC (' . self::euros($ttcC) . ') : ' . self::euros($htC) . ' + '
                . self::euros($tvaC) . ' = ' . self::euros($htC + $tvaC) . '.']];
        }

        // Même signe que l'écriture : un avoir ou un remboursement porte un HT et une TVA négatifs.
        foreach (['HT' => $htC, 'TVA' => $tvaC] as $nom => $v) {
            if ($v * $ttcC < 0) {
                $err[] = "Le montant $nom doit avoir le signe du montant TTC (négatif pour un avoir ou un remboursement).";
            }
        }
        if (abs($tvaC) > abs($ttcC)) {
            $err[] = 'La TVA ne peut pas dépasser le montant TTC.';
        }

        // Le taux, quand on le donne avec des montants : ils doivent s'accorder, à quelques centimes d'arrondi près.
        if ($taux !== null && ($sHt !== '' || $sTva !== '') && !$err) {
            $attendu = (int) round($htC * $taux / 100);
            if (abs($tvaC - $attendu) > 5 + (int) round(abs($tvaC) * 0.01)) {
                $err[] = 'La TVA (' . self::euros($tvaC) . ') ne correspond pas à ' . rtrim(rtrim(number_format($taux, 2, ',', ''), '0'), ',')
                    . ' % de ' . self::euros($htC) . ' (attendu ≈ ' . self::euros($attendu) . '). Laissez le taux vide si la facture mêle plusieurs taux.';
            }
        }

        $out = ['montant_ht' => self::dec($htC), 'montant_tva' => self::dec($tvaC), 'taux_tva' => $taux === null ? null : number_format($taux, 2, '.', '')];
        if ($depense) {
            $out['tva_non_recuperable'] = null;
            if ($sNr !== '') {
                $v = Montant::lire($sNr);
                if ($v === null) {
                    $err[] = 'La TVA non récupérable doit être un nombre.';
                } else {
                    $nrC = $cts($v);
                    if ($nrC * $tvaC < 0 || abs($nrC) > abs($tvaC)) {
                        $err[] = 'La TVA non récupérable ne peut ni changer de signe ni dépasser la TVA de la facture (' . self::euros($tvaC) . ').';
                    } elseif ($nrC !== 0) {
                        $out['tva_non_recuperable'] = self::dec($nrC);
                    }
                }
            }
        }
        return [$err ? $vide : $out, $err];
    }

    /** HT et TVA d'un TTC et d'un taux, pour que HT + TVA égale exactement le TTC. @return array{0:string,1:string} */
    public static function depuisTaux(float $ttc, float $taux): array
    {
        $ttcC = (int) round($ttc * 100);
        $htC = (int) round($ttcC / (1 + $taux / 100));
        return [self::dec($htC), self::dec($ttcC - $htC)];
    }

    // --- Écritures d'annulation et rectifications ---------------------------------

    /**
     * L'écriture d'annulation porte l'éclatement OPPOSÉ, comme elle porte le montant opposé : la somme des lignes
     * d'un registre reste nulle, en HT comme en TTC. Le taux, lui, ne change pas de signe.
     *
     * @return array<string,?string>
     */
    public static function oppose(array $origine, array $champs): array
    {
        $sortie = [];
        foreach ($champs as $c) {
            if (!array_key_exists($c, $origine)) {
                continue;   // base pas encore migrée : l'écriture n'a pas ces colonnes
            }
            $v = $origine[$c];
            $sortie[$c] = $v === null ? null : ($c === 'taux_tva' ? (string) $v : self::dec((int) round(-1 * (float) $v * 100)));
        }
        return $sortie;
    }

    /**
     * Une rectification qui ne mentionne pas l'éclatement (une extension qui reconstruit l'écriture à la main, par
     * exemple) ne doit pas le PERDRE : on le reprend de l'originale — mais seulement si le TTC n'a pas bougé. Si
     * le TTC change sans nouvel éclatement, l'ancien ne serait plus juste : il repasse à « à ventiler ».
     *
     * @param list<string> $champs
     */
    public static function conserver(array $d, array $origine, array $champs): array
    {
        $meme = isset($d['montant']) && abs((float) $d['montant'] - (float) $origine['montant']) < 0.005;
        foreach ($champs as $c) {
            if (!array_key_exists($c, $d)) {
                $d[$c] = $meme ? ($origine[$c] ?? null) : null;
            }
        }
        return $d;
    }

    /** Instantané pour le journal : seulement si l'écriture est éclatée. @return array<string,string> */
    public static function instantane(array $e, array $champs): array
    {
        $sortie = [];
        foreach ($champs as $c) {
            if (($e[$c] ?? null) !== null) {
                $sortie[$c] = (string) $e[$c];
            }
        }
        return $sortie;
    }

    // --- Ce qu'il reste à faire --------------------------------------------------

    /** @return array{recettes:int, depenses:int} écritures actives pas encore éclatées ; 0 si l'on n'est pas assujetti */
    public static function nbAVentiler(int $userId, int $exerciceId): array
    {
        if (!self::actif($userId)) {
            return ['recettes' => 0, 'depenses' => 0];
        }
        $n = static fn(string $t): int => (int) Database::valeur(
            "SELECT COUNT(*) FROM $t WHERE user_id = :u AND exercice_id = :e AND statut = 'active' AND montant_ht IS NULL",
            ['u' => $userId, 'e' => $exerciceId], 0
        );
        return ['recettes' => $n('recettes'), 'depenses' => $n('depenses')];
    }

    /**
     * Totaux des écritures actives, en TTC, en HT retenu et en TVA — pour les registres et les exports.
     *
     * @return array{ttc:float, ht:float, tva:float}
     */
    public static function totaux(int $userId, int $exerciceId, bool $depense): array
    {
        $table = $depense ? 'depenses' : 'recettes';
        $ht = $depense ? self::exprDepense($userId) : self::exprRecette($userId);
        $l = Database::un(
            "SELECT COALESCE(SUM(montant),0) AS ttc, COALESCE(SUM($ht),0) AS ht, COALESCE(SUM(COALESCE(montant_tva,0)),0) AS tva
               FROM $table WHERE user_id = :u AND exercice_id = :e AND statut = 'active'",
            ['u' => $userId, 'e' => $exerciceId]
        ) ?? ['ttc' => 0, 'ht' => 0, 'tva' => 0];
        return ['ttc' => round((float) $l['ttc'], 2), 'ht' => round((float) $l['ht'], 2), 'tva' => round((float) $l['tva'], 2)];
    }

    // --- Détails ---------------------------------------------------------------

    /** Centimes → « 12.34 », pour la base. */
    private static function dec(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }

    /** Centimes → « 12,34 € », pour un message. */
    private static function euros(int $centimes): string
    {
        return number_format($centimes / 100, 2, ',', ' ') . ' €';
    }
}
