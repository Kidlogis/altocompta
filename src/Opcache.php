<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Cache d'opcodes de PHP : à vider quand des fichiers de l'application viennent
 * d'être remplacés.
 *
 * PHP compile chaque fichier une fois et garde le résultat en mémoire, en ne
 * revérifiant la date du fichier que toutes les quelques secondes
 * (opcache.revalidate_freq, 2 s par défaut, souvent bien davantage chez un
 * hébergeur mutualisé). Juste après un remplacement de fichiers, les requêtes
 * peuvent donc exécuter un mélange d'anciennes et de nouvelles versions — un
 * gabarit neuf appelant une méthode qui n'existe encore que dans le cache
 * ancien —, d'où des erreurs qui disparaissent d'elles-mêmes quelques secondes
 * plus tard, ce qui les rend d'autant plus déroutantes.
 *
 * Sans effet, et sans erreur, quand l'extension est absente ou que
 * l'hébergeur en restreint l'usage (opcache.restrict_api).
 */
final class Opcache
{
    /** @return bool true si le cache a effectivement été vidé */
    public static function vider(): bool
    {
        clearstatcache(true);
        if (!function_exists('opcache_reset')) {
            return false;
        }
        return (bool) @opcache_reset();
    }
}
