<?php
declare(strict_types=1);

namespace Bnc;

/** Sorties : PDF de la 2035, CSV des registres. */
final class Export
{
    // --- 2035 en PDF -------------------------------------------------

    public static function declarationPdf(array $user, array $exercice, array $calc): string
    {
        $pdf = new Pdf('Declaration 2035 - exercice ' . $exercice['annee'], $user['nom']);

        $encre  = [20, 32, 51];
        $gris   = [107, 122, 141];
        $trait  = [220, 227, 236];
        $vert   = [14, 124, 90];
        $rouge  = [180, 70, 60];

        $y = 8.0;

        // En-tête, avec le logo en repère visuel du document.
        $pdf->image(dirname(__DIR__) . '/public/assets/img/logo-440.png', 20, $y, 28);
        $y = 27.0;
        $pdf->texte(20, $y, 'DÉCLARATION 2035', 15, true, $encre);
        $pdf->texteDroite(190, $y, 'Exercice ' . $exercice['annee'], 11, true, $encre);
        $y += 5.5;
        $pdf->texte(20, $y, 'Annexe 2035-A — bénéfices non commerciaux, régime de la déclaration contrôlée', 8, false, $gris);
        $y += 4;

        $identite = $user['denomination'] ?: $user['nom'];
        if (!empty($user['siret'])) {
            $identite .= '   SIRET ' . $user['siret'];
        }
        if (!empty($user['code_ape'])) {
            $identite .= '   APE ' . $user['code_ape'];
        }
        $pdf->texte(20, $y, $identite, 8.5, false, $encre);
        $pdf->texteDroite(190, $y, 'Du ' . View::date($exercice['date_debut']) . ' au ' . View::date($exercice['date_fin']), 8, false, $gris);

        // L'adresse du déclarant, quand elle est renseignée, sur sa propre ligne.
        $adresse = Utilisateur::adresseLigne($user);
        if ($adresse !== '') {
            $y += 4;
            $pdf->texte(20, $y, $adresse, 8, false, $gris);
        }
        $y += 3;
        $pdf->filet(20, $y, 190, $y, 0.5, $encre);
        $y += 7;

        // Corps du formulaire
        foreach (Declaration::lignes($calc) as $l) {
            if ($l['type'] === 'bandeau') {
                $y += 3;
                $pdf->aplat(20, $y - 3.6, 170, 5.6, [246, 248, 251]);
                $pdf->texte(22, $y, mb_strtoupper($l['libelle']), 8, true, $gris);
                $y += 5.5;
                continue;
            }

            $gras = $l['type'] !== 'ligne';
            $montant = $l['montant'];

            if ($l['type'] === 'resultat') {
                $y += 2;
                $pdf->aplat(20, $y - 4.4, 170, 7.5, $encre);
                $pdf->texte(23, $y, $l['code'], 8.5, true, [255, 255, 255]);
                $pdf->texte(33, $y, $l['libelle'], 10, true, [255, 255, 255]);
                $pdf->texteDroite(187, $y, self::euros($montant), 11, true, [255, 255, 255]);
                $y += 8;
                continue;
            }

            if ($l['type'] === 'total') {
                $pdf->aplat(20, $y - 3.8, 170, 6, [246, 248, 251]);
            }

            $pdf->texte(23, $y, $l['code'], 8, true, $gris);
            $pdf->texte(33, $y, $l['libelle'], 8.5, $gras, $encre);
            $pdf->texteDroite(187, $y,
                $montant === null ? '—' : self::euros($montant),
                8.5, $gras,
                $montant === null ? $gris : $encre);

            $y += 5.2;
            $pdf->filet(20, $y - 1.6, 190, $y - 1.6, 0.15, $trait);

            if ($y > 265) {
                $pdf->nouvellePage();
                $y = 22.0;
            }
        }

        // Détail des indemnités kilométriques, si l'option est retenue
        if (!empty($calc['option_bareme']) && !empty($calc['indemnites']['lignes'])) {
            if ($y > 230) { $pdf->nouvellePage(); $y = 22.0; }
            $y += 4;
            $pdf->aplat(20, $y - 3.6, 170, 5.6, [246, 248, 251]);
            $pdf->texte(22, $y, 'BARÈME KILOMÉTRIQUE — DÉTAIL DE LA CASE BR', 8, true, $gris);
            $y += 6.5;

            foreach ($calc['indemnites']['lignes'] as $l) {
                $v = $l['vehicule'];
                $pdf->texte(22, $y, mb_substr($v['designation'], 0, 30)
                    . ' (' . (int) $v['puissance_cv'] . ' CV'
                    . ((int) $v['electrique'] === 1 ? ', électrique' : '') . ')', 8, false, $encre);
                $pdf->texteDroite(140, $y, number_format($l['kilometres'], 0, ',', ' ') . ' km', 8, false, $encre);
                $pdf->texteDroite(187, $y, $l['calcul'] ? self::euros($l['calcul']['montant']) : '—', 8, false, $encre);
                $y += 4.6;
                $pdf->filet(20, $y - 1.5, 190, $y - 1.5, 0.15, $trait);
                if ($y > 265) { $pdf->nouvellePage(); $y = 22.0; }
            }

            $pdf->texte(22, $y, 'Les frais réels de véhicule et l\'amortissement des véhicules', 7.5, false, $gris);
            $y += 3.4;
            $pdf->texte(22, $y, 'concernés ne sont pas déductibles sur option pour le barème.', 7.5, false, $gris);
            $y += 5.5;
        }

        // Annexe 2035-B
        if (!empty($calc['tableau_2035b'])) {
            if ($y > 210) { $pdf->nouvellePage(); $y = 22.0; }
            $y += 4;
            $pdf->aplat(20, $y - 3.6, 170, 5.6, [246, 248, 251]);
            $pdf->texte(22, $y, 'ANNEXE 2035-B — IMMOBILISATIONS ET AMORTISSEMENTS', 8, true, $gris);
            $y += 6.5;

            $pdf->texte(22, $y, 'Désignation', 7.5, true, $gris);
            $pdf->texte(88, $y, 'Mise en serv.', 7.5, true, $gris);
            $pdf->texteDroite(133, $y, 'Base', 7.5, true, $gris);
            $pdf->texteDroite(158, $y, 'Dotation', 7.5, true, $gris);
            $pdf->texteDroite(187, $y, 'VNC', 7.5, true, $gris);
            $y += 3.4;
            $pdf->filet(20, $y - 1.4, 190, $y - 1.4, 0.2, $trait);

            foreach ($calc['tableau_2035b'] as $b) {
                $nom = $b['immo']['designation'] . ($b['cede'] ? ' (cédé)' : '');
                $pdf->texte(22, $y, mb_substr($nom, 0, 42), 8, false, $encre);
                $pdf->texte(88, $y, View::date($b['immo']['date_mise_service']), 8, false, $encre);
                $pdf->texteDroite(133, $y, self::euros($b['base']), 8, false, $encre);
                $pdf->texteDroite(158, $y, $b['dotation'] > 0 ? self::euros($b['dotation']) : '—', 8, false, $encre);
                $pdf->texteDroite(187, $y, $b['cede'] ? '—' : self::euros($b['vnc']), 8, false, $encre);
                $y += 4.8;
                $pdf->filet(20, $y - 1.5, 190, $y - 1.5, 0.15, $trait);

                if ($y > 265) { $pdf->nouvellePage(); $y = 22.0; }
            }

            $pdf->aplat(20, $y - 3.8, 170, 6, [246, 248, 251]);
            $pdf->texte(22, $y, 'Dotation de l\'exercice — reportée en case CH', 8, true, $encre);
            $pdf->texteDroite(187, $y, self::euros($calc['dotation']), 8.5, true, $encre);
            $y += 6.5;

            if (($calc['plus_values']['nb'] ?? 0) > 0) {
                $pdf->texte(22, $y, 'Plus-value court terme (ajoutée au résultat)', 8, false, $encre);
                $pdf->texteDroite(187, $y, self::euros($calc['plus_values']['court_terme']), 8, false, $encre);
                $y += 4.4;
                $pdf->texte(22, $y, 'Plus-value long terme (taxée séparément)', 8, false, $encre);
                $pdf->texteDroite(187, $y, self::euros($calc['plus_values']['long_terme']), 8, false, $encre);
                $y += 5;
            }
        }

        // Contrôles
        if ($y > 250) { $pdf->nouvellePage(); $y = 22.0; }
        $y += 4;
        $pdf->texte(20, $y, 'AVANT DE DÉCLARER', 8, true, $gris);
        $y += 5;
        foreach ($calc['controles'] as $c) {
            $couleur = $c['niveau'] === 'ok' ? $vert : ($c['niveau'] === 'erreur' ? $rouge : [168, 114, 14]);
            $texte = $c['nb'] !== null ? $c['nb'] . ' ' . $c['texte'] : $c['texte'];
            $pdf->texte(22, $y, '•', 9, true, $couleur);
            $pdf->texte(26, $y, $texte, 8.5, false, $encre);
            $y += 4;
            $pdf->texte(26, $y, $c['detail'], 7.5, false, $gris);
            $y += 5.5;
        }

        // Pied
        $pdf->filet(20, 282, 190, 282, 0.15, $trait);
        $pdf->texte(20, 286, 'Établi le ' . date('d/m/Y') . ' à partir du livre des recettes et du registre des dépenses.', 7.5, false, $gris);
        $pdf->texte(20, 289.5, 'Document de travail — ne remplace pas la télédéclaration officielle.', 7.5, false, $gris);

        return $pdf->rendu();
    }

