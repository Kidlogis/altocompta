<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Calcul de l'annexe 2035-A à partir des registres.
 *
 * Tout est en trésorerie : seules les opérations effectivement encaissées
 * ou décaissées dans l'exercice entrent dans le calcul. Les dépenses
 * d'usage mixte n'entrent que pour leur quote-part professionnelle.
 */
final class Declaration
{
    /**
     * @return array{
     *   recettes: array<string,array>, depenses: array<string,array>,
     *   total_recettes: float, total_depenses: float, resultat: float,
     *   controles: array<int,array>
     * }
     */
    public static function calculer(int $userId, int $exerciceId): array
    {
        $p = ['u' => $userId, 'e' => $exerciceId];

        $recettes = [];
        foreach (Database::tous(
            'SELECT r.poste_2035 AS code, p.libelle, SUM(' . Tva::exprRecette($userId, 'r.') . ') AS total, COUNT(*) AS nb
               FROM recettes r JOIN postes_2035 p ON p.code = r.poste_2035
              WHERE r.user_id = :u AND r.exercice_id = :e AND r.statut = "active"
              GROUP BY r.poste_2035, p.libelle ORDER BY p.ordre', $p) as $l) {
            $recettes[$l['code']] = ['libelle' => $l['libelle'], 'total' => (float) $l['total'], 'nb' => (int) $l['nb']];
        }

        $depenses = [];
        foreach (Database::tous(
            'SELECT d.poste_2035 AS code, p.libelle,
                    SUM(' . Tva::exprDepense($userId, 'd.') . ' * d.quote_part_pro / 100) AS total,
                    SUM(' . Tva::exprDepense($userId, 'd.') . ') AS brut, COUNT(*) AS nb
               FROM depenses d JOIN postes_2035 p ON p.code = d.poste_2035
              WHERE d.user_id = :u AND d.exercice_id = :e AND d.statut = "active"
              GROUP BY d.poste_2035, p.libelle ORDER BY p.ordre', $p) as $l) {
            $depenses[$l['code']] = [
                'libelle' => $l['libelle'],
                'total'   => round((float) $l['total'], 2),
                'brut'    => (float) $l['brut'],
                'nb'      => (int) $l['nb'],
            ];
        }

        $m = static fn(string $code): float => $recettes[$code]['total'] ?? 0.0;

        // Case AF = AA − AC − AD : les débours payés pour le compte des clients
        // et les honoraires rétrocédés ne sont pas des recettes propres.
        $netAF = round($m('AA') - $m('AC') - $m('AD'), 2);

        // Les produits financiers (AG) et gains divers (AH) s'ajoutent ensuite.
        $totalRecettes = round($netAF + $m('AG') + $m('AH'), 2);

        $exercice = Exercice::trouver($userId, $exerciceId);
        $annee    = (int) ($exercice['annee'] ?? date('Y'));
        $bareme   = (int) ($exercice['option_bareme_km'] ?? 0) === 1;

        // Option barème : l'indemnité forfaitaire couvre carburant, entretien,
        // assurance et dépréciation. Les frais réels de véhicule saisis en BR
        // ne sont donc pas déductibles cette année-là, et l'amortissement des
        // véhicules concernés non plus.
        $indemnites = $bareme
            ? Vehicule::indemnites($userId, $exerciceId, $annee)
            : ['lignes' => [], 'total' => 0.0, 'sans_bareme' => 0];

        $brEcarte = 0.0;
        if ($bareme && isset($depenses['BR'])) {
            $brEcarte = $depenses['BR']['total'];
            unset($depenses['BR']);
        }
        if ($bareme && $indemnites['total'] > 0) {
            $depenses['BR'] = [
                'libelle' => 'Frais de véhicules — barème kilométrique',
                'total'   => $indemnites['total'],
                'brut'    => $indemnites['total'],
                'nb'      => count($indemnites['lignes']),
            ];
        }

        // Les postes calculés par une extension — la dotation aux amortissements
        // en case CH, par exemple — arrivent par le point d'extension. Le cœur
        // n'a pas à connaître les immobilisations pour que la case existe.
        $apports  = Plugin::contribuerDeclaration($userId, $exerciceId, $annee);
        $dotation = 0.0;
        foreach ($apports['depenses'] as $code => $poste) {
            $depenses[$code] = $poste;
            if ($code === 'CH') {
                $dotation = (float) $poste['total'];
            }
        }

        // Régularisations : charges à payer et produits à recevoir de l'exercice,
        // moins la contre-passation de ceux de l'exercice précédent.
        $regul = Regularisation::impact($userId, $exerciceId, $annee);

        foreach ($regul['depenses'] as $code => $montant) {
            if (abs($montant) < 0.005) {
                continue;
            }
            $depenses[$code] = [
                'libelle' => ($depenses[$code]['libelle'] ?? Poste2035::libelle($code)),
                'total'   => round(($depenses[$code]['total'] ?? 0) + $montant, 2),
                'brut'    => round(($depenses[$code]['brut'] ?? 0) + $montant, 2),
                'nb'      => ($depenses[$code]['nb'] ?? 0),
                'regul'   => $montant,
            ];
        }
        foreach ($regul['recettes'] as $code => $montant) {
            if (abs($montant) < 0.005) {
                continue;
            }
            $recettes[$code] = [
                'libelle' => ($recettes[$code]['libelle'] ?? Poste2035::libelle($code)),
                'total'   => round(($recettes[$code]['total'] ?? 0) + $montant, 2),
                'nb'      => ($recettes[$code]['nb'] ?? 0),
                'regul'   => $montant,
            ];
        }

        // Le net des recettes se recalcule après régularisation.
        $m     = static fn(string $code): float => $recettes[$code]['total'] ?? 0.0;
        $netAF = round($m('AA') - $m('AC') - $m('AD'), 2);
        $totalRecettes = round($netAF + $m('AG') + $m('AH'), 2);

        $totalDepenses = round(array_sum(array_column($depenses, 'total')), 2);
        $resultat      = round($totalRecettes - $totalDepenses, 2);

        return [
            'recettes'       => $recettes,
            'depenses'       => $depenses,
            'net_af'         => $netAF,
            'dotation'       => $dotation,
            'option_bareme'  => $bareme,
            'regularisations'=> $regul,
            'apports'        => $apports,
            'annexes'        => $apports['annexes'],
            'indemnites'     => $indemnites,
            'br_ecarte'      => round($brEcarte, 2),
            'total_recettes' => $totalRecettes,
            'total_depenses' => $totalDepenses,
            'resultat'       => $resultat,
            'hors_taxes'     => Tva::actif($userId),
            'controles'      => self::controles($userId, $exerciceId),
        ];
    }

    /** Montant à partir duquel une dépense peut devoir s'immobiliser plutôt que passer en charge. */
    public const SEUIL_IMMOBILISATION = 500.0;

    /**
     * Postes où un bien durable peut se cacher : achats, petit outillage, véhicules,
     * fournitures. Un loyer, une prime d'assurance ou des honoraires de 800 € ne sont pas
     * des immobilisations : les compter noierait l'avertissement sous de fausses alertes.
     */
    public const POSTES_IMMOBILISABLES = ['BA', 'BK', 'BR', 'BY'];

    /** Dépenses actives assez importantes, sur un poste susceptible de porter un bien durable. */
    public static function depensesProchesImmobilisation(int $userId, int $exerciceId): int
    {
        $postes = "'" . implode("','", self::POSTES_IMMOBILISABLES) . "'";
        return (int) Database::valeur(
            'SELECT COUNT(*) FROM depenses
              WHERE user_id = :u AND exercice_id = :e AND statut = \'active\'
                AND est_remboursement = 0 AND ' . Tva::exprDepense($userId) . " >= :s AND poste_2035 IN ($postes)",
            ['u' => $userId, 'e' => $exerciceId, 's' => self::SEUIL_IMMOBILISATION], 0
        );
    }

    /**
     * Contrôle « dépenses à immobiliser ». Le même texte sert au cœur (extension absente)
     * et à l'extension Immobilisations (présente), seul le message d'action change.
     *
     * @return ?array contrôle à afficher, ou null s'il n'y a rien à signaler
     */
    public static function controleImmobilisation(int $nb, bool $extensionActive): ?array
    {
        if ($nb <= 0) {
            return null;
        }
        $texte = 'dépense' . ($nb > 1 ? 's' : '') . ' de ' . (int) self::SEUIL_IMMOBILISATION . ' € ou plus';
        if ($extensionActive) {
            return ['niveau' => 'attention', 'nb' => $nb, 'texte' => $texte,
                'detail' => 'Un bien durable au-delà de ce seuil s\'immobilise et s\'amortit, au lieu de passer en charge. À vérifier.',
                'lien'   => '/depenses'];
        }
        return ['niveau' => 'attention', 'nb' => $nb, 'texte' => $texte . ' sans registre des immobilisations',
            'detail' => 'Un bien durable au-delà de ce seuil s\'immobilise et s\'amortit. Sans l\'extension Immobilisations, '
                      . 'aucun amortissement n\'est calculé : un matériel passé en charge surévalue le résultat. '
                      . 'À vérifier, et à installer si de tels biens existent.',
            'lien'   => '/extensions'];
    }

    /** Points à vérifier avant de déclarer. */
    public static function controles(int $userId, int $exerciceId): array
    {
        $c = [];

        $enAttente = Affectation::nbEnAttente($userId, $exerciceId);
        if ($enAttente > 0) {
            $c[] = ['niveau' => 'attention', 'nb' => $enAttente,
                'texte' => 'opération' . ($enAttente > 1 ? 's' : '') . ' bancaire' . ($enAttente > 1 ? 's' : '') . ' non affectée' . ($enAttente > 1 ? 's' : ''),
                'detail' => 'Elles n\'entrent ni dans les recettes ni dans les dépenses : le résultat est provisoire.',
                'lien' => '/affectation'];
        }

        // Assujetti : une écriture dont le HT / TVA n'est pas éclaté compte pour son TTC, ce qui fausse le résultat.
        $av = Tva::nbAVentiler($userId, $exerciceId);
        if ($av['recettes'] + $av['depenses'] > 0) {
            $n = $av['recettes'] + $av['depenses'];
            $c[] = ['niveau' => 'attention', 'nb' => $n,
                'texte' => 'écriture' . ($n > 1 ? 's' : '') . ' à éclater en HT et TVA (' . $av['recettes'] . ' recette' . ($av['recettes'] > 1 ? 's' : '')
                         . ', ' . $av['depenses'] . ' dépense' . ($av['depenses'] > 1 ? 's' : '') . ')',
                'detail' => 'Assujetti à la TVA, vous déclarez hors taxes. Tant qu\'une écriture n\'est pas éclatée, elle compte pour son TTC : '
                          . 'le résultat est surévalué pour les recettes et les dépenses non éclatées. Ouvrir la fiche de l\'écriture pour saisir le HT et la TVA.',
                'lien' => $av['depenses'] >= $av['recettes'] ? '/depenses?a_ventiler=1' : '/recettes?a_ventiler=1'];
        }

        $sansJustif = Depense::nbSansJustificatif($userId, $exerciceId);
        if ($sansJustif > 0) {
            $c[] = ['niveau' => 'attention', 'nb' => $sansJustif,
                'texte' => 'dépense' . ($sansJustif > 1 ? 's' : '') . ' sans justificatif',
                'detail' => 'Non transmis avec la déclaration, mais à produire en cas de contrôle.',
                'lien' => '/depenses?sans_justif=1'];
        }

        // Une écriture hors des bornes de l'exercice fausse le rattachement.
        // PDO en préparation native n'autorise pas qu'un même paramètre nommé
        // apparaisse deux fois : chaque sous-requête a donc les siens.
        $horsBornes = (int) Database::valeur(
            'SELECT (SELECT COUNT(*) FROM recettes r JOIN exercices x ON x.id = r.exercice_id
                      WHERE r.user_id = :u1 AND r.exercice_id = :e1
                        AND r.date_encaissement NOT BETWEEN x.date_debut AND x.date_fin)
                  + (SELECT COUNT(*) FROM depenses d JOIN exercices x2 ON x2.id = d.exercice_id
                      WHERE d.user_id = :u2 AND d.exercice_id = :e2
                        AND d.date_decaissement NOT BETWEEN x2.date_debut AND x2.date_fin)',
            ['u1' => $userId, 'e1' => $exerciceId, 'u2' => $userId, 'e2' => $exerciceId], 0
        );
        if ($horsBornes > 0) {
            $c[] = ['niveau' => 'erreur', 'nb' => $horsBornes,
                'texte' => 'écriture' . ($horsBornes > 1 ? 's' : '') . ' hors des dates de l\'exercice',
                'detail' => 'À rattacher au bon exercice avant de déclarer.',
                'lien' => null];
        }

        $exercice = Exercice::trouver($userId, $exerciceId);
        $annee    = (int) ($exercice['annee'] ?? date('Y'));

        if ((int) ($exercice['option_bareme_km'] ?? 0) === 1) {
            if (!BaremeKm::existe($annee)) {
                $c[] = ['niveau' => 'erreur', 'nb' => null,
                    'texte' => 'Aucun barème kilométrique saisi pour ' . $annee,
                    'detail' => 'Les coefficients changent chaque année par arrêté : les reporter avant de déclarer.',
                    'lien' => '/vehicules/bareme'];
            }

            $brReels = (float) Database::valeur(
                'SELECT COALESCE(SUM(' . Tva::exprDepense($userId) . " * quote_part_pro / 100),0) FROM depenses
                  WHERE user_id = :u AND exercice_id = :e AND poste_2035 = 'BR' AND statut = 'active'",
                ['u' => $userId, 'e' => $exerciceId], 0
            );
            if ($brReels > 0) {
                $c[] = ['niveau' => 'attention', 'nb' => null,
                    'texte' => 'Frais de véhicule réels écartés du calcul',
                    'detail' => 'L\'option pour le barème rend ces dépenses non déductibles : '
                              . number_format($brReels, 2, ',', ' ') . ' € sont ignorés.',
                    'lien' => '/depenses?poste=BR'];
            }
        }

        $nbRegul = Regularisation::nb($userId, $exerciceId);
        if ($nbRegul > 0) {
            $c[] = ['niveau' => 'attention', 'nb' => $nbRegul,
                'texte'  => 'régularisation' . ($nbRegul > 1 ? 's' : '') . ' de fin d\'exercice',
                'detail' => 'Ces écritures dérogent au principe de trésorerie. Leur admissibilité '
                          . 'dépend du régime applicable : à valider avec l\'AGA ou l\'expert-comptable.',
                'lien'   => '/regularisations'];
        }

        // Une contre-passation attendue mais absente laisserait une charge déduite deux fois.
        $impact = Regularisation::impact($userId, $exerciceId, $annee);
        $contre = array_filter($impact['lignes'], static fn($l) => $l['origine'] === 'contrepassation');
        if ($contre) {
            $c[] = ['niveau' => 'ok', 'nb' => count($contre),
                'texte'  => 'contre-passation' . (count($contre) > 1 ? 's' : '') . ' de l\'exercice précédent',
                'detail' => 'Les charges déduites l\'an dernier sans être payées sont neutralisées ici, '
                          . 'pour qu\'elles ne soient pas déduites une seconde fois à leur paiement.',
                'lien'   => '/regularisations'];
        }

        // Sans l'extension, rien ne signale un bien durable passé en charge : le cœur le fait.
        // Avec elle, c'est elle qui contrôle (même règle), pour ne pas afficher deux fois la même alerte.
        if (!Plugin::estActif('immobilisations')) {
            $ct = self::controleImmobilisation(self::depensesProchesImmobilisation($userId, $exerciceId), false);
            if ($ct !== null) {
                $c[] = $ct;
            }
        }

        foreach (Plugin::contribuerDeclaration($userId, $exerciceId, $annee)['controles'] as $ct) {
            $c[] = $ct;
        }

        if (!$c) {
            $c[] = ['niveau' => 'ok', 'nb' => null,
                'texte' => 'Registres cohérents',
                'detail' => 'Écritures datées de l\'encaissement ou du décaissement effectif, toutes dans les bornes de l\'exercice.',
                'lien' => null];
        }
        return $c;
    }

    /** Lignes du formulaire, dans l'ordre officiel, y compris les totaux. */
    public static function lignes(array $calc): array
    {
        $l = [];
        $l[] = ['type' => 'bandeau', 'libelle' => 'Recettes'];

        // AA, AC et AD viennent avant le net AF ; AG et AH s'ajoutent apres.
        foreach (['AA', 'AC', 'AD'] as $code) {
            $l[] = ['type' => 'ligne', 'code' => $code, 'libelle' => Poste2035::libelle($code),
                    'montant' => $calc['recettes'][$code]['total'] ?? null];
        }
        $l[] = ['type' => 'total', 'code' => 'AF', 'libelle' => 'Montant net des recettes',
                'montant' => $calc['net_af']];

        foreach (['AG', 'AH'] as $code) {
            $m = $calc['recettes'][$code]['total'] ?? null;
            if ($m !== null) {
                $l[] = ['type' => 'ligne', 'code' => $code, 'libelle' => Poste2035::libelle($code), 'montant' => $m];
            }
        }

        $l[] = ['type' => 'bandeau', 'libelle' => 'Dépenses professionnelles'];
        foreach (Poste2035::saisissables('depense') as $p) {
            $m = $calc['depenses'][$p['code']]['total'] ?? null;
            // Les postes non utilisés alourdissent le formulaire : on ne garde
            // que ceux qui portent un montant.
            if ($m === null) {
                continue;
            }
            $l[] = ['type' => 'ligne', 'code' => $p['code'], 'libelle' => $p['libelle'], 'montant' => $m];
        }

        // CH n'est pas saisissable : elle est calculée sur les plans d'amortissement.
        if (($calc['dotation'] ?? 0) > 0) {
            $l[] = ['type' => 'ligne', 'code' => 'CH',
                    'libelle' => Poste2035::libelle('CH'), 'montant' => $calc['dotation']];
        }
        $l[] = ['type' => 'total', 'code' => 'CE', 'libelle' => 'Total des dépenses',
                'montant' => $calc['total_depenses']];

        $l[] = ['type' => 'resultat',
                'code' => $calc['resultat'] >= 0 ? 'CP' : 'CR',
                'libelle' => $calc['resultat'] >= 0 ? 'Excédent' : 'Insuffisance',
                'montant' => abs($calc['resultat'])];
        return $l;
    }
}
