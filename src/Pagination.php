<?php
declare(strict_types=1);

namespace Bnc;

/** Découpage d'une liste en pages, et rendu du sélecteur. */
final class Pagination
{
    public const PAR_PAGE = 50;

    public static function page(): int
    {
        return max(1, (int) ($_GET['page'] ?? 1));
    }

    public static function decalage(int $page, int $parPage = self::PAR_PAGE): int
    {
        return ($page - 1) * $parPage;
    }

    public static function nbPages(int $total, int $parPage = self::PAR_PAGE): int
    {
        return max(1, (int) ceil($total / $parPage));
    }

    /** Lien conservant les filtres en cours, en ne changeant que la page. */
    public static function lien(int $page): string
    {
        $q = $_GET;
        $q['page'] = $page;
        return '?' . http_build_query($q);
    }

    /**
     * Numéros à afficher : début, fin, et une fenêtre autour de la page
     * courante. 0 marque une coupure.
     *
     * @return array<int,int>
     */
    public static function fenetre(int $page, int $nbPages, int $rayon = 2): array
    {
        if ($nbPages <= 7) {
            return range(1, $nbPages);
        }
        $pages = [1];
        $debut = max(2, $page - $rayon);
        $fin   = min($nbPages - 1, $page + $rayon);

        if ($debut > 2) {
            $pages[] = 0;
        }
        for ($i = $debut; $i <= $fin; $i++) {
            $pages[] = $i;
        }
        if ($fin < $nbPages - 1) {
            $pages[] = 0;
        }
        $pages[] = $nbPages;
        return $pages;
    }
}
