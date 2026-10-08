<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Édition des registres en PDF.
 *
 * Le livre des recettes et le registre des dépenses doivent pouvoir être
 * présentés en cas de contrôle. Un CSV n'y suffit pas : il faut un document
 * paginé, daté, portant l'identité du déclarant et les totaux.
 */
final class RegistrePdf
{
    private const HAUT   = 22.0;
    private const BAS    = 268.0;
    private const GAUCHE = 15.0;
    private const DROITE = 195.0;

    private const ENCRE = [20, 32, 51];
    private const GRIS  = [107, 122, 141];
    private const TRAIT = [220, 227, 236];

    /** Marge intérieure d'une colonne, pour que deux valeurs ne se touchent pas. */
    private const MARGE = 3.0;

    /**
     * @param array<int,array{titre:string, largeur:float, align?:string}> $colonnes
     * @param array<int,array<int,string>> $lignes
     * @param array<int,string>|null $total
     */
    public static function table(
        array $user, array $exercice, string $titre, string $sousTitre,
        array $colonnes, array $lignes, ?array $total = null, string $note = '',
        array $annulees = []
    ): string {
        $pdf = new Pdf($titre . ' ' . $exercice['annee'], (string) $user['nom']);

        $y = self::entete($pdf, $user, $exercice, $titre, $sousTitre);
        $y = self::enteteColonnes($pdf, $colonnes, $y);
        $page = 1;

        foreach ($lignes as $i => $ligne) {
            if ($y > self::BAS) {
                self::pied($pdf, $page);
                $pdf->nouvellePage();
                $page++;
                $y = self::entete($pdf, $user, $exercice, $titre, $sousTitre, true);
                $y = self::enteteColonnes($pdf, $colonnes, $y);
            }

            // Fond alterné : sur un tableau dense, l'œil perd la ligne sans repère.
            if ($i % 2 === 1) {
                $pdf->aplat(self::GAUCHE, $y - 3.4, self::DROITE - self::GAUCHE, 4.8, [249, 250, 252]);
            }
            $annulee = in_array($i, $annulees, true);
            self::ligne($pdf, $colonnes, $ligne, $y, false, $annulee);
            $y += 4.8;
        }

        if ($total !== null) {
            if ($y > self::BAS - 6) {
                self::pied($pdf, $page);
                $pdf->nouvellePage();
                $page++;
                $y = self::entete($pdf, $user, $exercice, $titre, $sousTitre, true);
                $y = self::enteteColonnes($pdf, $colonnes, $y);
            }
            $pdf->aplat(self::GAUCHE, $y - 3.8, self::DROITE - self::GAUCHE, 6, [240, 243, 248]);
            self::ligne($pdf, $colonnes, $total, $y, true);
            $y += 7;
        }

        if ($note !== '') {
            // Retour à la ligne à la largeur réelle du texte : une note qui
            // déborde du cadre est coupée à l'impression, et c'est justement
            // la phrase qui explique les écritures annulées qui disparaissait.
            $yNote = $y + 3;
            foreach (self::couper($pdf, $note, self::DROITE - self::GAUCHE, 7.5) as $ligneNote) {
                if ($yNote > self::BAS + 4) {
                    self::pied($pdf, $page);
                    $pdf->nouvellePage();
                    $page++;
                    $yNote = self::HAUT;
                }
                $pdf->texte(self::GAUCHE, $yNote, $ligneNote, 7.5, false, self::GRIS);
                $yNote += 3.6;
            }
        }

        self::pied($pdf, $page);
        return $pdf->rendu();
    }

    /**
     * Découpe un texte en lignes qui tiennent dans la largeur donnée, en
     * mesurant la largeur réelle des glyphes — pas un nombre de caractères.
     *
     * @return array<int,string>
     */
    private static function couper(Pdf $pdf, string $texte, float $largeur, float $taille): array
    {
        $lignes = [];
        $courante = '';
        foreach (preg_split('/\s+/u', trim($texte)) ?: [] as $mot) {
            $essai = $courante === '' ? $mot : $courante . ' ' . $mot;
            if ($courante !== '' && $pdf->largeurTexte($essai, $taille, false) > $largeur) {
                $lignes[] = $courante;
                $courante = $mot;
            } else {
                $courante = $essai;
            }
        }
        if ($courante !== '') {
            $lignes[] = $courante;
        }
        return $lignes;
    }

