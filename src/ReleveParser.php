<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Lecture d'un relevé bancaire CSV, quelle qu'en soit la provenance.
 *
 * Le format exact renvoyé par Altoviz n'étant pas figé, les colonnes sont
 * reconnues par leur intitulé plutôt que par leur position. Le même parseur
 * sert donc à l'export Altoviz et aux CSV téléchargés depuis une banque.
 */
final class ReleveParser
{
    /** Intitulés reconnus, par rôle. Comparaison sans accents ni casse. */
    private const SYNONYMES = [
        'date'    => ['date', 'date operation', 'date de loperation', 'date valeur', 'date comptable',
                      'transaction date', 'operation date', 'booking date', 'dateop'],
        'libelle' => ['libelle', 'libelle operation', 'description', 'label', 'intitule', 'motif',
                      'communication', 'nature', 'reference', 'details', 'memo', 'name'],
        'montant' => ['montant', 'amount', 'valeur', 'somme', 'montant eur', 'montant operation'],
        'credit'  => ['credit', 'recette', 'encaissement', 'entree', 'money in'],
        'debit'   => ['debit', 'depense', 'decaissement', 'sortie', 'money out'],
        'compte'  => ['compte', 'account', 'iban', 'numero de compte', 'compte bancaire',
                      'bank account', 'bankaccount', 'account name'],
        'id'      => ['id', 'identifiant', 'transaction id', 'reference unique', 'operation id', 'uid'],
    ];

    /**
     * @return array{lignes:array<int,array>, colonnes:array<string,int>, ignorees:int}
     */
    public static function analyser(string $csv): array
    {
        $csv = self::normaliserEncodage($csv);
        $sep = self::detecterSeparateur($csv);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $entete = null;
        $colonnes = [];
        $lignes = [];
        $ignorees = 0;

        while (($cells = fgetcsv($handle, 0, $sep, '"', '\\')) !== false) {
            if ($cells === [null] || $cells === false) {
                continue;
            }
            $cells = array_map(static fn($c) => is_string($c) ? trim($c) : '', $cells);
            if (implode('', $cells) === '') {
                continue;
            }

            if ($entete === null) {
                $entete = $cells;
                $colonnes = self::mapperColonnes($cells);
                continue;
            }

            $ligne = self::lireLigne($cells, $colonnes);
            if ($ligne === null) {
                $ignorees++;
                continue;
            }
            $lignes[] = $ligne;
        }
        fclose($handle);

        return ['lignes' => $lignes, 'colonnes' => $colonnes, 'ignorees' => $ignorees];
    }

    /** @return array<string,int> rôle => index de colonne */
    private static function mapperColonnes(array $entete): array
    {
        $map = [];
        foreach ($entete as $i => $titre) {
            $t = self::clef($titre);
            if ($t === '') {
                continue;
            }
            foreach (self::SYNONYMES as $role => $variantes) {
                if (isset($map[$role])) {
                    continue;
                }
                foreach ($variantes as $v) {
                    if ($t === $v || str_starts_with($t, $v)) {
                        $map[$role] = $i;
                        break 2;
                    }
                }
            }
        }
        return $map;
    }

    private static function lireLigne(array $cells, array $col): ?array
    {
        $get = static fn(string $role) => isset($col[$role]) ? ($cells[$col[$role]] ?? '') : '';

        $date = Montant::lireDate($get('date'));
        if ($date === null) {
            return null;
        }

        // Colonne montant unique, ou paire crédit / débit.
        $montant = null;
        if (isset($col['montant'])) {
            $montant = Montant::lire($get('montant'));
        }
        if ($montant === null && (isset($col['credit']) || isset($col['debit']))) {
            $c = Montant::lire($get('credit'));
            $d = Montant::lire($get('debit'));
            if ($c !== null && abs($c) > 0)      $montant = abs($c);
            elseif ($d !== null && abs($d) > 0)  $montant = -abs($d);
        }
        if ($montant === null || abs($montant) < 0.005) {
            return null;
        }

        $libelle = trim($get('libelle'));
        if ($libelle === '') {
            $libelle = 'Opération du ' . $date;
        }

        return [
            'date_operation' => $date,
            'libelle'        => mb_substr($libelle, 0, 255),
            'montant'        => $montant,
            'compte'         => mb_substr(trim($get('compte')), 0, 120) ?: null,
            'external_id'    => mb_substr(trim($get('id')), 0, 64) ?: null,
        ];
    }

    private static function detecterSeparateur(string $csv): string
    {
        $premiere = strtok($csv, "\n") ?: '';
        $scores = [];
        foreach ([';', ',', "\t", '|'] as $s) {
            $scores[$s] = substr_count($premiere, $s);
        }
        arsort($scores);
        $meilleur = array_key_first($scores);
        return $scores[$meilleur] > 0 ? (string) $meilleur : ';';
    }

    private static function normaliserEncodage(string $csv): string
    {
        // BOM UTF-8
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            $csv = substr($csv, 3);
        }
        if (!mb_check_encoding($csv, 'UTF-8')) {
            $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        }
        return str_replace(["\r\n", "\r"], "\n", $csv);
    }

    /** Intitulé de colonne réduit : minuscules, sans accents ni ponctuation. */
    private static function clef(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
        ]);
        return trim(preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '');
    }
}
