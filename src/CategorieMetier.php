<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Nomenclature métier.
 *
 * Les 29 postes de la 2035-A sont exacts mais opaques : un formateur ne sait
 * pas que sa RC professionnelle relève de BP ni sa CFE de BS. Cette couche
 * donne des libellés parlants, chacun adossé à son poste officiel, et sert
 * aussi à pré-catégoriser les opérations importées.
 *
 * Les catégories livrées avec le logiciel ont user_id à NULL ; chacun peut
 * en ajouter, sans modifier les communes.
 */
final class CategorieMetier
{
    /** @return array<int,array> catégories communes et propres à l'utilisateur */
    /**
     * Les catégories qui portent un poste donné. Une catégorie a toujours UN poste ; un poste, lui, peut avoir
     * plusieurs catégories (et c'est pourquoi choisir un poste ne désigne pas toujours une catégorie).
     *
     * @param list<array<string,mixed>> $categories
     * @return list<array<string,mixed>>
     */
    public static function ayantPoste(array $categories, string $poste): array
    {
        return array_values(array_filter($categories, static fn(array $c) => (string) $c['poste_2035'] === $poste));
    }

    /**
     * L'identifiant de la catégorie d'un poste, quand il n'y en a qu'UNE : le lien est alors sans ambiguïté, ce n'est
     * pas une devinette. Avec zéro ou plusieurs, null : on ne choisit pas à la place de l'utilisateur.
     */
    public static function uniquePourPoste(int $userId, string $poste, string $sens): ?int
    {
        if ($poste === '') {
            return null;
        }
        $c = self::ayantPoste(self::lister($userId, $sens), $poste);
        return count($c) === 1 ? (int) $c[0]['id'] : null;
    }

    public static function lister(int $userId, ?string $sens = null, bool $activesSeules = true): array
    {
        $sql = 'SELECT c.*, p.libelle AS poste_libelle
                  FROM categories_metier c
                  JOIN postes_2035 p ON p.code = c.poste_2035
                 WHERE (c.user_id IS NULL OR c.user_id = :u)';
        $params = ['u' => $userId];

        if ($sens !== null) {
            $sql .= ' AND c.sens = :s';
            $params['s'] = $sens;
        }
        if ($activesSeules) {
            $sql .= ' AND c.actif = 1';
        }
        return Database::tous($sql . ' ORDER BY c.sens, c.ordre, c.libelle', $params);
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un(
            'SELECT * FROM categories_metier WHERE id = :i AND (user_id IS NULL OR user_id = :u)',
            ['i' => $id, 'u' => $userId]
        );
    }

    /** Poste 2035 correspondant, ou null si la catégorie n'existe pas. */
    public static function poste(int $userId, int $id): ?string
    {
        $c = self::trouver($userId, $id);
        return $c['poste_2035'] ?? null;
    }

    /**
     * Devine la catégorie d'une opération d'après son libellé bancaire.
     *
     * Sert de filet après les règles personnelles : sans avoir rien
     * configuré, un prélèvement URSSAF tombe déjà en charges sociales.
     * Le mot-clé le plus long l'emporte, pour qu'une correspondance
     * précise prime sur une générique.
     */
    public static function deviner(int $userId, string $libelle, string $sens): ?array
    {
        $hay = mb_strtoupper(self::sansAccents($libelle));
        $meilleure = null;
        $longueur = 0;

        foreach (self::lister($userId, $sens) as $c) {
            foreach (array_filter(array_map('trim', explode('|', (string) $c['mots_cles']))) as $mot) {
                $m = mb_strtoupper(self::sansAccents($mot));
                if ($m !== '' && str_contains($hay, $m) && mb_strlen($m) > $longueur) {
                    $meilleure = $c;
                    $longueur = mb_strlen($m);
                }
            }
        }
        return $meilleure;
    }

    public static function creer(int $userId, array $d): array
    {
        $e = [];
        $libelle = mb_substr(trim((string) ($d['libelle'] ?? '')), 0, 160);
        $sens    = in_array($d['sens'] ?? '', ['recette', 'depense'], true) ? $d['sens'] : 'depense';
        $poste   = (string) ($d['poste_2035'] ?? '');

        if ($libelle === '')                        $e[] = 'Le libellé est obligatoire.';
        if (!Poste2035::existe($poste, $sens))      $e[] = 'Choisir un poste 2035 du bon sens.';
        if ($e) {
            return $e;
        }

        Database::inserer('categories_metier', [
            'user_id'    => $userId,
            'sens'       => $sens,
            'libelle'    => $libelle,
            'poste_2035' => $poste,
            'mots_cles'  => mb_substr(trim((string) ($d['mots_cles'] ?? '')), 0, 255) ?: null,
            'aide'       => mb_substr(trim((string) ($d['aide'] ?? '')), 0, 255) ?: null,
            'ordre'      => 500,
            'actif'      => 1,
        ]);
        return [];
    }

    /** Une catégorie livrée avec le logiciel ne se supprime pas : elle se désactive. */
    public static function basculerActif(int $userId, int $id): string
    {
        $c = self::trouver($userId, $id);
        if ($c === null) {
            return 'Catégorie introuvable.';
        }
        if ($c['user_id'] === null) {
            // Désactiver une catégorie commune ne vaut que pour cet utilisateur :
            // on en crée une copie masquée plutôt que de toucher à la commune.
            return 'Les catégories livrées avec le logiciel ne peuvent pas être désactivées, '
                 . 'seulement complétées par les vôtres.';
        }
        Database::executer('UPDATE categories_metier SET actif = 1 - actif WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
        return '';
    }

    public static function supprimer(int $userId, int $id): string
    {
        $c = self::trouver($userId, $id);
        if ($c === null || $c['user_id'] === null) {
            return 'Seules vos propres catégories peuvent être supprimées.';
        }
        Database::executer('DELETE FROM categories_metier WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
        return '';
    }

    /** Catégories groupées par poste 2035, pour montrer le mapping. */
    public static function parPoste(int $userId, string $sens): array
    {
        $r = [];
        foreach (self::lister($userId, $sens) as $c) {
            $r[$c['poste_2035']][] = $c;
        }
        return $r;
    }

    private static function sansAccents(string $s): string
    {
        return strtr($s, [
            'à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','î'=>'i','ï'=>'i',
            'ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
            'À'=>'A','Â'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Î'=>'I','Ô'=>'O','Û'=>'U','Ç'=>'C',
        ]);
    }
}