    private static function euros(float $v): string
    {
        return number_format($v, 2, ',', ' ') . ' €';
    }

    // --- Registres en CSV --------------------------------------------

    /** Statut d'une écriture, en clair, pour les exports. */
    private static function libelleStatut(string $statut): string
    {
        return match ($statut) {
            'annulee'    => 'Annulée',
            'annulation' => 'Annulation',
            default      => 'Active',
        };
    }

    /** La page TVA, période par période, pour l'expert-comptable. */
    public static function tvaCsv(int $userId, int $exerciceId): string
    {
        $t = TvaPeriodes::calculer($userId, $exerciceId);
        $e = static fn($c) => number_format(((int) $c) / 100, 2, ',', '');
        $sortie = [['Période', 'Du', 'Au', 'TVA collectée', 'TVA déductible (dépenses)', 'TVA déductible (acquisitions)',
                    'TVA nette', 'Crédit reporté (entrée)', 'TVA à payer', 'Crédit à reporter', 'Écritures à éclater']];
        foreach ($t['periodes'] ?? [] as $p) {
            $sortie[] = [$p['libelle'], View::date($p['debut']), View::date($p['fin']), $e($p['collectee_c']), $e($p['deductible_c']),
                         $e($p['acquisitions_c']), $e($p['net_c']), $e($p['credit_entrant_c']), $e($p['a_payer_c']),
                         $e($p['credit_sortant_c']), (int) $p['nb_a_eclater']];
        }
        $tt = $t['totaux'] ?? [];
        $sortie[] = ['TOTAL', '', '', $e($tt['collectee_c'] ?? 0), $e($tt['deductible_c'] ?? 0), $e($tt['acquisitions_c'] ?? 0),
                     $e($tt['net_c'] ?? 0), $e($t['credit_initial_c'] ?? 0), $e($tt['a_payer_c'] ?? 0), $e($t['credit_final_c'] ?? 0),
                     (int) (($t['a_eclater']['recettes'] ?? 0) + ($t['a_eclater']['depenses'] ?? 0))];
        return self::versCsv($sortie);
    }

