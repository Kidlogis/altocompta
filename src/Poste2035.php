<?php
declare(strict_types=1);

namespace Bnc;

/** Référentiel des postes de l'annexe 2035-A. */
final class Poste2035
{
    private static ?array $cache = null;

    public static function tous(): array
    {
        return self::$cache ??= Database::tous(
            'SELECT code, sens, libelle, ordre, saisissable FROM postes_2035 ORDER BY ordre'
        );
    }

    /** @return array<int,array> postes saisissables d'un sens donné */
    public static function saisissables(string $sens): array
    {
        return array_values(array_filter(
            self::tous(),
            static fn(array $p) => $p['sens'] === $sens && (int) $p['saisissable'] === 1
        ));
    }

    public static function libelle(string $code): string
    {
        foreach (self::tous() as $p) {
            if ($p['code'] === $code) {
                return $p['libelle'];
            }
        }
        return $code;
    }

    public static function existe(string $code, ?string $sens = null): bool
    {
        foreach (self::tous() as $p) {
            if ($p['code'] === $code) {
                return $sens === null || $p['sens'] === $sens;
            }
        }
        return false;
    }
}
