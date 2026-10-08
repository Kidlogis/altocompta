<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Boutiques d'extensions : un catalogue JSON, affiché par l'application.
 *
 * La boutique de Kidlogis est embarquée (boutique/kidlogis.json), donc disponible hors ligne. D'autres
 * boutiques s'importent depuis un fichier ou une adresse. Le paiement a lieu sur le site du vendeur :
 * rien ici ne touche à de l'argent ni à une clé de licence. Après l'achat, on reçoit un zip, que
 * l'on importe par ExtensionZip.
 *
 * Une boutique importée est un contenu NON FIABLE. Elle est validée champ par champ avant d'être
 * conservée, ses textes ne sont jamais interprétés comme du HTML, ses liens doivent être en https, et
 * elle ne peut ni installer ni exécuter quoi que ce soit : elle ne fait que présenter et renvoyer vers
 * l'achat. Voir valider() pour le format (schéma 1).
 */
final class Boutique
{
    public const SCHEMA = 1;
    public const CLE_DEFAUT = 'kidlogis';
    /** Taille maximale d'un catalogue : au-delà, ce n'est plus un catalogue. */
    public const TAILLE_MAX = 524288;
    public const PRODUITS_MAX = 100;
    public const BOUTIQUES_MAX = 20;

    /** Nom d'un fichier image local : sans dossier, sans point initial, d'un type d'image. */
    private const NOM_IMAGE = '[a-z0-9][a-z0-9._-]{0,80}\.(webp|png|jpe?g)';

    private const PERIODES = ['unique' => 'paiement unique', 'annuel' => 'par an', 'mensuel' => 'par mois'];
    private const SYMBOLES = ['EUR' => '€', 'USD' => '$', 'GBP' => '£'];

    /**
     * Compare deux numéros de version : -1, 0 ou 1. « 1.0 » et « 1.0.0 » sont égaux, ce que ne dit pas
     * version_compare() de PHP, qui tient le second pour plus récent.
     */
    public static function comparerVersions(string $a, string $b): int
    {
        $n = static fn(string $v): string => implode('.', array_pad(array_map('intval', explode('.', $v)), 4, 0));
        return version_compare($n($a), $n($b));
    }

    // --- Lecture ---------------------------------------------------------

    private static function cheminDefaut(): string
    {
        return dirname(__DIR__) . '/boutique/kidlogis.json';
    }

    /** La boutique embarquée, ou null si son fichier est absent ou invalide. */
    public static function defaut(): ?array
    {
        $f = self::cheminDefaut();
        $brut = is_file($f) ? file_get_contents($f) : false;
        if ($brut === false) {
            return null;
        }
        [$store] = self::valider(json_decode($brut, true), true);
        return $store === null ? null : ['cle' => self::CLE_DEFAUT, 'integree' => true, 'source' => null, 'importee_le' => null] + $store;
    }

    /** @return list<array<string,mixed>> */
    public static function importees(): array
    {
        $sortie = [];
        foreach (Database::tous('SELECT * FROM boutiques ORDER BY nom, id') as $l) {
            [$store] = self::valider(json_decode((string) $l['contenu'], true), false);
            if ($store === null) {
                continue;   // écrit par cette application : ne devrait pas arriver ; on l'ignore plutôt que de planter la page
            }
            $sortie[] = ['cle' => (string) $l['cle'], 'integree' => false, 'source' => $l['source'], 'importee_le' => $l['maj_le']] + $store;
        }
        return $sortie;
    }

    /** La boutique par défaut d'abord, puis les boutiques importées. @return list<array<string,mixed>> */
    public static function toutes(): array
    {
        $d = self::defaut();
        return array_merge($d !== null ? [$d] : [], self::importees());
    }

    // --- Validation (pure) -----------------------------------------------