    public static function recettesCsv(int $userId, int $exerciceId): string
    {
        $lignes = Recette::lister($userId, $exerciceId);
        usort($lignes, static fn($a, $b) => $a['numero_chrono'] <=> $b['numero_chrono']);

        // Les corrections figurent à l'export avec leur statut : un fichier qui
        // les passerait sous silence ne ressemblerait plus au livre. L'écriture
        // d'annulation étant au montant opposé, la somme des lignes égale le
        // TOTAL, qui ne retient que les écritures actives.
        // Assujetti à la TVA : le TTC du virement, puis son éclatement HT / TVA. Les autres gardent leur format.
        $tva = Tva::actif($userId);
        $n2 = static fn($v) => $v === null ? '' : number_format((float) $v, 2, ',', '');
        $entete = ['N°', 'Date encaissement', 'Client', 'Nature', 'Règlement', 'Référence facture', 'Poste 2035'];
        $sortie = [$tva
            ? array_merge($entete, ['Montant TTC', 'Montant HT', 'TVA', 'Taux TVA %', 'Statut', 'Motif d\'annulation'])
            : array_merge($entete, ['Montant', 'Statut', 'Motif d\'annulation'])];
        foreach ($lignes as $l) {
            $debut = [
                $l['numero_chrono'], View::date($l['date_encaissement']), $l['client'], $l['nature'],
                ucfirst((string) $l['mode_reglement']), (string) $l['reference_facture'],
                $l['poste_2035'], number_format((float) $l['montant'], 2, ',', ''),
            ];
            $fin = [self::libelleStatut((string) $l['statut']), (string) $l['motif_annulation']];
            $sortie[] = $tva
                ? array_merge($debut, [$n2($l['montant_ht']), $n2($l['montant_tva']), $n2($l['taux_tva'])], $fin)
                : array_merge($debut, $fin);
        }
        if ($tva) {
            $t = Tva::totaux($userId, $exerciceId, false);
            $sortie[] = ['', '', '', '', '', '', 'TOTAL (écritures actives)', number_format($t['ttc'], 2, ',', ''),
                         number_format($t['ht'], 2, ',', ''), number_format($t['tva'], 2, ',', ''), '', '', ''];
        } else {
            $sortie[] = ['', '', '', '', '', '', 'TOTAL (écritures actives)',
                         number_format(Recette::total($userId, $exerciceId), 2, ',', ''), '', ''];
        }
        return self::versCsv($sortie);
    }

