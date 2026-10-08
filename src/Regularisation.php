<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Régularisations de fin d'exercice.
 *
 * Le BNC au réel se tient en trésorerie : une charge n'est déductible qu'une
 * fois décaissée. Certaines écritures dérogent à ce principe, au premier rang
 * desquelles les cotisations sociales obligatoires dues au 31 décembre mais
 * appelées plus tard.
 *
 * Ces écritures sont saisies à part des registres, chacune avec sa
 * justification : leur admissibilité dépend du régime et des tolérances
 * applicables, et relève d'un arbitrage avec l'AGA ou l'expert-comptable.
 * Le logiciel les enregistre et les trace, il ne se prononce pas.
 *
 * Contre-passation : une charge déduite en N réapparaît sur le relevé de N+1
 * lorsqu'elle est effectivement payée, et serait alors déduite une seconde
 * fois. L'impact est donc porté positivement sur l'exercice de saisie et
 * négativement sur le suivant, sans écriture miroir à maintenir.
 */
final class Regularisation
{
    public const SENS = [
        'charge_a_payer'     => 'Charge à payer',
        'produit_a_recevoir' => 'Produit à recevoir',
    ];

    public static function lister(int $userId, int $exerciceId): array
    {
        return Database::tous(
            'SELECT r.*, p.libelle AS poste_libelle, c.libelle AS categorie_libelle
               FROM regularisations r
               JOIN postes_2035 p ON p.code = r.poste_2035
          LEFT JOIN categories_metier c ON c.id = r.categorie_id
              WHERE r.user_id = :u AND r.exercice_id = :e
              ORDER BY r.sens, r.libelle',
            ['u' => $userId, 'e' => $exerciceId]
        );
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un('SELECT * FROM regularisations WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    /** @return array{0:?int, 1:array<int,string>} */
    public static function creer(int $userId, int $exerciceId, array $d): array
    {
        [$valide, $erreurs] = self::valider($userId, $d);
        if ($erreurs) {
            return [null, $erreurs];
        }
        $id = Database::inserer('regularisations', $valide + [
            'user_id'     => $userId,
            'exercice_id' => $exerciceId,
            'cree_le'     => date('Y-m-d H:i:s'),
        ]);
        return [$id, []];
    }

    public static function modifier(int $userId, int $id, array $d): array
    {
        [$valide, $erreurs] = self::valider($userId, $d);
        if ($erreurs) {
            return $erreurs;
        }
        Database::modifier('regularisations', $valide + ['modifie_le' => date('Y-m-d H:i:s')],
            'id = :i AND user_id = :u', ['i' => $id, 'u' => $userId]);
        return [];
    }

    public static function supprimer(int $userId, int $id): void
    {
        Database::executer('DELETE FROM regularisations WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    private static function valider(int $userId, array $d): array
    {
        $e = [];
        $sens    = isset(self::SENS[$d['sens'] ?? '']) ? $d['sens'] : 'charge_a_payer';
        $libelle = mb_substr(trim((string) ($d['libelle'] ?? '')), 0, 190);
        $montant = Montant::lire((string) ($d['montant'] ?? ''));

        $categorieId = (int) ($d['categorie_id'] ?? 0);
        $poste = (string) ($d['poste_2035'] ?? '');
        if ($categorieId > 0) {
            $poste = CategorieMetier::poste($userId, $categorieId) ?? $poste;
        }
        $sensPoste = $sens === 'charge_a_payer' ? 'depense' : 'recette';

        if ($libelle === '')                            $e[] = 'Le libellé est obligatoire.';
        if ($montant === null || $montant <= 0)         $e[] = 'Le montant doit être supérieur à zéro.';
        if (!Poste2035::existe($poste, $sensPoste))     $e[] = 'Choisir une catégorie du bon sens.';
        if (trim((string) ($d['justification'] ?? '')) === '') {
            $e[] = 'La justification est obligatoire : une régularisation déroge au principe '
                 . 'de trésorerie et doit pouvoir être expliquée en cas de contrôle.';
        }
        if ($e) {
            return [[], $e];
        }

        return [[
            'sens'          => $sens,
            'libelle'       => $libelle,
            'montant'       => $montant,
            'poste_2035'    => $poste,
            'categorie_id'  => $categorieId ?: null,
            'justification' => trim((string) $d['justification']),
            'contrepasser'  => !empty($d['contrepasser']) ? 1 : 0,
        ], []];
    }

    /**
     * Impact des régularisations sur un exercice, par poste 2035.
     *
     * Deux apports se cumulent : les régularisations saisies sur l'exercice,
     * comptées positivement, et la contre-passation de celles de l'exercice
     * précédent, comptée négativement — sans quoi une charge déduite en N
     * serait déduite une seconde fois en N+1 au moment de son paiement.
     *
     * @return array{depenses:array<string,float>, recettes:array<string,float>,
     *                total_depenses:float, total_recettes:float, lignes:array}
     */
    public static function impact(int $userId, int $exerciceId, int $annee): array
    {
        $depenses = $recettes = [];
        $lignes = [];

        foreach (self::lister($userId, $exerciceId) as $r) {
            $m = (float) $r['montant'];
            if ($r['sens'] === 'charge_a_payer') {
                $depenses[$r['poste_2035']] = ($depenses[$r['poste_2035']] ?? 0) + $m;
            } else {
                $recettes[$r['poste_2035']] = ($recettes[$r['poste_2035']] ?? 0) + $m;
            }
            $lignes[] = $r + ['origine' => 'exercice', 'signe' => 1];
        }

        // Contre-passation de l'exercice précédent
        $precedent = Database::un(
            'SELECT id FROM exercices WHERE user_id = :u AND annee = :a',
            ['u' => $userId, 'a' => $annee - 1]
        );
        if ($precedent !== null) {
            foreach (self::lister($userId, (int) $precedent['id']) as $r) {
                if ((int) $r['contrepasser'] !== 1) {
                    continue;
                }
                $m = (float) $r['montant'];
                if ($r['sens'] === 'charge_a_payer') {
                    $depenses[$r['poste_2035']] = ($depenses[$r['poste_2035']] ?? 0) - $m;
                } else {
                    $recettes[$r['poste_2035']] = ($recettes[$r['poste_2035']] ?? 0) - $m;
                }
                $lignes[] = $r + ['origine' => 'contrepassation', 'signe' => -1];
            }
        }

        return [
            'depenses'       => array_map(static fn($v) => round($v, 2), $depenses),
            'recettes'       => array_map(static fn($v) => round($v, 2), $recettes),
            'total_depenses' => round(array_sum($depenses), 2),
            'total_recettes' => round(array_sum($recettes), 2),
            'lignes'         => $lignes,
        ];
    }

    public static function nb(int $userId, int $exerciceId): int
    {
        return (int) Database::valeur(
            'SELECT COUNT(*) FROM regularisations WHERE user_id = :u AND exercice_id = :e',
            ['u' => $userId, 'e' => $exerciceId], 0
        );
    }
}
