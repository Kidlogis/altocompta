<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Client de l'API publique de l'Urssaf (mon-entreprise.urssaf.fr).
 *
 * Le service expose les calculs de ses simulateurs : POST /api/v1/evaluate reçoit une « situation »
 * (des règles du modèle social valorisées) et une liste d'expressions à évaluer. Les règles sont
 * celles de l'Urssaf, publiées en open source (paquet npm modele-social, langage publicodes) et à
 * jour des barèmes de l'année : on ne tient aucun taux ici.
 *
 * Les noms de règles ont été vérifiés en exécutant le modèle publié (version 11.1.0) sur des cas
 * de BNC en déclaration contrôlée. Ils ne sont pas garantis stables : l'Urssaf prévient que ses
 * règles « expérimentales » peuvent être renommées sans changement de version majeure, et
 * l'exemple de sa propre documentation en cite déjà un qui n'existe plus. Une règle disparue ou
 * une situation refusée produit donc un message précis, jamais un chiffre inventé.
 *
 * Ce qui part vers l'Urssaf : des montants et le profil du foyer fiscal. Ni nom, ni SIRET, ni adresse.
 */
final class Urssaf
{
    public const BASE_DEFAUT = 'https://mon-entreprise.urssaf.fr';
    public const CHEMIN      = '/api/v1/evaluate';

    /** Valeurs acceptées pour la situation familiale, telles que le modèle les écrit. */
    public const FAMILLES = ['célibataire', 'couple', 'veuf'];

    /** Sorties demandées : nom interne => règle du modèle social. */
    public const SORTIES = [
        'cotisations'            => 'dirigeant . indépendant . cotisations et contributions',
        'maladie'                => 'dirigeant . indépendant . cotisations et contributions . maladie',
        'indemnites_journalieres'=> 'dirigeant . indépendant . cotisations et contributions . indemnités journalières maladie',
        'retraite_base'          => 'dirigeant . indépendant . cotisations et contributions . retraite de base',
        'retraite_complementaire'=> 'dirigeant . indépendant . cotisations et contributions . retraite complémentaire',
        'invalidite_deces'       => 'dirigeant . indépendant . cotisations et contributions . invalidité et décès',
        'csg_crds'               => 'dirigeant . indépendant . cotisations et contributions . CSG-CRDS',
        'formation'              => 'dirigeant . indépendant . cotisations et contributions . formation professionnelle',
        'allocations_familiales' => 'dirigeant . indépendant . cotisations et contributions . allocations familiales',
        'resultat_fiscal'        => 'entreprise . résultat fiscal',
        'impot'                  => 'impôt . montant',
    ];

    /** Sorties sans lesquelles l'estimation n'a pas de sens. */
    public const INDISPENSABLES = ['cotisations', 'resultat_fiscal', 'impot'];

    /** Libellés du détail des cotisations, dans l'ordre d'affichage. */
    public const DETAIL = [
        'maladie'                 => 'Maladie-maternité',
        'indemnites_journalieres' => 'Indemnités journalières',
        'retraite_base'           => 'Retraite de base',
        'retraite_complementaire' => 'Retraite complémentaire',
        'invalidite_deces'        => 'Invalidité-décès',
        'csg_crds'                => 'CSG-CRDS',
        'formation'               => 'Formation professionnelle',
        'allocations_familiales'  => 'Allocations familiales',
    ];

