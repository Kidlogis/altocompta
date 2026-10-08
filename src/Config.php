<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Accès en lecture seule à la configuration, avec notation pointée.
 */
final class Config
{
    private static ?array $valeurs = null;

    public static function charger(string $chemin): void
    {
        if (!is_file($chemin)) {
            throw new \RuntimeException(
                "Configuration absente. Copier config/config.example.php en config/config.php."
            );
        }
        self::$valeurs = require $chemin;
    }

    /** @param mixed $defaut */
    public static function get(string $cle, $defaut = null)
    {
        if (self::$valeurs === null) {
            throw new \RuntimeException('Configuration non chargée.');
        }
        $noeud = self::$valeurs;
        foreach (explode('.', $cle) as $segment) {
            if (!is_array($noeud) || !array_key_exists($segment, $noeud)) {
                return $defaut;
            }
            $noeud = $noeud[$segment];
        }
        return $noeud;
    }

    public static function requis(string $cle)
    {
        $v = self::get($cle);
        if ($v === null || $v === '' || $v === 'A_REMPLACER') {
            throw new \RuntimeException("Clé de configuration manquante ou non renseignée : {$cle}");
        }
        return $v;
    }
}
