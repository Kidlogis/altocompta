<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Générateur PDF minimal, sans dépendance.
 *
 * Couvre ce qu'exige un formulaire : texte, filets, aplats, en utilisant
 * les polices de base garanties par le format (Helvetica). Le texte est
 * converti en WinAnsi, l'encodage attendu par ces polices, sans quoi les
 * accents ressortent faux.
 *
 * Unité : le millimètre. Origine en haut à gauche, comme on lit une page.
 */
final class Pdf
{
    private const MM = 2.834645669;          // millimètre -> point PostScript
    private const LARGEUR = 210.0;
    private const HAUTEUR = 297.0;

    private array $pages = [];
    private string $flux = '';
    private array $polices = ['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold'];

    /** @var array<string,array{nom:string,largeur:int,hauteur:int,rgb:string,alpha:?string}> */
    private array $images = [];

    public function __construct(
        private readonly string $titre = 'Document',
        private readonly string $auteur = 'AltoCompta',
    ) {}

    public function nouvellePage(): void
    {
        $this->fermerPage();
        $this->flux = '';
    }

    private function fermerPage(): void
    {
        if ($this->flux !== '') {
            $this->pages[] = $this->flux;
        }
    }

    public function texte(float $x, float $y, string $texte, float $taille = 9.0, bool $gras = false, array $rvb = [0, 0, 0]): void
    {
        $this->flux .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $gras ? 'F2' : 'F1', $taille,
            $rvb[0] / 255, $rvb[1] / 255, $rvb[2] / 255,
            $x * self::MM, (self::HAUTEUR - $y) * self::MM,
            $this->echapper($texte)
        );
    }

    /** Texte aligné à droite sur l'abscisse donnée : indispensable pour les montants. */
    public function texteDroite(float $x, float $y, string $texte, float $taille = 9.0, bool $gras = false, array $rvb = [0, 0, 0]): void
    {
        $largeur = $this->largeurTexte($texte, $taille, $gras);
        $this->texte($x - $largeur, $y, $texte, $taille, $gras, $rvb);
    }

    public function filet(float $x1, float $y1, float $x2, float $y2, float $epaisseur = 0.2, array $rvb = [190, 200, 212]): void
    {
        $this->flux .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rvb[0] / 255, $rvb[1] / 255, $rvb[2] / 255, $epaisseur,
            $x1 * self::MM, (self::HAUTEUR - $y1) * self::MM,
            $x2 * self::MM, (self::HAUTEUR - $y2) * self::MM
        );
    }

    public function aplat(float $x, float $y, float $largeur, float $hauteur, array $rvb): void
    {
        $this->flux .= sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $rvb[0] / 255, $rvb[1] / 255, $rvb[2] / 255,
            $x * self::MM, (self::HAUTEUR - $y - $hauteur) * self::MM,
            $largeur * self::MM, $hauteur * self::MM
        );
    }

    /**
     * Largeur d'une chaîne en millimètres, d'après les métriques réelles
     * d'Helvetica (voir MetriquesHelvetica). Une ancienne version la
     * calculait avec une moyenne de 0,50 em par caractère : assez bon pour
     * aligner un nombre à droite, mais 4 à 5 % trop étroit sur une phrase,
     * qui débordait alors de la page.
     */
    public function largeurTexte(string $texte, float $taille, bool $gras = false): float
    {
        // Les mêmes caractères que ceux réellement imprimés : mesurer le texte
        // brut puis imprimer sa version normalisée fausserait la largeur.
        $milliemes = MetriquesHelvetica::largeur(self::normaliser($texte), $gras);
        return ($milliemes / 1000.0) * $taille / self::MM;
    }

    /**
     * Place une image PNG. Position du coin haut-gauche en millimètres,
     * comme texte() ; la hauteur se déduit du rapport largeur/hauteur si
     * elle n'est pas fournie.
     *
     * Une image absente ou illisible ne fait pas échouer le document : le
     * PDF se génère sans elle plutôt que de bloquer une déclaration.
     */
    public function image(string $chemin, float $x, float $y, float $largeurMm, ?float $hauteurMm = null): void
    {
        if (!isset($this->images[$chemin])) {
            $donnees = self::decoderPng($chemin);
            if ($donnees === null) {
                return;
            }
            $donnees['nom'] = 'Im' . (count($this->images) + 1);
            $this->images[$chemin] = $donnees;
        }
        $img = $this->images[$chemin];
        $hauteurMm ??= $largeurMm * $img['hauteur'] / $img['largeur'];

        // Même repère que texte() : origine en haut, y croissant vers le bas.
        $xPt = $x * self::MM;
        $yPt = (self::HAUTEUR - $y - $hauteurMm) * self::MM;
        $this->flux .= sprintf(
            "q %.3F 0 0 %.3F %.3F %.3F cm /%s Do Q\n",
            $largeurMm * self::MM, $hauteurMm * self::MM, $xPt, $yPt, $img['nom']
        );
    }

    /**
     * Décode un PNG via GD en plans RGB et alpha séparés, prêts pour un
     * XObject Image et son SMask. Pixel par pixel : les logos et pictogrammes
     * qui passent ici restent petits, la boucle ne pèse pas.
     *
     * @return array{largeur:int,hauteur:int,rgb:string,alpha:?string}|null
     */
    private static function decoderPng(string $chemin): ?array
    {
        if (!is_file($chemin) || !function_exists('imagecreatefrompng')) {
            return null;
        }
        $im = @imagecreatefrompng($chemin);
        if ($im === false) {
            return null;
        }
        imagesavealpha($im, true);
        $w = imagesx($im);
        $h = imagesy($im);

        $rgb = '';
        $alpha = '';
        $opaque = true;

        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $c = imagecolorat($im, $px, $py);
                $a = ($c >> 24) & 0x7F;              // GD : 0 opaque, 127 transparent
                $o = 255 - (int) round($a * 255 / 127);
                $rgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                $alpha .= chr($o);
                if ($o !== 255) {
                    $opaque = false;
                }
            }
        }
        imagedestroy($im);

        return ['largeur' => $w, 'hauteur' => $h, 'rgb' => $rgb, 'alpha' => $opaque ? null : $alpha];
    }

    public function rendu(): string
    {
        $this->fermerPage();
        if (!$this->pages) {
            $this->pages[] = '';
        }

        $objets = [];
        $nbPages = count($this->pages);

        // 1 catalogue, 2 arbre des pages, puis par page : page + contenu.
        $idsPages = [];
        for ($i = 0; $i < $nbPages; $i++) {
            $idsPages[] = 3 + $i * 2;
        }

        $objets[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objets[2] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d >>",
            implode(' ', array_map(static fn($id) => "$id 0 R", $idsPages)),
            $nbPages
        );

        $idPolice = 3 + $nbPages * 2;

        // XObjects image : un jeu d'IDs partagé par toutes les pages, référencé
        // dans les ressources de chacune. Un logo répété sur plusieurs pages
        // n'est donc encodé qu'une fois.
        $idApresPolices = $idPolice + count($this->polices);
        $idSuivant = $idApresPolices;
        $refsImages = [];
        foreach ($this->images as $img) {
            $idImg = $idSuivant++;
            $refSMask = '';
            if ($img['alpha'] !== null) {
                $idSMask = $idSuivant++;
                $compr = (string) gzcompress($img['alpha'], 6);
                $objets[$idSMask] = sprintf(
                    "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                    . "/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length %d >>\nstream\n%s\nendstream",
                    $img['largeur'], $img['hauteur'], strlen($compr), $compr
                );
                $refSMask = " /SMask $idSMask 0 R";
            }
            $comprRgb = (string) gzcompress($img['rgb'], 6);
            $objets[$idImg] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d "
                . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode%s /Length %d >>\nstream\n%s\nendstream",
                $img['largeur'], $img['hauteur'], $refSMask, strlen($comprRgb), $comprRgb
            );
            $refsImages[$img['nom']] = $idImg;
        }
        $ressourcesXObject = $refsImages
            ? ' /XObject << ' . implode(' ', array_map(
                  static fn($nom, $id) => "/$nom $id 0 R", array_keys($refsImages), $refsImages
              )) . ' >>'
            : '';

        foreach ($this->pages as $i => $contenu) {
            $idPage = 3 + $i * 2;
            $idFlux = $idPage + 1;
            $objets[$idPage] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] "
                . "/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >>%s >> /Contents %d 0 R >>",
                self::LARGEUR * self::MM, self::HAUTEUR * self::MM,
                $idPolice, $idPolice + 1, $ressourcesXObject, $idFlux
            );
            $objets[$idFlux] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($contenu), $contenu);
        }

        $i = 0;
        foreach ($this->polices as $nom => $police) {
            $objets[$idPolice + $i] = sprintf(
                "<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>", $police
            );
            $i++;
        }

        $idInfo = $idSuivant;
        $objets[$idInfo] = sprintf(
            "<< /Title (%s) /Author (%s) /Producer (AltoCompta) /CreationDate (D:%s) >>",
            $this->echapper($this->titre), $this->echapper($this->auteur), date('YmdHis')
        );

        // Assemblage avec la table de références croisées.
        $pdf = "%PDF-1.4\n";
        $decalages = [];
        ksort($objets);
        foreach ($objets as $id => $corps) {
            $decalages[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$corps\nendobj\n";
        }

        $debutXref = strlen($pdf);
        $max = max(array_keys($objets)) + 1;
        $pdf .= "xref\n0 $max\n0000000000 65535 f \n";
        for ($id = 1; $id < $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $decalages[$id] ?? 0);
        }
        $pdf .= sprintf("trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            $max, $idInfo, $debutXref);

        return $pdf;
    }

    /**
     * Remplace les caractères que Windows-1252 ne connaît pas par leur
     * équivalent imprimable. Sans cela, la conversion les change en « ? » :
     * le signe moins typographique (U+2212) que View::montant() place devant
     * un montant négatif s'imprimait « ?1 234,50 » dans les registres.
     */
    public static function normaliser(string $s): string
    {
        return strtr($s, [
            "\u{2212}" => '-',   // signe moins
            "\u{2011}" => '-',   // trait d'union insécable
            "\u{202F}" => ' ',   // espace fine insécable
            "\u{2009}" => ' ',   // espace fine
            "\u{2007}" => ' ',   // espace chiffre
        ]);
    }

    /** Conversion en WinAnsi et échappement des caractères réservés du PDF. */
    private function echapper(string $s): string
    {
        $s = @mb_convert_encoding(self::normaliser($s), 'Windows-1252', 'UTF-8');
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], (string) $s);
    }
}
