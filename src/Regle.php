<?php
declare(strict_types=1);

namespace Bnc;

/** Règles d'affectation automatique appliquées aux opérations importées. */
final class Regle
{
    public static function lister(int $userId): array
    {
        return Database::tous(
            'SELECT * FROM regles_affectation WHERE user_id = :u ORDER BY priorite, id',
            ['u' => $userId]
        );
    }

    public static function actives(int $userId): array
    {
        return Database::tous(
            'SELECT * FROM regles_affectation WHERE user_id = :u AND actif = 1 ORDER BY priorite, id',
            ['u' => $userId]
        );
    }

    public static function creer(int $userId, array $d): int
    {
        return Database::inserer('regles_affectation', [
            'user_id'      => $userId,
            'priorite'     => $d['priorite'] ?? 100,
            'motif'        => $d['motif'],
            'type_match'   => $d['type_match'] ?? 'contient',
            'sens_montant' => $d['sens_montant'] ?? 'tous',
            'cible_type'   => $d['cible_type'],
            'poste_2035'   => $d['poste_2035'] ?: null,
            'actif'        => 1,
            'cree_le'      => date('Y-m-d H:i:s'),
        ]);
    }

    public static function supprimer(int $userId, int $id): void
    {
        Database::executer('DELETE FROM regles_affectation WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    public static function basculer(int $userId, int $id): void
    {
        Database::executer(
            'UPDATE regles_affectation SET actif = 1 - actif WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]
        );
    }

    /**
     * Première règle applicable à une opération, ou null.
     * Les règles sont évaluées par priorité croissante.
     */
    public static function appliquer(array $regles, array $tx): ?array
    {
        $montant = (float) $tx['montant'];
        $libelle = (string) $tx['libelle'];

        foreach ($regles as $r) {
            if ($r['sens_montant'] === 'credit' && $montant <= 0) continue;
            if ($r['sens_montant'] === 'debit'  && $montant >= 0) continue;

            if (self::correspond($r, $libelle, $montant)) {
                return $r;
            }
        }
        return null;
    }

    private static function correspond(array $regle, string $libelle, float $montant): bool
    {
        $motif = (string) $regle['motif'];
        $hay   = mb_strtoupper($libelle);
        $need  = mb_strtoupper($motif);

        return match ($regle['type_match']) {
            'contient'      => $need !== '' && str_contains($hay, $need),
            'commence'      => $need !== '' && str_starts_with($hay, $need),
            'montant_exact' => abs(abs($montant) - abs((float) $motif)) < 0.005,
            'regex'         => self::regex($motif, $libelle),
            default         => false,
        };
    }

    /** Une expression invalide saisie par l'utilisateur ne doit pas casser la synchronisation. */
    private static function regex(string $motif, string $sujet): bool
    {
        $expr = '/' . str_replace('/', '\/', $motif) . '/iu';
        set_error_handler(static fn() => true);
        $ok = @preg_match($expr, $sujet);
        restore_error_handler();
        return $ok === 1;
    }

    public static function valider(string $type, string $motif): string
    {
        if (trim($motif) === '') {
            return 'Le motif ne peut pas être vide.';
        }
        if ($type === 'regex') {
            set_error_handler(static fn() => true);
            $ok = @preg_match('/' . str_replace('/', '\/', $motif) . '/iu', '');
            restore_error_handler();
            if ($ok === false) {
                return 'Expression régulière invalide.';
            }
        }
        if ($type === 'montant_exact' && Montant::lire($motif) === null) {
            return 'Montant invalide.';
        }
        return '';
    }
}
