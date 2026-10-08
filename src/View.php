<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Rendu de gabarits PHP, échappement par défaut.
 */
final class View
{
    private static string $racine = __DIR__ . '/../templates';

    /**
     * Chemin d'un fichier statique (CSS, image), suffixé de la date de
     * modification du fichier. Sans ça, un navigateur qui a déjà mis en
     * cache une ancienne version de app.css ou d'un logo continue de la
     * servir après une mise à jour, jusqu'à un rechargement forcé — le
     * paramètre change dès que le fichier change, ce qui force le
     * navigateur à le retélécharger sans que quiconque ait à vider son cache.
     */
    /** @var array<string,string>|null chargé une fois, à la demande */
    private static ?array $icones = null;

    /**
     * Pictogramme Iconoir (traits, 24×24, MIT — https://iconoir.com), prêt à
     * insérer dans le menu en tuiles. La couleur suit currentColor : elle se
     * règle en CSS sur l'élément englobant, pas ici.
     */
    public static function icone(string $nom): string
    {
        self::$icones ??= require __DIR__ . '/icones.php';
        $contenu = self::$icones[$nom] ?? '';
        return '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
             . $contenu . '</svg>';
    }

    public static function asset(string $chemin): string
    {
        // Le fichier n'est pas toujours là où on le croit : chez un hébergeur mutualisé, le dossier public s'appelle
        // « public_html », et c'est le serveur web qui sait où il se trouve. Chercher seulement dans « public » ne
        // trouvait rien, donc ne versionnait rien : après une mise à jour, le navigateur gardait l'ancien CSS et
        // l'ancien JavaScript, et un bouton ajouté ne faisait rien.
        foreach ([(string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), dirname(__DIR__) . '/public', dirname(__DIR__) . '/public_html'] as $racine) {
            if ($racine !== '' && is_file($racine . $chemin)) {
                return $chemin . '?v=' . filemtime($racine . $chemin);
            }
        }
        // Dernier recours, qui vaut mieux que rien : le numéro de version de l'application, qui change à chaque mise à jour.
        $v = @file_get_contents(dirname(__DIR__) . '/VERSION');
        return $chemin . ($v !== false && trim($v) !== '' ? '?v=' . rawurlencode(trim($v)) : '');
    }

    /** Dossiers de gabarits supplémentaires, apportés par les plugins actifs. */
    private static array $racinesPlugins = [];

    public static function ajouterRacine(string $dossier): void
    {
        if (is_dir($dossier) && !in_array($dossier, self::$racinesPlugins, true)) {
            self::$racinesPlugins[] = rtrim($dossier, '/');
        }
    }

    public static function rendre(string $gabarit, array $donnees = [], ?string $layout = 'layout/base'): void
    {
        $contenu = self::capturer($gabarit, $donnees);

        if ($layout === null) {
            echo $contenu;
            return;
        }
        echo self::capturer($layout, $donnees + ['contenu' => $contenu]);
    }

    private static function capturer(string $gabarit, array $donnees): string
    {
        $fichier = self::$racine . '/' . $gabarit . '.php';

        // Un gabarit du cœur prime ; sinon on cherche chez les plugins actifs.
        if (!is_file($fichier)) {
            foreach (self::$racinesPlugins as $racine) {
                $candidat = $racine . '/' . $gabarit . '.php';
                if (is_file($candidat)) {
                    $fichier = $candidat;
                    break;
                }
            }
        }
        if (!is_file($fichier)) {
            throw new \RuntimeException("Gabarit introuvable : {$gabarit}");
        }
        extract($donnees, EXTR_SKIP);
        ob_start();
        require $fichier;
        return (string) ob_get_clean();
    }

    /** Échappement HTML. */
    public static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Montant à la française : 1 440,00 */
    public static function montant($v, bool $signe = false): string
    {
        $n = (float) $v;
        $s = number_format(abs($n), 2, ',', ' ');
        if ($signe) {
            return ($n < 0 ? '−' : '+') . $s;
        }
        return ($n < 0 ? '−' : '') . $s;
    }

    /** Date SQL vers jj/mm/aaaa */
    public static function date(?string $sql, string $format = 'd/m/Y'): string
    {
        if (!$sql) {
            return '—';
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($sql, 0, 10));
        return $d ? $d->format($format) : '—';
    }
}
