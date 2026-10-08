<?php
declare(strict_types=1);

namespace Bnc;

/**
 * La page TVA : ce que l'on doit (ou ce qui nous est dû) à l'État, période par période.
 *
 *   TVA collectée   = la TVA des recettes encaissées dans la période (un avoir la diminue) ;
 *   TVA déductible  = celle des dépenses payées, hors part non récupérable, au prorata professionnel,
 *                     plus celle des acquisitions que les extensions déclarent (les immobilisations) ;
 *   TVA nette       = collectée − déductible ;
 *   crédit reporté  = ce qui n'a pas pu être déduit à la période précédente ;
 *   à payer         = nette − crédit reporté, si positif ; sinon le solde devient un crédit à reporter.
 *
 * Hypothèse, assumée et affichée : la TVA est comptée À L'ENCAISSEMENT et AU PAIEMENT, c'est-à-dire à la date de
 * l'écriture. C'est la règle des prestations de services, qui est celle d'un BNC. Une vente de biens (exigible à la
 * livraison) et l'option pour les débits ne sont pas gérées.
 *
 * Tout se calcule en CENTIMES entiers. Une écriture pas encore éclatée en HT / TVA n'a pas de TVA connue : elle n'est pas
 * comptée, et la page le dit, avec le nombre d'écritures concernées, plutôt que de laisser croire à un résultat complet.
 */
final class TvaPeriodes
{
    public const PERIODICITES = [
        'mensuelle'     => 'Mensuelle',
        'trimestrielle' => 'Trimestrielle',
        'annuelle'      => 'Annuelle',
    ];

    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    // --- Pur : calendrier et enchaînement ---------------------------------------

    /**
     * Découpe un exercice en périodes de déclaration, sur le calendrier civil (mois, trimestres). Un exercice qui ne
     * commence pas au début d'une période — le premier, souvent — a une première période partielle.
     *
     * @return list<array{debut:string, fin:string, libelle:string, partielle:bool}>
     */
    public static function decouper(string $debut, string $fin, string $periodicite): array
    {
        $d = new \DateTimeImmutable($debut);
        $f = new \DateTimeImmutable($fin);
        if ($f < $d) {
            return [];
        }
        if ($periodicite === 'annuelle') {
            return [['debut' => $d->format('Y-m-d'), 'fin' => $f->format('Y-m-d'), 'libelle' => 'Exercice ' . $d->format('Y')
                . ($d->format('Y') !== $f->format('Y') ? '–' . $f->format('Y') : ''), 'partielle' => false]];
        }

        $pas  = $periodicite === 'mensuelle' ? 1 : 3;
        $mois = (int) $d->format('n');
        $depart = $periodicite === 'mensuelle' ? $mois : intdiv($mois - 1, 3) * 3 + 1;
        $cur = $d->setDate((int) $d->format('Y'), $depart, 1);

        $sortie = [];
        while ($cur <= $f) {
            $finPeriode = $cur->modify('+' . $pas . ' months')->modify('-1 day');
            $pd = $cur > $d ? $cur : $d;
            $pf = $finPeriode < $f ? $finPeriode : $f;
            $libelle = $periodicite === 'mensuelle'
                ? ucfirst(self::MOIS[(int) $cur->format('n') - 1]) . ' ' . $cur->format('Y')
                : 'T' . (intdiv((int) $cur->format('n') - 1, 3) + 1) . ' ' . $cur->format('Y');
            $sortie[] = ['debut' => $pd->format('Y-m-d'), 'fin' => $pf->format('Y-m-d'), 'libelle' => $libelle,
                         'partielle' => $pd != $cur || $pf != $finPeriode];
            $cur = $cur->modify('+' . $pas . ' months');
        }
        return $sortie;
    }

    /**
     * Enchaîne le crédit de TVA de période en période : ce qui n'a pas pu être déduit se reporte, ce qui reste à payer
     * après imputation du crédit se paie.
     *
     * @param list<array<string,int|string|bool>> $periodes avec collectee_c, deductible_c, acquisitions_c (centimes)
     * @return array{0:list<array<string,int|string|bool>>, 1:int} périodes complétées, crédit à l'issue de la dernière
     */
    public static function enchainer(array $periodes, int $creditInitialC): array
    {
        $credit = max(0, $creditInitialC);
        foreach ($periodes as $i => $p) {
            $net   = (int) $p['collectee_c'] - (int) $p['deductible_c'] - (int) $p['acquisitions_c'];
            $reste = $net - $credit;
            $p['net_c'] = $net;
            $p['credit_entrant_c'] = $credit;
            if ($reste >= 0) {
                $p['a_payer_c'] = $reste;
                $p['credit_sortant_c'] = 0;
                $credit = 0;
            } else {
                $p['a_payer_c'] = 0;
                $p['credit_sortant_c'] = -$reste;
                $credit = -$reste;
            }
            $periodes[$i] = $p;
        }
        return [$periodes, $credit];
    }