    private static function entete(
        Pdf $pdf, array $user, array $exercice, string $titre, string $sousTitre, bool $suite = false
    ): float {
        $pdf->image(dirname(__DIR__) . '/public/assets/img/logo-440.png', self::GAUCHE, 6, 26);

        $y = self::HAUT;
        $pdf->texte(self::GAUCHE, $y, mb_strtoupper($titre) . ($suite ? ' (suite)' : ''), 13, true, self::ENCRE);
        $pdf->texteDroite(self::DROITE, $y, 'Exercice ' . $exercice['annee'], 10, true, self::ENCRE);
        $y += 5;

        $identite = $user['denomination'] ?: $user['nom'];
        if (!empty($user['siret'])) {
            $identite .= '   SIRET ' . $user['siret'];
        }
        if (!empty($user['code_ape'])) {
            $identite .= '   APE ' . $user['code_ape'];
        }
        $pdf->texte(self::GAUCHE, $y, $identite, 8, false, self::ENCRE);
        $pdf->texteDroite(self::DROITE, $y,
            'Du ' . View::date($exercice['date_debut']) . ' au ' . View::date($exercice['date_fin']),
            8, false, self::GRIS);
        $y += 4;

        $adresse = Utilisateur::adresseLigne($user);
        if ($adresse !== '') {
            $pdf->texte(self::GAUCHE, $y, $adresse, 7.5, false, self::GRIS);
            $y += 3.5;
        }

        if ($sousTitre !== '') {
            $pdf->texte(self::GAUCHE, $y, $sousTitre, 7.5, false, self::GRIS);
            $y += 3.5;
        }
        $pdf->filet(self::GAUCHE, $y, self::DROITE, $y, 0.5, self::ENCRE);
        return $y + 6;
    }

    private static function enteteColonnes(Pdf $pdf, array $colonnes, float $y): float
    {
        $x = self::GAUCHE;
        foreach ($colonnes as $col) {
            if (($col['align'] ?? 'gauche') === 'droite') {
                $pdf->texteDroite($x + $col['largeur'] - self::MARGE, $y, $col['titre'], 7, true, self::GRIS);
            } else {
                $pdf->texte($x + 1, $y, $col['titre'], 7, true, self::GRIS);
            }
            $x += $col['largeur'];
        }
        $y += 2.4;
        $pdf->filet(self::GAUCHE, $y, self::DROITE, $y, 0.3, self::TRAIT);
        return $y + 4;
    }

    /**
     * @param bool $annulee ligne de correction (écriture annulée ou son écriture
     *        d'annulation) : texte grisé. Elle reste imprimée — un registre ne
     *        cache pas ce qui a été saisi puis annulé — et c'est l'écriture
     *        d'annulation, au montant opposé, qui la compense : pas de barré.
     */
    private static function ligne(Pdf $pdf, array $colonnes, array $valeurs, float $y, bool $gras,
                                  bool $annulee = false): void
    {
        $couleur = $annulee ? self::GRIS : self::ENCRE;
        $x = self::GAUCHE;
        foreach ($colonnes as $i => $col) {
            $texte = (string) ($valeurs[$i] ?? '');
            if ($texte !== '') {
                // Tronquer plutôt que déborder sur la colonne voisine, en
                // mesurant la largeur réelle : un compte de caractères sous-
                // estime les chiffres et les slashes, et coupait les dates.
                // Les colonnes chiffrées ne sont jamais tronquées : un montant
                // amputé rendrait le registre inexploitable.
                $chiffree = ($col['align'] ?? 'gauche') === 'droite';
                $dispo = $col['largeur'] - self::MARGE - 1;

                // Une ligne de total (en gras) étiquette souvent sa ligne dans une
                // colonne étroite, suivie de cellules vides : le libellé peut
                // s'étendre sur elles plutôt que d'être tronqué en « TOT… ».
                if ($gras && !$chiffree) {
                    for ($k = $i + 1; isset($colonnes[$k]) && (string) ($valeurs[$k] ?? '') === ''; $k++) {
                        $dispo += $colonnes[$k]['largeur'];
                    }
                }

                if (!$chiffree && $pdf->largeurTexte($texte, 7.5, $gras) > $dispo) {
                    while ($texte !== '' && $pdf->largeurTexte($texte . '…', 7.5, $gras) > $dispo) {
                        $texte = mb_substr($texte, 0, -1);
                    }
                    $texte .= '…';
                }
                if (($col['align'] ?? 'gauche') === 'droite') {
                    $pdf->texteDroite($x + $col['largeur'] - self::MARGE, $y, $texte, 7.5, $gras, $couleur);
                } else {
                    $pdf->texte($x + 1, $y, $texte, 7.5, $gras, $couleur);
                }
            }
            $x += $col['largeur'];
        }
        $pdf->filet(self::GAUCHE, $y + 1.4, self::DROITE, $y + 1.4, 0.15, self::TRAIT);
    }