    /**
     * Valide et normalise un catalogue.
     *
     * Rien n'est corrigé en silence : un champ invalide refuse la boutique entière, avec la raison.
     * Les champs inconnus sont ignorés, ce qui laisse de la place aux évolutions du format.
     *
     * @param bool $integree vrai pour la boutique embarquée : seule autorisée à citer des images locales
     * @return array{0:?array<string,mixed>, 1:list<string>} boutique normalisée, erreurs
     */
    public static function valider(mixed $json, bool $integree): array
    {
        $err = [];
        if (!is_array($json) || array_is_list($json)) {
            return [null, ['Le fichier n\'est pas un objet JSON.']];
        }
        if (($json['schema'] ?? null) !== self::SCHEMA) {
            $s = $json['schema'] ?? 'absent';
            return [null, ['Format de boutique non pris en charge : schéma « ' . (is_scalar($s) ? $s : '?') . ' », celui-ci attend le schéma ' . self::SCHEMA . '.']];
        }

        $b = $json['boutique'] ?? null;
        if (!is_array($b)) {
            return [null, ['Bloc « boutique » absent.']];
        }
        $boutique = [
            'id'          => self::slug($b['id'] ?? null, 'boutique.id', $err),
            'nom'         => self::texte($b['nom'] ?? null, 80, 'boutique.nom', $err, requis: true),
            'url'         => isset($b['url']) ? self::url($b['url'], 'boutique.url', $err) : null,
            'description' => self::texte($b['description'] ?? null, 300, 'boutique.description', $err),
        ];

        $liste = $json['produits'] ?? null;
        if (!is_array($liste) || !array_is_list($liste)) {
            $err[] = 'Liste « produits » absente ou invalide.';
            return [null, $err];
        }
        if (count($liste) > self::PRODUITS_MAX) {
            return [null, ['Trop de produits (' . count($liste) . ', maximum ' . self::PRODUITS_MAX . ').']];
        }

        $produits = [];
        $vus = [];
        foreach ($liste as $i => $p) {
            $n = 'produit ' . ($i + 1);
            if (!is_array($p)) {
                $err[] = "$n : n'est pas un objet.";
                continue;
            }
            $id = self::slug($p['id'] ?? null, "$n.id", $err);
            if ($id !== null) {
                if (isset($vus[$id])) {
                    $err[] = "$n : identifiant « $id » déjà utilisé.";
                }
                $vus[$id] = true;
            }
            $ext = isset($p['extension']) ? self::slug($p['extension'], "$n.extension", $err) : $id;

            $prix = null;
            if (isset($p['prix'])) {
                $prix = self::prix($p['prix'], "$n.prix", $err);
            }
            $points = [];
            if (isset($p['points_forts'])) {
                if (!is_array($p['points_forts']) || !array_is_list($p['points_forts']) || count($p['points_forts']) > 12) {
                    $err[] = "$n.points_forts : liste de 12 éléments au plus.";
                } else {
                    foreach ($p['points_forts'] as $pf) {
                        $t = self::texte($pf, 200, "$n.points_forts", $err, requis: true);
                        if ($t !== null) {
                            $points[] = $t;
                        }
                    }
                }
            }

            $produits[] = [
                'id'          => $id,
                'extension'   => $ext,
                'titre'       => self::texte($p['titre'] ?? null, 100, "$n.titre", $err, requis: true),
                'resume'      => self::texte($p['resume'] ?? null, 300, "$n.resume", $err),
                'description' => self::texte($p['description'] ?? null, 4000, "$n.description", $err, multiligne: true),
                'points_forts' => $points,
                'prerequis'   => self::texte($p['prerequis'] ?? null, 400, "$n.prerequis", $err),
                'version'     => self::version($p['version'] ?? null, "$n.version", $err),
                'core_min'    => self::version($p['core_min'] ?? null, "$n.core_min", $err),
                'prix'        => $prix ?? ['montant' => null, 'devise' => 'EUR', 'periodicite' => '', 'libelle' => null],
                'url_achat'   => isset($p['url_achat']) ? self::url($p['url_achat'], "$n.url_achat", $err) : null,
                'image'       => isset($p['image']) ? self::image($p['image'], $integree, "$n.image", $err) : null,
                'editeur'     => self::texte($p['editeur'] ?? null, 80, "$n.editeur", $err),
                'sha256'      => self::sha256($p['sha256'] ?? null, "$n.sha256", $err),
            ];
        }

        if ($err) {
            return [null, array_slice($err, 0, 8)];
        }
        return [['schema' => self::SCHEMA, 'boutique' => $boutique, 'produits' => $produits], []];
    }