    /**
     * Situation à envoyer pour un indépendant en BNC, EI soumise à l'IR, déclaration contrôlée,
     * profession libérale non réglementée.
     *
     * Les réglages qu'on ne demande pas à l'utilisateur sont posés explicitement plutôt que laissés
     * au défaut du simulateur : un défaut silencieux est une hypothèse que personne ne voit.
     * Le montant d'impôt exige la méthode « barème standard » — la formule de l'administration ;
     * avec le « taux neutre » de la retenue à la source, il sortirait à zéro.
     *
     * @param array{recettes:float, charges:float, debut_activite:?string, famille:string,
     *              enfants:int, parent_isole:bool, autres_revenus:float} $e
     * @return array<string,string|int>
     */
    public static function situation(array $e): array
    {
        $famille = in_array($e['famille'], self::FAMILLES, true) ? $e['famille'] : 'célibataire';
        $enfants = max(0, min(20, (int) $e['enfants']));
        // « Parent isolé » n'a de sens qu'avec un enfant et sans conjoint.
        $isole = !empty($e['parent_isole']) && $enfants > 0 && $famille !== 'couple';
        $euros = static fn(float $v): string => (int) round($v) . ' €/an';

        $s = [
            'dirigeant'                                                   => "'indépendant'",
            'entreprise . catégorie juridique'                            => "'EI'",
            'entreprise . catégorie juridique . EI . auto-entrepreneur'   => 'non',
            'entreprise . imposition'                                     => "'IR'",
            'entreprise . imposition . IR . type de bénéfices'            => "'BNC'",
            'entreprise . imposition . régime'                            => "'déclaration contrôlée'",
            'entreprise . activité . nature'                              => "'libérale'",
            'entreprise . activité . nature . libérale . réglementée'     => 'non',
            'établissement . commune . département . outre-mer'           => 'non',
            'dirigeant . indépendant . PL . option régime général'        => 'non',
            'dirigeant . indépendant . cotisations facultatives'          => 'non',
            'situation personnelle . RSA'                                 => 'non',
            'impôt . foyer fiscal . situation de famille'                 => "'" . $famille . "'",
            'impôt . foyer fiscal . enfants à charge'                     => $enfants,
            'impôt . foyer fiscal . parent isolé'                         => $isole ? 'oui' : 'non',
            'impôt . foyer fiscal . revenu imposable . autres revenus imposables' => $euros(max(0.0, (float) $e['autres_revenus'])),
            'impôt . méthode de calcul'                                   => "'barème standard'",
            "entreprise . chiffre d'affaires"                             => $euros(max(0.0, (float) $e['recettes'])),
            'entreprise . charges'                                        => $euros(max(0.0, (float) $e['charges'])),
        ];

        // La date de début d'activité commande le régime des deux premières années.
        if (!empty($e['debut_activite']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $e['debut_activite'], $m)) {
            $s['entreprise . date de création'] = $m[3] . '/' . $m[2] . '/' . $m[1];
        }
        return $s;
    }