    private static function pied(Pdf $pdf, int $page): void
    {
        $pdf->filet(self::GAUCHE, 282, self::DROITE, 282, 0.15, self::TRAIT);
        $pdf->texte(self::GAUCHE, 286, 'Édité le ' . date('d/m/Y') . ' — AltoCompta', 7, false, self::GRIS);
        $pdf->texteDroite(self::DROITE, 286, 'Page ' . $page, 7, false, self::GRIS);
    }

    // --- Registres ----------------------------------------------------

    public static function livreRecettes(array $user, array $exercice, int $userId): string
    {
        $lignes = Recette::lister($userId, (int) $exercice['id']);
        usort($lignes, static fn($a, $b) => $a['numero_chrono'] <=> $b['numero_chrono']);

        $tva = Tva::actif($userId);
        $n2 = static fn($v) => $v === null ? '—' : View::montant($v);
        $rows = [];
        $annulees = [];
        $nbAnnulees = 0;
        foreach ($lignes as $l) {
            if ($l['statut'] !== 'active') {
                $annulees[] = count($rows);
                $nbAnnulees++;
            }
            $debut = [
                str_pad((string) $l['numero_chrono'], 3, '0', STR_PAD_LEFT),
                View::date($l['date_encaissement']),
                $l['client'],
                $l['nature'],
                ucfirst((string) $l['mode_reglement']),
                (string) $l['reference_facture'],
                View::montant($l['montant']),
            ];
            $rows[] = $tva ? array_merge($debut, [$n2($l['montant_ht']), $n2($l['montant_tva'])]) : $debut;
        }

        if ($tva) {
            $t = Tva::totaux($userId, (int) $exercice['id'], false);
            return self::table($user, $exercice, 'Livre des recettes',
                'Encaissements par ordre chronologique, numérotation continue — TTC, HT et TVA',
                [
                    ['titre' => 'N°',        'largeur' => 10],
                    ['titre' => 'Encaissé',  'largeur' => 17],
                    ['titre' => 'Client',    'largeur' => 30],
                    ['titre' => 'Nature',    'largeur' => 30],
                    ['titre' => 'Règlement', 'largeur' => 15],
                    ['titre' => 'Facture',   'largeur' => 15],
                    ['titre' => 'TTC',       'largeur' => 21, 'align' => 'droite'],
                    ['titre' => 'HT',        'largeur' => 21, 'align' => 'droite'],
                    ['titre' => 'TVA',       'largeur' => 21, 'align' => 'droite'],
                ],
                $rows,
                ['', '', '', '', '', 'TOTAL', View::montant($t['ttc']), View::montant($t['ht']), View::montant($t['tva'])],
                'Le TTC est le montant effectivement encaissé ; le HT et la TVA sont ceux éclatés sur la fiche de chaque écriture. '
                . 'Une écriture non éclatée compte pour son TTC dans la colonne HT du total.'
                . self::noteAnnulees($nbAnnulees),
                $annulees
            );
        }

        return self::table($user, $exercice, 'Livre des recettes',
            'Encaissements par ordre chronologique, numérotation continue',
            [
                ['titre' => 'N°',        'largeur' => 11],
                ['titre' => 'Encaissé',  'largeur' => 18],
                ['titre' => 'Client',    'largeur' => 41],
                ['titre' => 'Nature',    'largeur' => 49],
                ['titre' => 'Règlement', 'largeur' => 18],
                ['titre' => 'Facture',   'largeur' => 20],
                ['titre' => 'Montant',   'largeur' => 23, 'align' => 'droite'],
            ],
            $rows,
            ['', '', '', '', '', 'TOTAL', View::montant(Recette::total($userId, (int) $exercice['id']))],
            'Les montants sont ceux effectivement encaissés sur l\'exercice, hors toute facturation non réglée.'
            . self::noteAnnulees($nbAnnulees),
            $annulees
        );
    }