    private static function slug(mixed $v, string $champ, array &$err): ?string
    {
        if (!is_string($v) || !preg_match('/^[a-z0-9][a-z0-9_-]{1,40}$/', $v)) {
            $err[] = "$champ : identifiant attendu (lettres minuscules, chiffres, tirets, 2 à 41 caractères).";
            return null;
        }
        return $v;
    }

    /** Texte brut : jamais du HTML (les gabarits échappent), sans caractères de contrôle. */
    private static function texte(mixed $v, int $max, string $champ, array &$err, bool $requis = false, bool $multiligne = false): ?string
    {
        if ($v === null || $v === '') {
            if ($requis) {
                $err[] = "$champ : obligatoire.";
            }
            return null;
        }
        if (!is_string($v)) {
            $err[] = "$champ : texte attendu.";
            return null;
        }
        $v = str_replace("\r\n", "\n", trim($v));
        if (preg_match($multiligne ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $v)) {
            $err[] = "$champ : caractères de contrôle interdits.";
            return null;
        }
        if (mb_strlen($v) > $max) {
            $err[] = "$champ : $max caractères au plus.";
            return null;
        }
        return $v;
    }

    /** Lien https, ASCII, sans identifiants intégrés ni caractères propres à casser un attribut HTML. */
    public static function urlHttps(string $v): bool
    {
        $v = trim($v);
        if ($v === '' || strlen($v) > 500 || !preg_match('/^[\x21-\x7E]+$/', $v) || preg_match('/["<>\\\\^`{|}]/', $v)) {
            return false;
        }
        $p = parse_url($v);
        return is_array($p) && strtolower((string) ($p['scheme'] ?? '')) === 'https' && ($p['host'] ?? '') !== ''
            && !isset($p['user']) && !isset($p['pass']);
    }

    private static function url(mixed $v, string $champ, array &$err): ?string
    {
        if (!is_string($v) || !self::urlHttps($v)) {
            $err[] = "$champ : adresse https attendue.";
            return null;
        }
        return trim($v);
    }

    private static function image(mixed $v, bool $integree, string $champ, array &$err): ?string
    {
        if (is_string($v) && $integree && preg_match('#^images/' . self::NOM_IMAGE . '$#i', $v)) {
            return $v;   // une image livrée avec l'application, dans boutique/images/
        }
        if (is_string($v) && self::urlHttps($v)) {
            return trim($v);
        }
        $err[] = "$champ : adresse https attendue.";
        return null;
    }

