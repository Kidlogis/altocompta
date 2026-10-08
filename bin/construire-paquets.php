<?php
declare(strict_types=1);
/**
 * Fabrique les paquets de distribution : un zip par extension, et un zip du cœur SANS les extensions.
 *
 *   php bin/construire-paquets.php [--sortie=DOSSIER] [--url-images=https://site/dossier/]
 *
 * Pourquoi séparés : le cœur se met à jour (Mise à jour) sans jamais toucher aux extensions installées, et
 * chaque extension s'importe seule (Extensions › Importer). Un client qui n'achète qu'une extension ne
 * reçoit pas le code des autres.
 *
 * Les zips sont REPRODUCTIBLES : fichiers triés, dates et droits fixés. Reconstruire sans rien changer
 * donne la même empreinte SHA-256, ce qui permet de la publier dans le catalogue (boutique/kidlogis.json)
 * pour que l'application signale un fichier qui ne serait pas l'original.
 *
 * Avec --url-images, écrit aussi une version PUBLIQUE du catalogue (boutique-publique/kidlogis.json, avec ses
 * images) : le catalogue embarqué cite des images locales, que seule l'application elle-même sait servir ; un autre
 * site qui l'importerait les refuserait. La version publique, elle, pointe vers cette adresse en https.
 *
 * Ordre : extensions d'abord (leur empreinte entre dans le catalogue), puis le cœur (qui embarque ce catalogue).
 * Le script vérifie que chaque extension construite passe l'analyse de l'application ; sinon il échoue.
 */
require __DIR__ . '/../src/autoload.php';

use Bnc\Boutique;
use Bnc\ExtensionZip;

const DATE_FIXE = 1767225600;   // 2026-01-01 00:00:00 UTC

$racine = dirname(__DIR__);
$options = getopt('', ['sortie::', 'url-images::']);
$sortie = rtrim((string) ($options['sortie'] ?? (sys_get_temp_dir() . '/altocompta-paquets')), '/');
if (!is_dir($sortie) && !mkdir($sortie, 0755, true)) {
    fwrite(STDERR, "Impossible de créer $sortie\n");
    exit(1);
}
$version = trim((string) file_get_contents($racine . '/VERSION'));
if (!preg_match('/^\d+(\.\d+){1,3}$/', $version)) {
    fwrite(STDERR, "VERSION illisible.\n");
    exit(1);
}

/** @return list<string> chemins relatifs, triés */
function fichiers(string $dossier): array
{
    $liste = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && !$f->isLink()) {
            $liste[] = ltrim(substr($f->getPathname(), strlen($dossier)), '/');
        }
    }
    sort($liste, SORT_STRING);
    return $liste;
}