    private static function cts(mixed $v): int
    {
        return (int) round((float) $v * 100);
    }

    // --- Les chiffres de l'exercice ---------------------------------------------

    /**
     * @return array<string,mixed> vide si l'exercice n'existe pas ; sinon voir les clés ci-dessous (montants en centimes)
     */
    public static function calculer(int $userId, int $exerciceId): array
    {
        $ex = Exercice::trouver($userId, $exerciceId);
        if ($ex === null) {
            return [];
        }
        $periodicite = Database::valeur('SELECT tva_periodicite FROM users WHERE id = :u', ['u' => $userId]);
        $periodicite = is_string($periodicite) && isset(self::PERIODICITES[$periodicite]) ? $periodicite : null;

        // Sans périodicité choisie, on totalise l'exercice d'un bloc : c'est utile, et l'on invite à choisir.
        $periodes = array_map(
            static fn(array $p) => $p + ['collectee_c' => 0, 'deductible_c' => 0, 'acquisitions_c' => 0, 'ca_ht_c' => 0, 'nb_ecritures' => 0, 'nb_a_eclater' => 0],
            self::decouper((string) $ex['date_debut'], (string) $ex['date_fin'], $periodicite ?? 'annuelle')
        );
        $trouver = static function (string $date) use (&$periodes): ?int {
            foreach ($periodes as $i => $p) {
                if ($date >= $p['debut'] && $date <= $p['fin']) {
                    return $i;
                }
            }
            return null;
        };

        $aEclater = ['recettes' => 0, 'depenses' => 0];
        $horsPeriode = 0;
        $p = ['u' => $userId, 'e' => $exerciceId];

        foreach (Database::tous(
            "SELECT date_encaissement AS d, montant_ht, montant_tva FROM recettes WHERE user_id = :u AND exercice_id = :e AND statut = 'active'", $p
        ) as $l) {
            $i = $trouver((string) $l['d']);
            if ($i === null) { $horsPeriode++; continue; }
            $periodes[$i]['nb_ecritures']++;
            if ($l['montant_tva'] === null) {
                $periodes[$i]['nb_a_eclater']++;
                $aEclater['recettes']++;
                continue;
            }
            $periodes[$i]['collectee_c'] += self::cts($l['montant_tva']);
            $periodes[$i]['ca_ht_c'] += self::cts($l['montant_ht']);
        }

        foreach (Database::tous(
            "SELECT date_decaissement AS d, montant_ht, montant_tva, tva_non_recuperable, quote_part_pro
               FROM depenses WHERE user_id = :u AND exercice_id = :e AND statut = 'active'", $p
        ) as $l) {
            $i = $trouver((string) $l['d']);
            if ($i === null) { $horsPeriode++; continue; }
            $periodes[$i]['nb_ecritures']++;
            if ($l['montant_tva'] === null) {
                $periodes[$i]['nb_a_eclater']++;
                $aEclater['depenses']++;
                continue;
            }
            $periodes[$i]['deductible_c'] += self::cts(Tva::deductible($l));
        }

        // Acquisitions : la TVA d'un matériel n'est dans aucune dépense (il est immobilisé), l'extension la déclare.
        $acquisitions = Plugin::contribuerTva($userId, $exerciceId, $ex)['acquisitions'];
        foreach ($acquisitions as $a) {
            if (($a['etat'] ?? '') !== 'comptee') {
                continue;
            }
            $i = $trouver((string) $a['date']);
            if ($i === null) { $horsPeriode++; continue; }
            $periodes[$i]['acquisitions_c'] += self::cts($a['tva']);
        }

        $creditInitialC = self::cts($ex['tva_credit_initial'] ?? 0);
        [$periodes, $creditFinalC] = self::enchainer($periodes, $creditInitialC);

        $tot = ['collectee_c' => 0, 'deductible_c' => 0, 'acquisitions_c' => 0, 'net_c' => 0, 'a_payer_c' => 0, 'ca_ht_c' => 0];
        foreach ($periodes as $per) {
            foreach ($tot as $k => $_) {
                $tot[$k] += (int) $per[$k];
            }
        }

        // Paiements de TVA déjà repérés : les opérations bancaires écartées du résultat avec le motif « TVA ».
        $pay = Database::tous(
            "SELECT date_operation AS d, libelle, montant FROM transactions_brutes
              WHERE user_id = :u AND exercice_id = :e AND statut = 'ignore' AND motif_exclusion = 'tva' ORDER BY date_operation, id", $p
        );
        $verse = $rembourse = 0;
        foreach ($pay as $l) {
            $c = self::cts($l['montant']);
            $c < 0 ? $verse += -$c : $rembourse += $c;
        }

        // Une extension qui gère des acquisitions mais ne sait pas encore les déclarer à cette page : sa TVA manquerait en
        // silence, et le résultat serait trop élevé. On le dit plutôt que de laisser croire que le total est complet.
        $avertissements = [];
        if (Plugin::estActif('immobilisations') && !Plugin::contribueTva('immobilisations')) {
            $avertissements[] = 'L\'extension Immobilisations installée ne déclare pas encore la TVA de vos acquisitions à cette page (versions antérieures à 1.0.2) : '
                . 'elle n\'est pas comptée, et la TVA à payer est surévaluée du montant de la TVA récupérable sur vos achats de matériel. Mettez l\'extension à jour (Extensions › Importer).';
        }

        $sansTva = count(array_filter($acquisitions, static fn(array $a) => ($a['etat'] ?? '') === 'a_renseigner'));
        if ($sansTva > 0) {
            $avertissements[] = $sansTva . ' acquisition' . ($sansTva > 1 ? 's' : '') . ' (biens immobilisés) sans TVA renseignée : '
                . ($sansTva > 1 ? 'leur TVA n\'est pas comptée' : 'sa TVA n\'est pas comptée') . '. Ouvrir le bien (Immobilisations) pour saisir le HT et la TVA, comme pour une dépense.';
        }

        return [
            'avertissements' => $avertissements,
            'exercice'       => $ex,
            'periodicite'    => $periodicite,
            'periodes'       => $periodes,
            'totaux'         => $tot,
            'credit_initial_c' => $creditInitialC,
            'credit_final_c'   => $creditFinalC,
            'a_eclater'      => $aEclater,
            'acquisitions'   => $acquisitions,
            'paiements'      => ['lignes' => $pay, 'verse_c' => $verse, 'rembourse_c' => $rembourse],
            'hors_periode'   => $horsPeriode,
        ];
    }

