<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Barème kilométrique forfaitaire.
 *
 * Le barème remplace les frais réels de véhicule : sur option annuelle et
 * globale, l'indemnité couvre carburant, entretien, assurance, pneumatiques
 * et dépréciation. En contrepartie, ni les dépenses de véhicule ni
 * l'amortissement du véhicule ne sont déductibles cette année-là.
 *
 * Les coefficients sont fixés par arrêté chaque année : ils sont donc
 * stockés en base et modifiables, jamais figés dans le code. Un exercice
 * sans barème saisi est signalé plutôt que calculé avec des valeurs périmées.
 */
final class BaremeKm
{
    /** Majoration applicable aux véhicules électriques. */
    public const MAJORATION_ELECTRIQUE = 1.20;

    public const TYPES = [
        'voiture'     => 'Voiture',
        'moto'        => 'Moto',
        'cyclomoteur' => 'Cyclomoteur',
    ];

    public static function anneesDisponibles(): array
    {
        return array_map('intval', array_column(
            Database::tous('SELECT DISTINCT annee FROM bareme_km ORDER BY annee DESC'), 'annee'
        ));
    }

    public static function existe(int $annee, string $type = 'voiture'): bool
    {
        return (bool) Database::valeur(
            'SELECT COUNT(*) FROM bareme_km WHERE annee = :a AND type_vehicule = :t',
            ['a' => $annee, 't' => $type], 0
        );
    }

    public static function lignes(int $annee): array
    {
        return Database::tous(
            'SELECT * FROM bareme_km WHERE annee = :a
              ORDER BY type_vehicule, puissance_min, tranche_min',
            ['a' => $annee]
        );
    }

    /**
     * Indemnité pour une distance annuelle.
     *
     * La tranche est déterminée par le total annuel parcouru, et la formule
     * de cette tranche s'applique à la totalité des kilomètres — ce n'est pas
     * un barème progressif par paliers.
     *
     * @return array{montant:float, coefficient:float, constante:float,
     *                tranche:string, majore:bool}|null
     */
    public static function calculer(int $annee, string $type, int $puissanceCv, bool $electrique, float $km): ?array
    {
        if ($km <= 0) {
            return null;
        }

        $ligne = Database::un(
            'SELECT * FROM bareme_km
              WHERE annee = :a AND type_vehicule = :t
                AND :cv1 BETWEEN puissance_min AND puissance_max
                AND :km1 BETWEEN tranche_min AND tranche_max
              LIMIT 1',
            ['a' => $annee, 't' => $type, 'cv1' => $puissanceCv, 'km1' => (int) round($km)]
        );

        if ($ligne === null) {
            return null;
        }

        $montant = $km * (float) $ligne['coefficient'] + (float) $ligne['constante'];
        if ($electrique) {
            $montant *= self::MAJORATION_ELECTRIQUE;
        }

        return [
            'montant'     => round($montant, 2),
            'coefficient' => (float) $ligne['coefficient'],
            'constante'   => (float) $ligne['constante'],
            'tranche'     => self::libelleTranche($ligne),
            'majore'      => $electrique,
        ];
    }

    private static function libelleTranche(array $l): string
    {
        $min = (int) $l['tranche_min'];
        $max = (int) $l['tranche_max'];
        return $max >= 999999
            ? 'au-delà de ' . number_format($min - 1, 0, ',', ' ') . ' km'
            : 'de ' . number_format($min, 0, ',', ' ') . ' à ' . number_format($max, 0, ',', ' ') . ' km';
    }

    /** Duplique un barème vers une nouvelle année, pour y reporter l'arrêté. */
    public static function dupliquer(int $source, int $cible): int
    {
        if (self::existe($cible) || !self::existe($source)) {
            return 0;
        }
        return Database::executer(
            'INSERT INTO bareme_km (annee, type_vehicule, puissance_min, puissance_max,
                                    tranche_min, tranche_max, coefficient, constante)
             SELECT :cible, type_vehicule, puissance_min, puissance_max,
                    tranche_min, tranche_max, coefficient, constante
               FROM bareme_km WHERE annee = :source',
            ['cible' => $cible, 'source' => $source]
        )->rowCount();
    }

    public static function majCoefficient(int $id, float $coefficient, float $constante): void
    {
        Database::modifier('bareme_km',
            ['coefficient' => $coefficient, 'constante' => $constante],
            'id = :i', ['i' => $id]
        );
    }
}