    private static function version(mixed $v, string $champ, array &$err): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !preg_match('/^\d+(\.\d+){0,3}$/', $v)) {
            $err[] = "$champ : numéro de version attendu (ex. 1.2.0).";
            return null;
        }
        return $v;
    }

    private static function sha256(mixed $v, string $champ, array &$err): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !preg_match('/^[a-f0-9]{64}$/', $v)) {
            $err[] = "$champ : empreinte SHA-256 attendue (64 caractères hexadécimaux minuscules).";
            return null;
        }
        return $v;
    }

    /** @return array{montant:?float, devise:string, periodicite:string, libelle:?string} */
    private static function prix(mixed $p, string $champ, array &$err): array
    {
        $r = ['montant' => null, 'devise' => 'EUR', 'periodicite' => '', 'libelle' => null];
        if (!is_array($p)) {
            $err[] = "$champ : objet attendu.";
            return $r;
        }
        $m = $p['montant'] ?? null;
        if (is_int($m) || is_float($m) || (is_string($m) && preg_match('/^\d+(\.\d{1,2})?$/', $m))) {
            $m = (float) $m;
            if ($m < 0 || $m > 100000) {
                $err[] = "$champ.montant : entre 0 et 100 000.";
            } else {
                $r['montant'] = round($m, 2);
            }
        } elseif ($m !== null) {
            $err[] = "$champ.montant : nombre attendu.";
        }
        if (isset($p['devise'])) {
            if (is_string($p['devise']) && preg_match('/^[A-Z]{3}$/', $p['devise'])) {
                $r['devise'] = $p['devise'];
            } else {
                $err[] = "$champ.devise : code à trois lettres majuscules (EUR).";
            }
        }
        if (isset($p['periodicite']) && $p['periodicite'] !== '') {
            if (is_string($p['periodicite']) && isset(self::PERIODES[$p['periodicite']])) {
                $r['periodicite'] = $p['periodicite'];
            } else {
                $err[] = "$champ.periodicite : unique, annuel ou mensuel.";
            }
        }
        $r['libelle'] = self::texte($p['libelle'] ?? null, 40, "$champ.libelle", $err);
        return $r;
    }

    // --- Images livrées avec l'application ------------------------------------

    /**
     * Les couvertures de la boutique officielle ne sont PAS dans le dossier public : l'application les sert
     * elle-même (route /boutique/image/<nom>). Un fichier statique dépend du serveur web — droits des
     * dossiers, configuration, types de fichiers —, que l'application ne maîtrise pas ; elle maîtrise ses
     * propres fichiers.
     *
     * @return ?string chemin réel du fichier, ou null si le nom n'est pas celui d'une image de ce dossier
     */
    public static function cheminImage(string $nom): ?string
    {
        if (!preg_match('#^' . self::NOM_IMAGE . '$#i', $nom)) {
            return null;
        }
        $dossier = realpath(dirname(__DIR__) . '/boutique/images');
        if ($dossier === false) {
            return null;
        }
        $f = realpath($dossier . '/' . $nom);
        return ($f !== false && str_starts_with($f, $dossier . '/') && is_file($f)) ? $f : null;
    }

    public static function typeImage(string $chemin): string
    {
        return match (strtolower(pathinfo($chemin, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', default => 'image/webp',
        };
    }

    /** L'adresse à mettre dans <img> : la route de l'application pour une image locale, l'adresse https sinon. */
    public static function urlImage(string $image): string
    {
        return str_starts_with($image, 'images/') ? '/boutique/image/' . rawurlencode(basename($image)) : $image;
    }

    // --- Affichage ---------------------------------------------------------

    /** « Gratuit », « 29,90 € », « 29,90 € par an » ; un libellé du vendeur l'emporte (« Sur devis »). */
    public static function prixAffiche(array $prix): string
    {
        if (!empty($prix['libelle'])) {
            return (string) $prix['libelle'];
        }
        if ($prix['montant'] === null) {
            return '';
        }
        if ((float) $prix['montant'] === 0.0) {
            return 'Gratuit';
        }
        $devise = (string) ($prix['devise'] ?? 'EUR');
        $s = number_format((float) $prix['montant'], 2, ',', ' ') . ' ' . (self::SYMBOLES[$devise] ?? $devise);
        return !empty($prix['periodicite']) ? $s . ' ' . self::PERIODES[$prix['periodicite']] : $s;
    }

    /**
     * Où en est un produit sur cette installation.
     *
     * @param array<string,array> $plugins extensions détectées, par identifiant (Plugin::decouvrir())
     * @param array<string,array> $etats   états en base (Plugin::etats())
     * @return array{statut:string, installee:?string, maj:bool, compatible:bool}
     */
    public static function etat(array $produit, array $plugins, array $etats, string $versionCore): array
    {
        $id = (string) $produit['extension'];
        $installee = isset($plugins[$id]);
        $v = $installee ? (string) ($plugins[$id]['version'] ?? '') : null;
        $actif = $installee && (int) ($etats[$id]['actif'] ?? 0) === 1;
        return [
            'statut'     => !$installee ? 'absente' : ($actif ? 'active' : 'inactive'),
            'installee'  => $v,
            'maj'        => $installee && !empty($produit['version']) && $v !== '' && self::comparerVersions((string) $produit['version'], (string) $v) > 0,
            'compatible' => empty($produit['core_min']) || $versionCore === '' || self::comparerVersions($versionCore, (string) $produit['core_min']) >= 0,
        ];
    }

    // --- Import ------------------------------------------------------------

    /**
     * Importe un catalogue (contenu brut). Une boutique qui porte le même identifiant est mise à jour.
     *
     * @return array{ok:bool, erreur:?string, cle:?string, nb:int}
     */
    public static function importer(string $brut, ?string $source): array
    {
        $echec = static fn(string $m) => ['ok' => false, 'erreur' => $m, 'cle' => null, 'nb' => 0];
        if (strlen($brut) > self::TAILLE_MAX) {
            return $echec('Fichier trop gros pour un catalogue (' . round(self::TAILLE_MAX / 1024) . ' Ko au plus).');
        }
        $json = json_decode($brut, true, 12);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            return $echec('JSON illisible : ' . json_last_error_msg() . '.');
        }
        [$store, $erreurs] = self::valider($json, false);
        if ($store === null) {
            return $echec('Boutique refusée — ' . implode(' ', $erreurs));
        }
        $cle = (string) $store['boutique']['id'];
        if ($cle === self::CLE_DEFAUT) {
            return $echec('L\'identifiant « ' . self::CLE_DEFAUT . ' » est réservé à la boutique intégrée.');
        }
        $existe = (int) Database::valeur('SELECT COUNT(*) FROM boutiques WHERE cle = :c', ['c' => $cle]) > 0;
        if (!$existe && (int) Database::valeur('SELECT COUNT(*) FROM boutiques') >= self::BOUTIQUES_MAX) {
            return $echec('Trop de boutiques importées (' . self::BOUTIQUES_MAX . ' au plus).');
        }

        $maintenant = date('Y-m-d H:i:s');
        Database::executer(
            'INSERT INTO boutiques (cle, nom, source, contenu, importee_le, maj_le)
             VALUES (:c, :n, :s, :j, :t1, :t2)
             ON DUPLICATE KEY UPDATE nom = VALUES(nom), source = COALESCE(VALUES(source), source),
                                     contenu = VALUES(contenu), maj_le = VALUES(maj_le)',
            ['c' => $cle, 'n' => $store['boutique']['nom'], 's' => $source,
             'j' => json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 't1' => $maintenant, 't2' => $maintenant]
        );
        return ['ok' => true, 'erreur' => null, 'cle' => $cle, 'nb' => count($store['produits'])];
    }

    public static function supprimer(string $cle): void
    {
        if ($cle !== self::CLE_DEFAUT) {
            Database::executer('DELETE FROM boutiques WHERE cle = :c', ['c' => $cle]);
        }
    }

    /** Recharge une boutique importée depuis son adresse d'origine. */
    public static function actualiser(string $cle): array
    {
        $source = Database::valeur('SELECT source FROM boutiques WHERE cle = :c', ['c' => $cle]);
        if (!is_string($source) || $source === '') {
            return ['ok' => false, 'erreur' => 'Cette boutique a été importée depuis un fichier : la réimporter pour la mettre à jour.', 'cle' => $cle, 'nb' => 0];
        }
        [$brut, $err] = self::telecharger($source);
        if ($brut === null) {
            return ['ok' => false, 'erreur' => $err, 'cle' => $cle, 'nb' => 0];
        }
        $r = self::importer($brut, $source);
        if ($r['ok'] && $r['cle'] !== $cle) {
            // La boutique distante a changé d'identifiant : ce serait une autre boutique, pas une mise à jour.
            self::supprimer((string) $r['cle']);
            return ['ok' => false, 'erreur' => 'L\'adresse renvoie désormais une autre boutique (« ' . $r['cle'] . ' ») : mise à jour refusée.', 'cle' => $cle, 'nb' => 0];
        }
        return $r;
    }

    // --- Import par adresse : ne jamais laisser le serveur être un relais vers l'intérieur ---

    /** Le réglage qui lève les garde-fous réseau, pour les essais en local. Refusé par défaut, y compris sans configuration. */
    private static function reseauPrivePermis(): bool
    {
        try {
            return (bool) Config::get('boutique.reseau_prive', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Une adresse IP publique, c'est-à-dire joignable seulement de l'extérieur. */
    public static function ipPublique(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];   // une IPv4 déguisée en IPv6
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $n = ip2long($ip);
            // Plages que PHP ne classe pas comme privées : partage d'adresses d'opérateur, tests, benchmark.
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24]] as [$base, $bits]) {
                $masque = -1 << (32 - $bits);
                if (($n & $masque) === (ip2long($base) & $masque)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Vérifie qu'une adresse peut être appelée par le serveur, et fige l'adresse IP à utiliser.
     *
     * Contrôler l'adresse IP après résolution, puis s'y connecter exactement, évite qu'un nom de domaine
     * ne réponde « public » au contrôle et « 127.0.0.1 » à la connexion. Le réglage boutique.reseau_prive
     * lève ces restrictions : réservé aux essais en local.
     *
     * @return array{0:?array{host:string, port:int, ip:string, https:bool}, 1:string} cible, erreur
     */
    public static function cible(string $url): array
    {
        $prive = self::reseauPrivePermis();
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || !preg_match('/^[\x21-\x7E]+$/', $url)) {
            return [null, 'Adresse invalide.'];
        }
        $p = parse_url($url);
        $schema = strtolower((string) ($p['scheme'] ?? ''));
        if (!is_array($p) || ($schema !== 'https' && !($prive && $schema === 'http')) || ($p['host'] ?? '') === '') {
            return [null, 'Adresse en https attendue.'];
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return [null, 'Les identifiants dans l\'adresse sont refusés.'];
        }
        $https = $schema === 'https';
        $port = (int) ($p['port'] ?? ($https ? 443 : 80));
        if (!$prive && $port !== 443) {
            return [null, 'Seul le port 443 est accepté.'];
        }

        $host = trim((string) $p['host'], '[]');
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $ips = array_merge($ips, (array) @gethostbynamel($host));
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        $ips = array_values(array_unique(array_filter($ips)));
        if (!$ips) {
            return [null, 'Nom de domaine introuvable.'];
        }
        if (!$prive) {
            foreach ($ips as $ip) {
                if (!self::ipPublique($ip)) {
                    return [null, 'Cette adresse pointe vers un réseau interne : refusée.'];
                }
            }
        }
        return [['host' => $host, 'port' => $port, 'ip' => $ips[0], 'https' => $https], ''];
    }

    /** @return array{0:?string, 1:string} contenu, erreur */
    public static function telecharger(string $url): array
    {
        [$cible, $err] = self::cible($url);
        if ($cible === null) {
            return [null, $err];
        }
        if (!function_exists('curl_init')) {
            return [null, 'L\'import par adresse demande l\'extension PHP curl : importez plutôt le fichier.'];
        }

        $recu = '';
        $trop = false;
        $ch = curl_init(trim($url));
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$cible['host'] . ':' . $cible['port'] . ':' . $cible['ip']],
            CURLOPT_PROTOCOLS      => $cible['https'] ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
            CURLOPT_FOLLOWLOCATION => false,    // une redirection pourrait mener à une adresse interne : on ne la suit pas
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'AltoCompta/1.0 (boutique)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $morceau) use (&$recu, &$trop): int {
                $recu .= $morceau;
                if (strlen($recu) > self::TAILLE_MAX) {
                    $trop = true;
                    return 0;   // interrompt le transfert
                }
                return strlen($morceau);
            },
        ]);
        $ok = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $detail = curl_error($ch);
        curl_close($ch);

        if ($trop) {
            return [null, 'Catalogue trop gros (' . round(self::TAILLE_MAX / 1024) . ' Ko au plus).'];
        }
        if ($ok === false) {
            return [null, 'Le serveur de la boutique ne répond pas' . ($detail !== '' ? ' (' . $detail . ')' : '') . '.'];
        }
        if ($http >= 300 && $http < 400) {
            return [null, 'La boutique redirige vers une autre adresse : utilisez l\'adresse finale.'];
        }
        if ($http !== 200) {
            return [null, 'La boutique a répondu avec le code ' . $http . '.'];
        }
        return [$recu, ''];
    }
}
