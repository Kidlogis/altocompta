<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Lecture des montants saisis ou importés.
 * Accepte « 1 440,00 », « 1.440,00 », « 1440.00 », « -1 234,56 € ».
 */
final class Montant
{
    public static function lire(string $brut): ?float
    {
        $s = trim($brut);
        if ($s === '') {
            return null;
        }

        $negatif = str_contains($s, '-') || str_contains($s, '−')
                || (str_starts_with($s, '(') && str_ends_with($s, ')'));

        $s = preg_replace('/[^0-9.,]/u', '', $s) ?? '';
        if ($s === '') {
            return null;
        }

        $dernierePoint  = strrpos($s, '.');
        $derniereVirgule = strrpos($s, ',');

        if ($dernierePoint !== false && $derniereVirgule !== false) {
            // Le séparateur décimal est le dernier des deux.
            if ($derniereVirgule > $dernierePoint) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($derniereVirgule !== false) {
            // Virgule seule : décimale si 1 ou 2 chiffres derrière, sinon séparateur de milliers.
            $apres = strlen($s) - $derniereVirgule - 1;
            $s = $apres <= 2 ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        } elseif ($dernierePoint !== false) {
            $apres = strlen($s) - $dernierePoint - 1;
            if ($apres > 2) {
                $s = str_replace('.', '', $s);
            }
        }

        if (!is_numeric($s)) {
            return null;
        }
        $v = round((float) $s, 2);
        return $negatif ? -abs($v) : $v;
    }

    /**
     * Lecture d'un décimal quelconque, sans l'heuristique des milliers.
     *
     * lire() suppose des montants en euros : une virgule suivie de plus de
     * deux chiffres y est prise pour un séparateur de milliers, ce qui est
     * juste pour « 1,250 » euros mais faux pour un coefficient « 0,6360 ».
     * Cette méthode traite virgule et point comme le séparateur décimal,
     * sans exception.
     */
    public static function lireDecimal(string $brut): ?float
    {
        $s = trim($brut);
        if ($s === '') {
            return null;
        }
        $negatif = str_contains($s, '-') || str_contains($s, '−');

        $s = preg_replace('/[^0-9.,]/u', '', $s) ?? '';
        $s = str_replace(',', '.', $s);

        // Plusieurs points : seul le dernier est décimal, les autres séparaient les milliers.
        if (substr_count($s, '.') > 1) {
            $dernier = strrpos($s, '.');
            $s = str_replace('.', '', substr($s, 0, $dernier)) . '.' . substr($s, $dernier + 1);
        }

        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        $v = (float) $s;
        return $negatif ? -abs($v) : $v;
    }

    /** Date d'une source hétérogène vers Y-m-d. */
    public static function lireDate(string $brut): ?string
    {
        $s = trim($brut);
        if ($s === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd/m/y', 'd-m-Y', 'Y/m/d', 'd.m.Y', 'Ymd'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!' . $f, $s);
            $err = \DateTimeImmutable::getLastErrors();
            if ($d !== false && empty($err['warning_count']) && empty($err['error_count'])) {
                return $d->format('Y-m-d');
            }
        }
        // Formats ISO avec heure
        try {
            return (new \DateTimeImmutable($s))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