    /**
     * Interprète une réponse de l'API.
     *
     * @return array{ok:bool, valeurs:array<string,?float>, manquantes:list<string>,
     *               avertissements:list<string>, erreur:?string}
     */
    public static function lireReponse(int $http, string $corps): array
    {
        $vide = ['ok' => false, 'valeurs' => [], 'manquantes' => [], 'avertissements' => [], 'erreur' => null];
        $echec = static fn(string $m) => ['erreur' => $m] + $vide;

        if ($http === 429) {
            return $echec('L\'Urssaf limite le nombre d\'appels : réessayer dans quelques minutes.');
        }
        $d = json_decode($corps, true);

        // Un 5xx dont le corps explique l'erreur (une règle inconnue, par exemple) doit être lu :
        // « service indisponible » en cacherait la vraie cause. Seule une réponse inexploitable
        // — page d'erreur d'un serveur intermédiaire, corps vide — est traitée en panne.
        $explicite = is_array($d) && (isset($d['error']) || isset($d['situationError']));
        if ($http >= 500 && !$explicite) {
            return $echec('Le service de l\'Urssaf est indisponible (code ' . $http . ') : réessayer plus tard.');
        }
        if (!is_array($d)) {
            return $echec('Réponse illisible de l\'Urssaf (code ' . $http . ').');
        }

        // Erreur de validation de la requête : une liste d'objets {message, dataPath…}.
        if ($http === 400 || (array_is_list($d) && isset($d[0]['message']))) {
            $m = array_filter(array_map(static fn($x) => is_array($x) ? ($x['message'] ?? '') : '', $d));
            return $echec('Requête refusée par l\'Urssaf : ' . ($m ? implode(' ; ', array_slice($m, 0, 3)) : 'format invalide') . '.');
        }
        if (isset($d['situationError'])) {
            $m = is_array($d['situationError']) ? ($d['situationError']['message'] ?? '') : (string) $d['situationError'];
            return $echec('L\'Urssaf n\'a pas accepté la situation envoyée — son modèle a sans doute changé : '
                . self::court((string) $m));
        }
        if (isset($d['error'])) {
            $m = is_array($d['error']) ? ($d['error']['message'] ?? '') : (string) $d['error'];
            return $echec('L\'Urssaf signale une erreur — une règle a sans doute été renommée : ' . self::court((string) $m));
        }
        if ($http !== 200 || !isset($d['evaluate']) || !is_array($d['evaluate'])) {
            return $echec('Réponse inattendue de l\'Urssaf (code ' . $http . ').');
        }

        $cles = array_keys(self::SORTIES);
        if (count($d['evaluate']) !== count($cles)) {
            return $echec('L\'Urssaf a répondu à ' . count($d['evaluate']) . ' expression(s) sur ' . count($cles) . ' demandées.');
        }

        $valeurs = [];
        $manquantes = [];
        foreach (array_values($d['evaluate']) as $i => $r) {
            $v = is_array($r) ? ($r['nodeValue'] ?? null) : null;
            $valeurs[$cles[$i]] = is_numeric($v) ? (float) $v : null;
            $mv = is_array($r) ? ($r['missingVariables'] ?? []) : [];
            foreach (is_array($mv) ? (array_is_list($mv) ? $mv : array_keys($mv)) : [] as $nom) {
                $manquantes[(string) $nom] = true;
            }
        }
        foreach (self::INDISPENSABLES as $cle) {
            if ($valeurs[$cle] === null) {
                return $echec('Le simulateur n\'a pas pu calculer « ' . self::SORTIES[$cle] . ' » : '
                    . 'il lui manque une donnée, ou la règle a changé.');
            }
        }

        // Les avertissements de règles « expérimentales » concernent ceux qui écrivent le modèle,
        // pas la personne qui lit l'estimation.
        $avert = [];
        foreach ($d['warnings'] ?? [] as $w) {
            $t = trim((string) (is_array($w) ? ($w['message'] ?? '') : $w));
            if ($t !== '' && !preg_match('/exp[ée]rimental|taggu/iu', $t)) {
                $avert[$t] = true;
            }
        }

        return ['ok' => true, 'valeurs' => $valeurs, 'manquantes' => array_keys($manquantes),
                'avertissements' => array_slice(array_keys($avert), 0, 3), 'erreur' => null];
    }

    private static function court(string $m): string
    {
        $m = trim(preg_replace('/\s+/u', ' ', $m) ?? '');
        return $m === '' ? '(sans détail)' : mb_substr($m, 0, 240);
    }

    /**
     * Envoie la situation à l'Urssaf et interprète la réponse.
     *
     * @param array<string,string|int> $situation
     * @return array{ok:bool, valeurs:array<string,?float>, manquantes:list<string>,
     *               avertissements:list<string>, erreur:?string}
     */
    public static function evaluer(array $situation): array
    {
        $url = rtrim((string) Config::get('urssaf.base_url', self::BASE_DEFAUT), '/') . self::CHEMIN;
        $corps = (string) json_encode(
            ['situation' => $situation, 'expressions' => array_values(self::SORTIES)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $delai = (int) Config::get('urssaf.timeout', 20);

        try {
            [$http, $reponse] = function_exists('curl_init') ? self::viaCurl($url, $corps, $delai) : self::viaStream($url, $corps, $delai);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'valeurs' => [], 'manquantes' => [], 'avertissements' => [], 'erreur' => $e->getMessage()];
        }
        return self::lireReponse($http, $reponse);
    }

    /** @return array{0:int, 1:string} */
    private static function viaCurl(string $url, string $corps, int $delai): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corps,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => $delai,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'AltoCompta/1.0',
        ]);
        $reponse = curl_exec($ch);
        if ($reponse === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Le service de l\'Urssaf ne répond pas (' . ($err !== '' ? $err : 'aucune réponse') . ').');
        }
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$http, (string) $reponse];
    }

    /** @return array{0:int, 1:string} */
    private static function viaStream(string $url, string $corps, int $delai): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $corps, 'timeout' => $delai, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $reponse = @file_get_contents($url, false, $ctx);
        if ($reponse === false) {
            throw new \RuntimeException('Le service de l\'Urssaf ne répond pas (aucune réponse).');
        }
        $http = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $http = (int) $m[1]; }
        }
        return [$http, $reponse];
    }
}
