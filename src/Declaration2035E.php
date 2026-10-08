<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Annexe 2035-E — détermination de la valeur ajoutée produite.
 *
 * Cette annexe sert au calcul de la CVAE et n'est exigée qu'au-delà d'un
 * seuil de recettes. En deçà, elle n'a pas à être servie : l'application le
 * signale plutôt que de produire un tableau inutile.
 *
 * La valeur ajoutée vaut les recettes diminuées des consommations en
 * provenance de tiers. En sont exclues les rémunérations et charges sociales,
 * les amortissements et les charges financières, qui ne sont pas des
 * consommations externes.
 *
 * La ventilation ci-dessous est celle retenue par défaut. Les règles
 * d'inclusion évoluent et comportent des cas particuliers : la vérifier
 * avec l'AGA ou l'expert-comptable avant de déclarer.
 */
final class Declaration2035E
{
    /** Seuil de recettes au-delà duquel l'annexe est exigée. */
    public const SEUIL_RECETTES = 152500.0;

    /**
     * Postes de la 2035-A regroupés en consommations en provenance de tiers.
     * @var array<string,array<int,string>>
     */
    public const CONSOMMATIONS = [
        'Achats'                          => ['BA'],
        'Services extérieurs'             => ['BF', 'BG', 'BH', 'BJ', 'BM', 'BN', 'BP', 'BR', 'BV', 'BX', 'BY'],
        'Autres charges de gestion'       => ['BK', 'BZ', 'CA', 'CB'],
    ];

    /** Postes explicitement hors valeur ajoutée, avec la raison. */
    public const EXCLUS = [
        'BB' => 'Rémunérations : charges de personnel, non consommées auprès de tiers',
        'BC' => 'Charges sociales sur salaires',
        'BW' => 'Charges sociales personnelles de l\'exploitant',
        'BD' => 'TVA',
        'BE' => 'Impôts et taxes',
        'BS' => 'CET, exclue par nature du calcul de la valeur ajoutée',
        'CC' => 'Charges financières',
        'CD' => 'Pertes diverses',
        'CH' => 'Dotation aux amortissements',
    ];

    public static function exigee(float $recettes): bool
    {
        return $recettes > self::SEUIL_RECETTES;
    }

    /**
     * @param array $calc résultat de Declaration::calculer()
     * @return array{exigee:bool, recettes:float, groupes:array, total_consommations:float,
     *                valeur_ajoutee:float, exclus:array}
     */
    public static function calculer(array $calc): array
    {
        $recettes = (float) $calc['total_recettes'];

        $groupes = [];
        $total = 0.0;
        foreach (self::CONSOMMATIONS as $libelle => $codes) {
            $lignes = [];
            $sousTotal = 0.0;
            foreach ($codes as $code) {
                $montant = (float) ($calc['depenses'][$code]['total'] ?? 0);
                if (abs($montant) < 0.005) {
                    continue;
                }
                // Le libellé est résolu à l'affichage : le calcul reste ainsi
                // indépendant de la base, testable et réutilisable hors contexte web.
                $lignes[] = ['code' => $code, 'montant' => $montant];
                $sousTotal += $montant;
            }
            $groupes[$libelle] = ['lignes' => $lignes, 'total' => round($sousTotal, 2)];
            $total += $sousTotal;
        }

        $exclus = [];
        foreach (self::EXCLUS as $code => $raison) {
            $montant = (float) ($calc['depenses'][$code]['total'] ?? 0);
            if (abs($montant) >= 0.005) {
                $exclus[] = ['code' => $code, 'raison' => $raison, 'montant' => $montant];
            }
        }

        return [
            'exigee'              => self::exigee($recettes),
            'recettes'            => $recettes,
            'groupes'             => $groupes,
            'total_consommations' => round($total, 2),
            'valeur_ajoutee'      => round($recettes - $total, 2),
            'exclus'              => $exclus,
        ];
    }

    /**
     * Vérifie que chaque poste de dépense est bien classé : un poste ni
     * consommé ni explicitement exclu passerait silencieusement à la trappe.
     *
     * @return array<int,string> codes non classés
     */
    public static function postesNonClasses(array $calc): array
    {
        $connus = array_merge(...array_values(self::CONSOMMATIONS));
        $connus = array_merge($connus, array_keys(self::EXCLUS));

        $manquants = [];
        foreach (array_keys($calc['depenses'] ?? []) as $code) {
            if (!in_array($code, $connus, true)) {
                $manquants[] = $code;
            }
        }
        return $manquants;
    }
}
