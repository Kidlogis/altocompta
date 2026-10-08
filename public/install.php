<?php
declare(strict_types=1);
/**
 * Installateur guidé. Se neutralise dès que config/config.php existe.
 * Autonome : ne dépend ni du bootstrap ni de la configuration.
 */

$racine  = dirname(__DIR__);
$GLOBALS['racine'] = $racine;
$config  = $racine . '/config/config.php';
$schema  = $racine . '/db/schema.sql';

require $racine . '/src/autoload.php';
use Bnc\Crypto;

$GLOBALS['maintenant'] = date('d/m/Y à H:i');
$GLOBALS['url_app'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

session_start();

if (is_file($config) && !isset($_GET['force'])) {
    http_response_code(403);
    ecran('Déjà installé',
        '<p class="txt">L\'application est configurée. Pour la réinstaller, supprimer '
        . '<code>config/config.php</code> sur le serveur.</p>'
        . '<p style="margin-top:14px"><a class="btn btn-p" href="/">Ouvrir l\'application</a></p>');
    exit;
}

$etape   = (int) ($_GET['etape'] ?? 1);
$erreurs = [];

// ---------------------------------------------------------------------
//  Étape 1 — prérequis
// ---------------------------------------------------------------------
function prerequis(string $racine): array
{
    $c = [];
    $c[] = ['PHP 8.1 ou plus récent', PHP_VERSION_ID >= 80100, PHP_VERSION, true];
    foreach (['pdo_mysql' => true, 'mbstring' => true, 'openssl' => true, 'sodium' => false, 'curl' => false] as $ext => $bloquant) {
        $c[] = ["Extension {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'présente' : 'absente', $bloquant];
    }
    $c[] = ['Dossier config/ accessible en écriture', is_writable($racine . '/config'),
            is_writable($racine . '/config') ? 'ok' : 'lecture seule', true];
    $c[] = ['Dossier storage/justificatifs/ accessible en écriture', is_writable($racine . '/storage/justificatifs'),
            is_writable($racine . '/storage/justificatifs') ? 'ok' : 'lecture seule', true];
    return $c;
}

// ---------------------------------------------------------------------
//  Traitements
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Étape 2 : base de données
    if ($etape === 2) {
        $db = [
            'hote' => trim((string) ($_POST['hote'] ?? '127.0.0.1')),
            'port' => (int) ($_POST['port'] ?? 3306),
            'base' => trim((string) ($_POST['base'] ?? '')),
            'user' => trim((string) ($_POST['user'] ?? '')),
            'mdp'  => (string) ($_POST['mdp'] ?? ''),
        ];

        if ($db['base'] === '' || $db['user'] === '') {
            $erreurs[] = 'Le nom de la base et l\'utilisateur sont obligatoires.';
        } else {
            try {
                $pdo = new PDO(
                    "mysql:host={$db['hote']};port={$db['port']};dbname={$db['base']};charset=utf8mb4",
                    $db['user'], $db['mdp'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

                $tables = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.tables
                      WHERE table_schema = DATABASE() AND table_name = 'users'"
                )->fetchColumn();

                if ($tables > 0 && empty($_POST['ecraser'])) {
                    $erreurs[] = 'Cette base contient déjà les tables de l\'application. '
                               . 'Cocher la case ci-dessous pour les conserver et poursuivre, '
                               . 'ou choisir une base vide.';
                } else {
                    $_SESSION['inst_db']      = $db;
                    $_SESSION['inst_version'] = $version;
                    $_SESSION['inst_existe']  = $tables > 0;
                    header('Location: ?etape=3');
                    exit;
                }
            } catch (PDOException $e) {
                $erreurs[] = 'Connexion impossible : ' . lisibleErreurPdo($e);
            }
        }
    }

    // --- Étape 3 : compte administrateur, puis installation
    if ($etape === 3) {
        $email = trim((string) ($_POST['email'] ?? ''));
        $nom   = trim((string) ($_POST['nom'] ?? ''));
        $denom = trim((string) ($_POST['denomination'] ?? ''));
        $siret = preg_replace('/\D/', '', (string) ($_POST['siret'] ?? '')) ?: null;
        $mdp   = (string) ($_POST['mdp'] ?? '');
        $mdp2  = (string) ($_POST['mdp2'] ?? '');
        $annee = (int) ($_POST['annee'] ?? date('Y'));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erreurs[] = 'Adresse e-mail invalide.';
        if ($nom === '')                                $erreurs[] = 'Le nom est obligatoire.';
        if (strlen($mdp) < 12)                          $erreurs[] = 'Le mot de passe doit faire 12 caractères au minimum.';
        if ($mdp !== $mdp2)                             $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
        if ($siret !== null && strlen($siret) !== 14)   $erreurs[] = 'Le SIRET doit comporter 14 chiffres.';
        if (empty($_SESSION['inst_db']))                $erreurs[] = 'Étape base de données non validée.';

        if (!$erreurs) {
            $db = $_SESSION['inst_db'];
            try {
                $pdo = new PDO(
                    "mysql:host={$db['hote']};port={$db['port']};dbname={$db['base']};charset=utf8mb4",
                    $db['user'], $db['mdp'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );

                if (empty($_SESSION['inst_existe'])) {
                    $pdo->exec(file_get_contents($GLOBALS['schema']));
                    $st = $pdo->prepare('INSERT INTO migrations (version, applique_le) VALUES (?, NOW())');
                    $st->execute(['001_initial']);

                    // Le schéma intègre déjà les migrations livrées : les marquer
                    // évite qu'un migrate.php ultérieur tente de les rejouer.
                    foreach (glob($GLOBALS['racine'] . '/db/migrations/*.sql') ?: [] as $f) {
                        $st->execute([basename($f, '.sql')]);
                    }
                }

                $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $st->execute([mb_strtolower($email)]);
                if ($st->fetchColumn()) {
                    throw new RuntimeException('Un compte existe déjà avec cette adresse e-mail.');
                }

                $st = $pdo->prepare(
                    'INSERT INTO users (email, mot_de_passe, nom, denomination, siret, role, actif, cree_le)
                     VALUES (?, ?, ?, ?, ?, "admin", 1, NOW())'
                );
                $st->execute([mb_strtolower($email), password_hash($mdp, PASSWORD_DEFAULT), $nom, $denom ?: null, $siret]);
                $userId = (int) $pdo->lastInsertId();

                $st = $pdo->prepare(
                    'INSERT INTO exercices (user_id, annee, date_debut, date_fin, statut, cree_le)
                     VALUES (?, ?, ?, ?, "ouvert", NOW())'
                );
                $st->execute([$userId, $annee, "$annee-01-01", "$annee-12-31"]);

                // Quelques règles d'affectation de départ
                $st = $pdo->prepare(
                    'INSERT INTO regles_affectation (user_id, priorite, motif, type_match, sens_montant, cible_type, poste_2035, actif, cree_le)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())'
                );
                foreach ([
                    [10, 'URSSAF',                    'contient', 'debit', 'depense',  'BW'],
                    [20, 'TOTAL|ESSO|SHELL|INTERMARCHE CARBURANT', 'regex', 'debit', 'depense', 'BR'],
                    [30, 'OVH|IONOS|NETCUP|SCALEWAY', 'regex',    'debit', 'depense',  'BY'],
                    [40, 'ASSURANCE|MAAF|AXA|MAIF',   'regex',    'debit', 'depense',  'BP'],
                ] as $r) {
                    $st->execute([$userId, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]]);
                }

                // Écriture de la configuration
                $cle = Crypto::genererCleMaitre();
                $contenu = rendreConfig($db, $cle);
                if (@file_put_contents($GLOBALS['config'], $contenu) === false) {
                    throw new RuntimeException(
                        'Écriture de config/config.php impossible. Vérifier les droits du dossier config/.'
                    );
                }
                @chmod($GLOBALS['config'], 0640);

                session_destroy();
                header('Location: ?etape=4');
                exit;

            } catch (Throwable $e) {
                $erreurs[] = $e instanceof PDOException ? lisibleErreurPdo($e) : $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------
//  Rendu
// ---------------------------------------------------------------------
switch ($etape) {
    case 2:  vueBase($erreurs);   break;
    case 3:  vueCompte($erreurs); break;
    case 4:  vueFin();            break;
    default: vuePrerequis($racine);
}

// =====================================================================
//  Fonctions de rendu
// =====================================================================
function e(?string $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function lisibleErreurPdo(PDOException $e): string
{
    $m = $e->getMessage();
    return match (true) {
        // MySQL renvoie « Access denied » aussi bien pour un mot de passe faux
        // que pour un utilisateur sans droits sur la base visée : à distinguer.
        str_contains($m, 'Access denied') && str_contains($m, 'to database')
            => 'cet utilisateur MySQL n\'a aucun droit sur cette base. Vérifier le nom de la base, '
             . 'ou lui accorder les droits depuis le panneau d\'administration de l\'hébergeur.',
        str_contains($m, 'Access denied')        => 'identifiant ou mot de passe refusé par le serveur MySQL.',
        str_contains($m, 'Unknown database')     => 'cette base de données n\'existe pas. La créer d\'abord depuis le panneau d\'administration de l\'hébergeur.',
        str_contains($m, 'Connection refused')   => 'aucun serveur MySQL ne répond sur cet hôte et ce port.',
        str_contains($m, 'getaddrinfo')          => 'nom d\'hôte introuvable.',
        str_contains($m, 'timed out')            => 'délai dépassé : hôte ou port probablement incorrect.',
        default                                  => $m,
    };
}

function rendreConfig(array $db, string $cle): string
{
    $x = static fn($v) => var_export($v, true);
    return <<<PHP
    <?php
    /**
     * Généré par l'installateur le {$GLOBALS['maintenant']}.
     * Ce fichier contient des secrets : ne jamais le versionner.
     */
    return [
        'db' => [
            'hote' => {$x($db['hote'])},
            'port' => {$x($db['port'])},
            'base' => {$x($db['base'])},
            'user' => {$x($db['user'])},
            'mdp'  => {$x($db['mdp'])},
        ],

        // Chiffre les clés API des connecteurs. La perdre les rend irrécupérables.
        'cle_maitre' => {$x($cle)},

        'stockage' => __DIR__ . '/../storage/justificatifs',

        'app' => [
            'nom'         => 'AltoCompta',
            'url'         => {$x($GLOBALS['url_app'])},
            'fuseau'      => 'Europe/Paris',
            'debug'       => false,
            'session_nom' => 'altocompta',
        ],

        'altoviz' => [
            'base_url' => 'https://api.altoviz.com',
            'timeout'  => 30,
        ],
    ];
    PHP;
}

function ecran(string $titre, string $corps, int $etape = 0): void
{
    $etapes = ['Prérequis', 'Base de données', 'Compte', 'Terminé'];
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($titre) . ' — Installation</title>'
       . '<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">'
       . '<link rel="preconnect" href="https://fonts.googleapis.com">'
       . '<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">'
       . '<link rel="stylesheet" href="' . e(\Bnc\View::asset('/assets/app.css')) . '"></head><body class="ecran-connexion">'
       . '<div class="boite-install">'
       . '<img src="' . e(\Bnc\View::asset('/assets/img/logo-880.png')) . '" alt="AltoCompta" class="logo-install">';

    if ($etape > 0) {
        echo '<ol class="fil">';
        foreach ($etapes as $i => $lib) {
            $n = $i + 1;
            $cls = $n < $etape ? 'fait' : ($n === $etape ? 'courant' : '');
            echo '<li class="' . $cls . '"><span>' . $n . '</span>' . e($lib) . '</li>';
        }
        echo '</ol>';
    }

    echo '<div class="carte"><div class="carte-tete"><h2>' . e($titre) . '</h2></div>'
       . $corps . '</div></div></body></html>';
}

function alertes(array $erreurs): string
{
    if (!$erreurs) return '';
    $h = '<div style="padding:12px 16px 0">';
    foreach ($erreurs as $x) $h .= '<p class="alerte" style="margin:0 0 8px">' . e($x) . '</p>';
    return $h . '</div>';
}

function vuePrerequis(string $racine): void
{
    $checks = prerequis($racine);
    $bloque = false;
    $h = '<table><tbody>';
    foreach ($checks as [$lib, $ok, $detail, $bloquant]) {
        if (!$ok && $bloquant) $bloque = true;
        $cls = $ok ? 'p-ok' : ($bloquant ? 'p-ko' : 'p-att');
        $h .= '<tr><td>' . e($lib) . '</td>'
            . '<td style="width:150px"><span class="puce ' . $cls . '">'
            . e($ok ? $detail : ($bloquant ? $detail : $detail . ' (optionnel)')) . '</span></td></tr>';
    }
    $h .= '</tbody></table>';

    if ($bloque) {
        $h .= '<div class="champ"><p class="alerte">Corriger les points en rouge avant de poursuivre. '
            . 'Pour les droits d\'écriture : <code>chmod 750 config storage/justificatifs</code> '
            . 'en tant que propriétaire du site.</p>'
            . '<button class="btn" onclick="location.reload()">Revérifier</button></div>';
    } else {
        $h .= '<div class="champ"><a class="btn btn-p" href="?etape=2">Continuer</a></div>';
    }
    ecran('Vérification du serveur', $h, 1);
}

function vueBase(array $erreurs): void
{
    $v = static fn(string $k, string $d = '') => e((string) ($_POST[$k] ?? $d));
    $h = alertes($erreurs) . '<form method="post" action="?etape=2">'
       . champ('hote', 'Hôte MySQL', 'text', $v('hote', '127.0.0.1'),
               'Sur la plupart des hébergements mutualisés, la base est locale : garder 127.0.0.1 ou localhost.')
       . champ('port', 'Port', 'text', $v('port', '3306'))
       . champ('base', 'Nom de la base', 'text', $v('base'),
               'La base doit déjà exister : la créer depuis le panneau d\'administration de l\'hébergeur.')
       . champ('user', 'Utilisateur MySQL', 'text', $v('user'))
       . champ('mdp',  'Mot de passe MySQL', 'password', '');

    if ($erreurs && str_contains(implode(' ', $erreurs), 'contient déjà')) {
        $h .= '<div class="champ"><label style="font-weight:400;display:flex;gap:8px;align-items:flex-start">'
            . '<input type="checkbox" name="ecraser" value="1" style="width:auto;margin-top:3px">'
            . '<span>Conserver les tables existantes et ajouter seulement le compte. '
            . 'Les données déjà présentes ne seront pas touchées.</span></label></div>';
    }

    $h .= '<div class="champ"><button class="btn btn-p" style="width:100%">Tester et continuer</button></div></form>';
    ecran('Base de données', $h, 2);
}

function vueCompte(array $erreurs): void
{
    $v = static fn(string $k, string $d = '') => e((string) ($_POST[$k] ?? $d));
    $version = e((string) ($_SESSION['inst_version'] ?? ''));
    $h = '<div class="etat"><b>Base joignable</b> — MySQL ' . $version . '</div>'
       . alertes($erreurs)
       . '<form method="post" action="?etape=3">'
       . champ('nom', 'Nom', 'text', $v('nom'))
       . champ('denomination', 'Dénomination portée sur la 2035', 'text', $v('denomination'),
               'Facultatif. Affiché en tête de la déclaration.')
       . champ('siret', 'SIRET', 'text', $v('siret'), 'Facultatif, 14 chiffres.')
       . champ('email', 'Adresse e-mail', 'email', $v('email'), 'Sert d\'identifiant de connexion.')
       . champ('mdp',  'Mot de passe', 'password', '', '12 caractères au minimum.')
       . champ('mdp2', 'Confirmation du mot de passe', 'password', '')
       . champ('annee', 'Premier exercice', 'text', $v('annee', date('Y')),
               'L\'exercice est créé du 1ᵉʳ janvier au 31 décembre.')
       . '<div class="champ"><button class="btn btn-p" style="width:100%">Installer</button></div></form>';
    ecran('Compte administrateur', $h, 3);
}

function vueFin(): void
{
    $h = '<div class="etat"><b>Installation terminée</b> — la configuration a été écrite.</div>'
       . '<div class="champ"><p class="txt">Dernier geste, côté serveur : supprimer l\'installateur '
       . 'pour qu\'il ne reste pas accessible.</p>'
       . '<pre class="cmd">rm public/install.php</pre></div>'
       . '<div class="champ"><p class="txt">Sauvegarder <code>config/config.php</code> : '
       . 'il contient la clé qui déchiffre les clés API des connecteurs. Sans elle, elles sont à régénérer.</p></div>'
       . '<div class="champ"><a class="btn btn-p" href="/" style="width:100%;text-align:center">Ouvrir l\'application</a></div>';
    ecran('Terminé', $h, 4);
}

function champ(string $nom, string $label, string $type, string $valeur, string $aide = ''): string
{
    return '<div class="champ"><label for="' . $nom . '">' . e($label) . '</label>'
         . '<input id="' . $nom . '" name="' . $nom . '" type="' . $type . '" value="' . $valeur . '"'
         . ($type === 'password' ? ' autocomplete="new-password"' : '') . '>'
         . ($aide ? '<div class="aide">' . $aide . '</div>' : '')
         . '</div>';
}
