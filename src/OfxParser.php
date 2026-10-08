<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Lecture d'un relevé OFX / QFX.
 *
 * L'OFX 1.x est du SGML : les balises ne sont pas fermées et le document
 * n'est pas du XML valide. On ne peut donc pas s'appuyer sur un parseur XML,
 * d'où une lecture par expressions régulières sur les blocs <STMTTRN>.
 * L'OFX 2.x, lui, est du XML, mais la même lecture fonctionne pour les deux.
 */
final class OfxParser
{
    public static function estOfx(string $contenu): bool
    {
        $debut = mb_strtoupper(mb_substr(ltrim($contenu), 0, 400));
        return str_contains($debut, 'OFXHEADER')
            || str_contains($debut, '<OFX>')
            || str_contains($debut, '<STMTTRN>');
    }

    /**
     * @return array{lignes:array<int,array>, ignorees:int, compte:?string}
     */
    public static function analyser(string $ofx): array
    {
        $ofx = self::normaliserEncodage($ofx);

        $compte = self::baliseSimple($ofx, 'ACCTID');
        $lignes = [];
        $ignorees = 0;

        if (!preg_match_all('#<STMTTRN>(.*?)</STMTTRN>#is', $ofx, $blocs)) {
            // Certains exports omettent la balise fermante : on découpe alors
            // sur chaque ouverture de transaction.
            $morceaux = preg_split('#<STMTTRN>#i', $ofx);
            $blocs = [1 => array_slice($morceaux ?: [], 1)];
        }

        foreach ($blocs[1] as $bloc) {
            $ligne = self::lireTransaction($bloc, $compte);
            if ($ligne === null) {
                $ignorees++;
                continue;
            }
            $lignes[] = $ligne;
        }

        return ['lignes' => $lignes, 'ignorees' => $ignorees, 'compte' => $compte];
    }

    private static function lireTransaction(string $bloc, ?string $compte): ?array
    {
        $date = self::baliseSimple($bloc, 'DTPOSTED') ?? self::baliseSimple($bloc, 'DTUSER');
        $montantBrut = self::baliseSimple($bloc, 'TRNAMT');

        if ($date === null || $montantBrut === null) {
            return null;
        }

        $dateIso = self::lireDateOfx($date);
        $montant = Montant::lire($montantBrut);

        if ($dateIso === null || $montant === null || abs($montant) < 0.005) {
            return null;
        }

        // NAME est court et souvent tronqué ; MEMO porte le libellé complet.
        $nom  = self::baliseSimple($bloc, 'NAME') ?? '';
        $memo = self::baliseSimple($bloc, 'MEMO') ?? '';
        $libelle = trim($memo !== '' && mb_strlen($memo) > mb_strlen($nom) ? $memo : $nom);
        if ($libelle === '' ) {
            $libelle = trim($nom . ' ' . $memo);
        }
        if ($libelle === '') {
            $libelle = 'Opération du ' . $dateIso;
        }

        return [
            'date_operation' => $dateIso,
            'libelle'        => mb_substr(self::decoderEntites($libelle), 0, 255),
            'montant'        => $montant,
            'compte'         => $compte !== null ? mb_substr($compte, 0, 120) : null,
            'external_id'    => mb_substr((string) (self::baliseSimple($bloc, 'FITID') ?? ''), 0, 64) ?: null,
        ];
    }

    /** Valeur d'une balise SGML non fermée : <TAG>valeur jusqu'au prochain < ou saut de ligne. */
    private static function baliseSimple(string $sujet, string $balise): ?string
    {
        if (preg_match('#<' . $balise . '>\s*([^<\r\n]*)#i', $sujet, $m)) {
            $v = trim($m[1]);
            return $v !== '' ? $v : null;
        }
        return null;
    }

    /** Date OFX : AAAAMMJJ, éventuellement suivie de l'heure et d'un fuseau entre crochets. */
    private static function lireDateOfx(string $brut): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($brut), $m)) {
            if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
            }
        }
        return null;
    }

    private static function decoderEntites(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function normaliserEncodage(string $ofx): string
    {
        if (str_starts_with($ofx, "\xEF\xBB\xBF")) {
            $ofx = substr($ofx, 3);
        }
        // L'en-tête OFX 1.x déclare souvent CHARSET:1252.
        $enTete = mb_strtoupper(substr($ofx, 0, 400));
        $latin  = str_contains($enTete, '1252') || str_contains($enTete, 'ISO-8859-1');

        if ($latin || !mb_check_encoding($ofx, 'UTF-8')) {
            $converti = @mb_convert_encoding($ofx, 'UTF-8', 'Windows-1252');
            if (is_string($converti) && $converti !== '') {
                $ofx = $converti;
            }
        }
        return str_replace(["\r\n", "\r"], "\n", $ofx);
    }
}