/** Écrit un zip reproductible : $entrees = [chemin dans l'archive => contenu]. */
function ecrire(string $chemin, array $entrees): void
{
    @unlink($chemin);
    ksort($entrees, SORT_STRING);
    $zip = new ZipArchive();
    if ($zip->open($chemin, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Création impossible : $chemin");
    }
    foreach ($entrees as $nom => $contenu) {
        $zip->addFromString($nom, $contenu);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $zip->setMtimeIndex($i, DATE_FIXE);
        $zip->setExternalAttributesIndex($i, ZipArchive::OPSYS_UNIX, 0100644 << 16);
    }
    if (!$zip->close()) {
        throw new RuntimeException("Écriture impossible : $chemin");
    }
}

// ---------------------------------------------------------------------------
//  1. Une archive par extension
// ---------------------------------------------------------------------------
$empreintes = [];   // extension => [version, sha256]
$ctx = ['installees' => [], 'core' => $version, 'reserves' => ['pro'], 'catalogues' => []];

foreach (glob($racine . '/plugins/*/plugin.php') ?: [] as $fichier) {
    $dossier = dirname($fichier);
    $id = basename($dossier);
    $m = (static fn(string $f) => require $f)($fichier);
    foreach (['nom', 'version', 'core_min'] as $cle) {
        if (empty($m[$cle]) || !is_string($m[$cle])) {
            fwrite(STDERR, "plugins/$id : « $cle » absent du manifeste.\n");
            exit(1);
        }
    }

    $entrees = [];
    foreach (fichiers($dossier) as $rel) {
        if (str_starts_with($rel, 'tests/') || str_starts_with(basename($rel), '.')) {
            continue;   // les tests restent dans le dépôt ; on ne livre pas de fichier caché
        }
        $entrees["$id/$rel"] = (string) file_get_contents($dossier . '/' . $rel);
    }
    $entrees["$id/manifeste.json"] = json_encode([
        'id' => $id, 'nom' => $m['nom'], 'version' => $m['version'], 'core_min' => $m['core_min'],
        'auteur' => $m['auteur'] ?? '', 'licence' => $m['licence'] ?? '', 'description' => $m['description'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";

    $nom = "altocompta-$id-{$m['version']}.zip";
    ecrire("$sortie/$nom", $entrees);

    // Le paquet doit passer l'analyse de l'application, ni plus ni moins.
    $a = ExtensionZip::apercu("$sortie/$nom", $nom, $ctx);
    if (!$a['ok']) {
        fwrite(STDERR, "$nom ne passe pas l'analyse de l'application : {$a['erreur']}\n");
        exit(1);
    }
    $empreintes[$id] = [$m['version'], $a['sha256'], $nom, filesize("$sortie/$nom"), $a['fichiers']];
}

// ---------------------------------------------------------------------------
//  2. L'empreinte de chaque extension entre dans le catalogue embarqué
// ---------------------------------------------------------------------------
$chemin = $racine . '/boutique/kidlogis.json';
$cat = json_decode((string) file_get_contents($chemin), true);
foreach ($cat['produits'] as &$p) {
    $id = $p['extension'] ?? $p['id'];
    if (!isset($empreintes[$id])) {
        fwrite(STDERR, "Avertissement : le produit « $id » du catalogue n'a pas d'extension correspondante.\n");
        continue;
    }
    if (($p['version'] ?? null) !== $empreintes[$id][0]) {
        fwrite(STDERR, "Le produit « $id » annonce la version " . ($p['version'] ?? '?') . ", l'extension est en {$empreintes[$id][0]} : aligner le catalogue.\n");
        exit(1);
    }
    $p['sha256'] = $empreintes[$id][1];
}
unset($p);
file_put_contents($chemin, json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
[$store, $err] = Boutique::valider($cat, true);
if ($store === null) {
    fwrite(STDERR, "Le catalogue mis à jour est invalide : " . implode(' ', $err) . "\n");
    exit(1);
}

// Version publique du catalogue : images en https, importable par n'importe quelle installation.
$urlImages = isset($options['url-images']) ? rtrim((string) $options['url-images'], '/') . '/' : null;
if ($urlImages !== null) {
    if (!Boutique::urlHttps($urlImages)) {
        fwrite(STDERR, "--url-images doit être une adresse https.\n");
        exit(1);
    }
    $publique = $cat;
    @mkdir("$sortie/boutique-publique/images", 0755, true);
    foreach ($publique['produits'] as &$p) {
        if (!empty($p['image']) && str_starts_with($p['image'], 'images/')) {
            $fichier = basename($p['image']);
            copy($racine . '/boutique/' . $p['image'], "$sortie/boutique-publique/images/$fichier");
            $p['image'] = $urlImages . $fichier;
        }
    }
    unset($p);
    [$verifie, $err] = Boutique::valider($publique, false);   // false : comme le ferait une AUTRE installation
    if ($verifie === null) {
        fwrite(STDERR, "Le catalogue public est invalide : " . implode(' ', $err) . "\n");
        exit(1);
    }
    file_put_contents("$sortie/boutique-publique/kidlogis.json", json_encode($publique, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
}

// ---------------------------------------------------------------------------
//  3. Le cœur, sans les extensions
// ---------------------------------------------------------------------------
$entrees = [];
foreach (fichiers($racine) as $rel) {
    $b = basename($rel);
    $exclu = (str_starts_with($rel, 'plugins/') && $rel !== 'plugins/LISEZMOI.md')
          || $rel === 'config/config.php'
          || (str_starts_with($rel, 'storage/') && $b !== '.gitkeep')
          || str_starts_with($rel, 'paquets/') || str_starts_with($rel, '.git/')
          || str_contains($rel, '/node_modules/') || $b === '.DS_Store' || str_ends_with($b, '.log');
    if (!$exclu) {
        $entrees["altocompta/$rel"] = (string) file_get_contents($racine . '/' . $rel);
    }
}
$nomCoeur = "altocompta-core-$version.zip";
ecrire("$sortie/$nomCoeur", $entrees);

// ---------------------------------------------------------------------------
echo "Paquets dans $sortie\n\n";
printf("%-44s %9s  %s\n", 'Fichier', 'Taille', 'SHA-256');
foreach ($empreintes as $id => [$v, $sha, $nom, $taille, $nb]) {
    printf("%-44s %7d Ko  %s\n", $nom, round($taille / 1024), $sha);
}
if ($urlImages !== null) {
    echo "\nCatalogue public : $sortie/boutique-publique/kidlogis.json (images dans images/, à héberger sous $urlImages)\n";
}
printf("%-44s %7d Ko  %s\n", $nomCoeur, round(filesize("$sortie/$nomCoeur") / 1024), hash_file('sha256', "$sortie/$nomCoeur"));