    public static function depensesCsv(int $userId, int $exerciceId): string
    {
        // Sens naturel d'un registre : l'originale, son annulation, puis la nouvelle écriture.
        $lignes = array_reverse(Depense::lister($userId, $exerciceId));
        $tva = Tva::actif($userId);
        $n2 = static fn($v) => $v === null ? '' : number_format((float) $v, 2, ',', '');
        $sortie = [$tva
            ? ['Date décaissement', 'Fournisseur', 'Libellé', 'Poste 2035', 'Intitulé du poste', 'Montant payé (TTC)', 'Montant HT',
               'TVA', 'Taux TVA %', 'TVA non récupérable', 'Quote-part pro %', 'Montant déductible', 'Justificatif', 'Statut', 'Motif d\'annulation']
            : ['Date décaissement', 'Fournisseur', 'Libellé', 'Poste 2035', 'Intitulé du poste',
               'Montant payé', 'Quote-part pro %', 'Montant déductible', 'Justificatif',
               'Statut', 'Motif d\'annulation']];
        foreach ($lignes as $l) {
            // Le montant déductible part du montant RETENU : hors taxes (plus la TVA non récupérable) quand l'écriture est éclatée.
            $deductible = Tva::retenu($l, true, $tva) * (int) $l['quote_part_pro'] / 100;
            $debut = [View::date($l['date_decaissement']), $l['fournisseur'], $l['libelle'],
                      $l['poste_2035'], Poste2035::libelle($l['poste_2035']), number_format((float) $l['montant'], 2, ',', '')];
            $fin = [(int) $l['nb_justif'] > 0 ? 'oui' : 'non', self::libelleStatut((string) $l['statut']), (string) $l['motif_annulation']];
            $sortie[] = $tva
                ? array_merge($debut, [$n2($l['montant_ht']), $n2($l['montant_tva']), $n2($l['taux_tva']), $n2($l['tva_non_recuperable']),
                                       (int) $l['quote_part_pro'], number_format($deductible, 2, ',', '')], $fin)
                : array_merge($debut, [(int) $l['quote_part_pro'], number_format($deductible, 2, ',', '')], $fin);
        }
        $sortie[] = $tva
            ? ['', '', '', '', 'TOTAL DÉDUCTIBLE (écritures actives)', '', '', '', '', '', '',
               number_format(Depense::total($userId, $exerciceId), 2, ',', ''), '', '', '']
            : ['', '', '', '', 'TOTAL DÉDUCTIBLE (écritures actives)', '', '',
               number_format(Depense::total($userId, $exerciceId), 2, ',', ''), '', '', ''];
        return self::versCsv($sortie);
    }

    /**
     * CSV à séparateur point-virgule, avec BOM : c'est ce qu'attend Excel
     * en configuration française, faute de quoi les accents ressortent faux.
     */
    private static function versCsv(array $lignes): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        foreach ($lignes as $l) {
            fputcsv($h, $l, ';', '"', '\\');
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);
        return $csv;
    }

    public static function trajetsCsv(int $userId, int $exerciceId, int $annee): string
    {
        $sortie = [['Date', 'Véhicule', 'Immatriculation', 'Motif du déplacement', 'Kilomètres']];
        $total = 0.0;

        foreach (Vehicule::trajets($userId, $exerciceId) as $t) {
            $v = Vehicule::trouver($userId, (int) $t['vehicule_id']);
            $sortie[] = [
                View::date($t['date_trajet']), $t['vehicule'],
                $v['immatriculation'] ?? '', $t['motif'],
                number_format((float) $t['kilometres'], 1, ',', ''),
            ];
            $total += (float) $t['kilometres'];
        }
        $sortie[] = ['', '', '', 'TOTAL', number_format($total, 1, ',', '')];

        foreach (Vehicule::indemnites($userId, $exerciceId, $annee)['lignes'] as $l) {
            $sortie[] = ['', '', '', 'Indemnité ' . $l['vehicule']['designation'],
                         $l['calcul'] ? number_format($l['calcul']['montant'], 2, ',', '') : 'barème absent'];
        }
        return self::versCsv($sortie);
    }

    public static function envoyer(string $contenu, string $nom, string $mime): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($contenu));
        header('Content-Disposition: attachment; filename="' . $nom . '"');
        header('X-Content-Type-Options: nosniff');
        echo $contenu;
    }
}