    public static function registreDepenses(array $user, array $exercice, int $userId): string
    {
        $tva = Tva::actif($userId);
        $n2 = static fn($v) => $v === null ? '—' : View::montant($v);
        $rows = [];
        $annulees = [];
        $nbAnnulees = 0;
        // Sens naturel d'un registre : l'originale, son annulation, puis la nouvelle écriture.
        foreach (array_reverse(Depense::lister($userId, (int) $exercice['id'])) as $l) {
            $deductible = Tva::retenu($l, true, $tva) * (int) $l['quote_part_pro'] / 100;
            if ($l['statut'] !== 'active') {
                $annulees[] = count($rows);
                $nbAnnulees++;
            }
            $rows[] = $tva ? [
                View::date($l['date_decaissement']), $l['fournisseur'], $l['libelle'], $l['poste_2035'],
                View::montant($l['montant']), $n2($l['montant_ht']), $n2($l['montant_tva']),
                (int) $l['quote_part_pro'] . ' %', View::montant($deductible), (int) $l['nb_justif'] > 0 ? 'oui' : '—',
            ] : [
                View::date($l['date_decaissement']),
                $l['fournisseur'],
                $l['libelle'],
                $l['poste_2035'],
                View::montant($l['montant']),
                (int) $l['quote_part_pro'] . ' %',
                View::montant($deductible),
                (int) $l['nb_justif'] > 0 ? 'oui' : '—',
            ];
        }

        if ($tva) {
            $t = Tva::totaux($userId, (int) $exercice['id'], true);
            return self::table($user, $exercice, 'Registre des dépenses',
                'Décaissements de l\'exercice — TTC, HT, TVA, quote-part professionnelle et poste 2035',
                [
                    ['titre' => 'Payé le',     'largeur' => 17],
                    ['titre' => 'Fournisseur', 'largeur' => 26],
                    ['titre' => 'Libellé',     'largeur' => 29],
                    ['titre' => 'Case',        'largeur' => 10],
                    ['titre' => 'TTC',         'largeur' => 20, 'align' => 'droite'],
                    ['titre' => 'HT',          'largeur' => 19, 'align' => 'droite'],
                    ['titre' => 'TVA',         'largeur' => 16, 'align' => 'droite'],
                    ['titre' => 'Part',        'largeur' => 11, 'align' => 'droite'],
                    ['titre' => 'Déductible',  'largeur' => 20, 'align' => 'droite'],
                    ['titre' => 'Justif.',     'largeur' => 12],
                ],
                $rows,
                ['', '', '', 'TOTAL', View::montant($t['ttc']), '', View::montant($t['tva']), '', View::montant(Depense::total($userId, (int) $exercice['id'])), ''],
                'Le déductible part du montant hors taxes (plus la TVA non récupérable) quand l\'écriture est éclatée, puis applique la quote-part '
                . 'professionnelle. Une écriture non éclatée compte pour son TTC.'
                . self::noteAnnulees($nbAnnulees),
                $annulees
            );
        }

        return self::table($user, $exercice, 'Registre des dépenses',
            'Décaissements de l\'exercice, avec quote-part professionnelle et poste 2035',
            [
                ['titre' => 'Payé le',      'largeur' => 18],
                ['titre' => 'Fournisseur',  'largeur' => 36],
                ['titre' => 'Libellé',      'largeur' => 42],
                ['titre' => 'Case',         'largeur' => 12],
                ['titre' => 'Montant',      'largeur' => 22, 'align' => 'droite'],
                ['titre' => 'Part',         'largeur' => 13, 'align' => 'droite'],
                ['titre' => 'Déductible',   'largeur' => 22, 'align' => 'droite'],
                ['titre' => 'Justif.',      'largeur' => 15],
            ],
            $rows,
            ['', '', '', 'TOTAL', '', '', View::montant(Depense::total($userId, (int) $exercice['id'])), ''],
            'Le montant déductible tient compte de la quote-part professionnelle des dépenses d\'usage mixte.'
            . self::noteAnnulees($nbAnnulees),
            $annulees
        );
    }

    /**
     * Mention de bas de registre quand des corrections y figurent : sans elle,
     * des lignes grisées au montant opposé paraîtraient une anomalie plutôt que
     * l'application de la règle.
     */
    private static function noteAnnulees(int $nb): string
    {
        if ($nb === 0) {
            return '';
        }
        return ' ' . $nb . ' ligne' . ($nb > 1 ? 's' : '') . ' de correction, en gris : une écriture annulée,'
             . ' puis son écriture d\'annulation au montant opposé. Leur somme est nulle ;'
             . ' le total ne retient que les écritures actives.';
    }
}