    /** Le crédit de TVA à l'issue de l'exercice précédent, s'il existe dans l'application. @return ?array{annee:int, credit_c:int} */
    public static function creditPrecedent(int $userId, int $exerciceId): ?array
    {
        $ex = Exercice::trouver($userId, $exerciceId);
        if ($ex === null) {
            return null;
        }
        $prec = Database::un('SELECT id, annee FROM exercices WHERE user_id = :u AND annee = :a', ['u' => $userId, 'a' => (int) $ex['annee'] - 1]);
        if ($prec === null) {
            return null;
        }
        $c = self::calculer($userId, (int) $prec['id']);
        return ['annee' => (int) $prec['annee'], 'credit_c' => (int) ($c['credit_final_c'] ?? 0)];
    }

    // --- Réglages ---------------------------------------------------------------

    /** @return string erreur, ou '' */
    public static function definirPeriodicite(int $userId, string $periodicite): string
    {
        if (!isset(self::PERIODICITES[$periodicite])) {
            return 'Périodicité inconnue.';
        }
        Database::modifier('users', ['tva_periodicite' => $periodicite], 'id = :i', ['i' => $userId]);
        return '';
    }

    /**
     * Le crédit de TVA reporté à l'ouverture d'un exercice. Réservé à un exercice ouvert : sur un exercice clos, il
     * fausserait des chiffres que l'on a figés.
     *
     * @return string erreur, ou ''
     */
    public static function definirCreditInitial(int $userId, int $exerciceId, float $montant): string
    {
        $ex = Exercice::trouver($userId, $exerciceId);
        if ($ex === null) {
            return 'Exercice introuvable.';
        }
        if ($ex['statut'] !== 'ouvert') {
            return 'L\'exercice est clos : son crédit de TVA ne se modifie plus.';
        }
        if ($montant < 0 || $montant > 99999999.99) {
            return 'Le crédit de TVA est un montant positif ou nul.';
        }
        Database::modifier('exercices', ['tva_credit_initial' => number_format($montant, 2, '.', '')], 'id = :i AND user_id = :u', ['i' => $exerciceId, 'u' => $userId]);
        return '';
    }
}
