<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Comptes bancaires de l'utilisateur.
 *
 * Un compte professionnel, un compte mixte, éventuellement plusieurs comptes
 * pros : les opérations sont rattachées à un compte réel plutôt qu'au libellé
 * recopié du relevé, ce qui permet de reconnaître les mouvements internes.
 */
final class CompteBancaire
{
    public const NATURES = [
        'professionnel' => 'Professionnel',
        'mixte'         => 'Mixte',
        'personnel'     => 'Personnel',
    ];

    public static function lister(int $userId, bool $actifsSeuls = false): array
    {
        return Database::tous(
            'SELECT * FROM comptes_bancaires WHERE user_id = :u'
            . ($actifsSeuls ? ' AND actif = 1' : '')
            . ' ORDER BY actif DESC, nature, libelle',
            ['u' => $userId]
        );
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un('SELECT * FROM comptes_bancaires WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    public static function creer(int $userId, array $d): int
    {
        return Database::inserer('comptes_bancaires', [
            'user_id'  => $userId,
            'libelle'  => $d['libelle'],
            'banque'   => $d['banque'] ?: null,
            'iban_fin' => $d['iban_fin'] ?: null,
            'nature'   => $d['nature'],
            'motifs'   => $d['motifs'] ?: null,
            'actif'    => 1,
            'cree_le'  => date('Y-m-d H:i:s'),
        ]);
    }

    public static function modifier(int $userId, int $id, array $d): void
    {
        Database::modifier('comptes_bancaires', [
            'libelle'  => $d['libelle'],
            'banque'   => $d['banque'] ?: null,
            'iban_fin' => $d['iban_fin'] ?: null,
            'nature'   => $d['nature'],
            'motifs'   => $d['motifs'] ?: null,
        ], 'id = :i AND user_id = :u', ['i' => $id, 'u' => $userId]);
    }

    public static function basculerActif(int $userId, int $id): void
    {
        Database::executer('UPDATE comptes_bancaires SET actif = 1 - actif WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    public static function supprimer(int $userId, int $id): void
    {
        Database::executer('DELETE FROM comptes_bancaires WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    /**
     * Retrouve le compte correspondant au libellé brut d'un relevé.
     *
     * Les exports bancaires écrivent le compte de façons variables — « Qonto
     * 4417 », « FR76...0143 », « CCP pro ». Chaque compte porte donc une liste
     * de motifs, et les quatre derniers caractères de l'IBAN servent aussi de
     * repère.
     */
    public static function reconnaitre(int $userId, ?string $libelleBrut): ?int
    {
        $brut = trim((string) $libelleBrut);
        if ($brut === '') {
            return null;
        }
        $normalise = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $brut) ?? '');

        foreach (self::lister($userId) as $c) {
            foreach (array_filter(array_map('trim', explode('|', (string) $c['motifs']))) as $motif) {
                $m = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $motif) ?? '');
                if ($m !== '' && str_contains($normalise, $m)) {
                    return (int) $c['id'];
                }
            }
            $fin = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $c['iban_fin']) ?? '');
            if ($fin !== '' && str_contains($normalise, $fin)) {
                return (int) $c['id'];
            }
            $lib = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $c['libelle']) ?? '');
            if ($lib !== '' && str_contains($normalise, $lib)) {
                return (int) $c['id'];
            }
        }
        return null;
    }

    /** Nombre d'opérations rattachées, par compte. */
    public static function statistiques(int $userId, int $exerciceId): array
    {
        $r = [];
        foreach (Database::tous(
            "SELECT compte_id, COUNT(*) AS nb,
                    SUM(CASE WHEN statut = 'nouveau' THEN 1 ELSE 0 END) AS en_attente
               FROM transactions_brutes
              WHERE user_id = :u AND exercice_id = :e AND compte_id IS NOT NULL
              GROUP BY compte_id",
            ['u' => $userId, 'e' => $exerciceId]) as $l) {
            $r[(int) $l['compte_id']] = ['nb' => (int) $l['nb'], 'en_attente' => (int) $l['en_attente']];
        }
        return $r;
    }

    /** Opérations importées sans compte identifié : à rattacher à la main. */
    public static function nbSansCompte(int $userId, int $exerciceId): int
    {
        return (int) Database::valeur(
            'SELECT COUNT(*) FROM transactions_brutes
              WHERE user_id = :u AND exercice_id = :e AND compte_id IS NULL',
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }
}
