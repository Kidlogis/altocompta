<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\ExtensionZip as Z;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
$tmp = sys_get_temp_dir() . '/extzip-' . getmypid(); @mkdir($tmp, 0755, true);
register_shutdown_function(function () use ($tmp) { exec('rm -rf ' . escapeshellarg($tmp)); });

$manifeste = fn(array $s = []) => json_encode(array_replace(['id'=>'demo','nom'=>'Module de démonstration','version'=>'1.0.0','core_min'=>'2026.10.11','auteur'=>'Kidlogis','licence'=>'propriétaire','description'=>'Essai.'], $s));
/** Crée un zip ; $fichiers : nom => contenu ; $attrs : nom => attributs externes Unix. */
function zipper(string $nom, array $fichiers, array $attrs = []): string {
    global $tmp;
    $f = "$tmp/$nom.zip"; @unlink($f);
    $z = new ZipArchive(); $z->open($f, ZipArchive::CREATE);
    foreach ($fichiers as $n => $c) { $z->addFromString($n, $c); }
    foreach ($attrs as $n => $a) { $z->setExternalAttributesName($n, ZipArchive::OPSYS_UNIX, $a); }
    $z->close(); return $f;
}
$base = fn(array $plus = [], string $id = 'demo', ?string $man = null) => array_merge([
    "$id/manifeste.json" => $man ?? json_encode(['id'=>$id,'nom'=>'Module','version'=>'1.0.0','core_min'=>'2026.10.11']),
    "$id/plugin.php" => '<?php return ["nom"=>"Module"];',
    "$id/src/Classe.php" => '<?php class X {}', "$id/LICENSE" => 'Tous droits réservés',
    "$id/templates/vue.php" => '<p>x</p>', "$id/migrations/001.sql" => 'SELECT 1;',
], $plus);
$ctx = ['installees'=>[], 'core'=>'2026.10.11', 'reserves'=>['pro'], 'catalogues'=>[]];
$refuse = function (string $nom, array $fichiers, string $attendu, array $c = null, array $attrs = []) use (&$ctx) {
    $r = Z::apercu(zipper($nom, $fichiers, $attrs), "$nom.zip", $c ?? $ctx);
    ok(!$r['ok'] && str_contains((string) $r['erreur'], $attendu), "$nom : refusé (« " . mb_substr((string) $r['erreur'], 0, 70) . ' »)');
    return $r;
};

echo "Une archive saine\n";
$f = zipper('sain', $base());
$avantCopies = count(glob(sys_get_temp_dir() . '/altocompta-ext-*.zip'));
$r = Z::apercu($f, 'sain.zip', $ctx);
ok($r['ok'] && $r['id'] === 'demo' && $r['manifeste']['version'] === '1.0.0' && $r['action'] === 'installation', 'analyse réussie : installation de demo v1.0.0');
ok($r['fichiers'] === 6 && $r['sha256'] === hash_file('sha256', $f), '6 fichiers ; empreinte SHA-256 du zip calculée');
ok(count(glob(sys_get_temp_dir() . '/altocompta-ext-*.zip')) === $avantCopies, 'apercu() ne conserve aucune copie (le nombre de copies temporaires n\'a pas bougé)');
$av = glob(sys_get_temp_dir() . '/altocompta-ext-*.zip');
$a = Z::analyser($f, 'sain.zip', $ctx);
ok($a['ok'] && is_file($a['chemin']) && hash_file('sha256', $a['chemin']) === $a['sha256'], 'analyser() conserve une copie identique, pour la confirmation');
@unlink($a['chemin']);

echo "\nNom, taille, contenu illisible\n";
ok(!Z::apercu($f, 'sain.tar', $ctx)['ok'] && str_contains(Z::apercu($f, 'sain.tar', $ctx)['erreur'], '.zip'), 'un fichier qui n\'est pas un .zip : refusé');
file_put_contents("$tmp/vide.zip", ''); ok(!Z::apercu("$tmp/vide.zip", 'vide.zip', $ctx)['ok'], 'fichier vide : refusé');
file_put_contents("$tmp/faux.zip", "Ceci n'est pas une archive"); ok(str_contains(Z::apercu("$tmp/faux.zip", 'faux.zip', $ctx)['erreur'], 'illisible'), 'contenu qui n\'est pas un zip : illisible');
ok(!Z::apercu("$tmp/absent.zip", 'absent.zip', $ctx)['ok'], 'fichier absent : refusé');
$vide = new ZipArchive(); $vide->open("$tmp/rien.zip", ZipArchive::CREATE); $vide->addEmptyDir('demo'); $vide->close();
ok(!Z::apercu("$tmp/rien.zip", 'rien.zip', $ctx)['ok'], 'archive sans fichier : refusée');

echo "\nStructure : un seul dossier racine, nommé comme l'extension\n";
$refuse('deux-racines', $base() + ['autre/x.php' => '<?php'], 'un seul dossier racine');
$refuse('fichier-racine', ['plugin.php' => '<?php', 'manifeste.json' => '{}'], 'refusé');
$refuse('id-majuscule', $base([], 'Demo'), 'identifiant d\'extension valide');
$refuse('id-chiffre', $base([], '1demo'), 'identifiant d\'extension valide');
$refuse('id-reserve', $base([], 'pro'), 'réservé');
$refuse('sans-manifeste', array_diff_key($base(), ['demo/manifeste.json' => 1]), 'manifeste.json');
$refuse('sans-plugin', array_diff_key($base(), ['demo/plugin.php' => 1]), 'plugin.php');

echo "\nManifeste : lu comme des données, jamais exécuté\n";
$refuse('man-vide', $base(['demo/manifeste.json' => '']), 'manifeste.json');
$refuse('man-liste', $base(['demo/manifeste.json' => '[1,2]']), 'objet JSON');
$refuse('man-id', $base(['demo/manifeste.json' => $manifeste(['id' => 'autre'])]), 'ne correspond pas');
$refuse('man-nom', $base(['demo/manifeste.json' => $manifeste(['nom' => ''])]), 'nom');
$refuse('man-version', $base(['demo/manifeste.json' => $manifeste(['version' => 'v1'])]), 'version');
$refuse('man-core', $base(['demo/manifeste.json' => $manifeste(['core_min' => 'abc'])]), 'core_min');
$refuse('man-controle', $base(['demo/manifeste.json' => $manifeste(['nom' => "a\x01b"])]), 'nom');
$refuse('man-enorme', $base(['demo/manifeste.json' => $manifeste(['description' => str_repeat('x', 30000)])]), 'manifeste');
$marq = "$tmp/execute.txt";
$refuse('man-code', $base(['demo/plugin.php' => '<?php file_put_contents(' . var_export($marq, true) . ', "exécuté");', 'demo/manifeste.json' => '']), 'manifeste');
Z::apercu(zipper('code-ok', $base(['demo/plugin.php' => '<?php file_put_contents(' . var_export($marq, true) . ', "exécuté");'])), 'code-ok.zip', $ctx);
ok(!file_exists($marq), 'le code d\'une archive n\'est JAMAIS exécuté pendant l\'analyse (plugin.php piégé, fichier témoin absent)');

echo "\nChemins hostiles\n";
$refuse('traversee', $base(['demo/../evil.php' => '<?php']), 'Chemin suspect');
$refuse('traversee2', $base(['demo/src/../../../evil.php' => '<?php']), 'Chemin suspect');
$refuse('absolu', $base(['/etc/cron.d/x.php' => '<?php']), 'dossier racine');
$refuse('antislash', $base(['demo/sous\\evil.php' => '<?php']), 'Chemin suspect');
$refuse('double-slash', $base(['demo//x.php' => '<?php']), 'Chemin suspect');
$refuse('lecteur', $base(['C:/x.php' => '<?php']), 'dossier racine');
$refuse('lecteur-dans-la-racine', $base(['demo/C:/x.php' => '<?php']), 'Chemin suspect');
$refuse('octet-nul', $base(["demo/x\0.php" => '<?php']), 'refusé');   // le nom est tronqué à l'octet nul : plus d'extension, refusé
$refuse('lien-symbolique', $base(['demo/lien.php' => '/etc/passwd'], 'demo'), 'Lien symbolique', null, ['demo/lien.php' => 0120777 << 16]);
$refuse('nom-pirate', $base(["demo/\u{202E}gnp.php" => '<?php']), 'Chemin suspect');
$refuse('accent', $base(["demo/résumé.php" => '<?php']), 'Chemin suspect');
$refuse('espace', $base(["demo/a b.php" => '<?php']), 'Chemin suspect');
$refuse('homoglyphe', $base(["demo/\u{0430}.php" => '<?php']), 'Chemin suspect');

echo "\nFichiers cachés et types interdits\n";
$refuse('cache-htaccess', $base(['demo/.htaccess' => 'x']), 'Fichier caché');
$refuse('cache-env', $base(['demo/src/.env' => 'x']), 'Fichier caché');
$refuse('dossier-cache', $base(['demo/.git/config' => 'x']), 'Fichier caché');
foreach (['x.phtml', 'x.phar', 'x.php5', 'x.exe', 'x.sh', 'x.so', 'x.html', 'x.ini', 'x.zip', 'x.php.bak', 'Makefile', 'x.PHTML'] as $nom)
    $refuse('type-' . $nom, $base(["demo/$nom" => 'x']), 'Type de fichier refusé');
foreach (['x.php', 'x.sql', 'x.md', 'x.txt', 'x.json', 'x.css', 'x.js', 'x.svg', 'x.png', 'x.jpg', 'x.jpeg', 'x.gif', 'x.webp', 'LICENSE', 'NOTICE', 'LISEZMOI', 'X.PHP'] as $nom)
    ok(Z::apercu(zipper('ok-' . $nom, $base(["demo/sous/$nom" => 'x'])), 'ok.zip', $ctx)['ok'], "type accepté : $nom");

echo "\nTailles : limites sur ce que l'archive contient réellement\n";
$gros = array_fill(0, 601, 'x'); $f601 = [];
foreach ($gros as $i => $c) $f601["demo/f$i.txt"] = $c;
$refuse('trop-de-fichiers', $base($f601), 'Trop de fichiers');
$refuse('fichier-enorme', $base(['demo/gros.txt' => str_repeat("\0", Z::FICHIER_MAX + 1)]), 'trop volumineux');
$cinq = []; for ($i = 0; $i < 5; $i++) $cinq["demo/m$i.txt"] = str_repeat("\0", 9 * 1048576);
$refuse('somme-enorme (bombe : 45 Mo de zéros, quelques Ko compressés, refusée sans être décompressée)', $base($cinq), 'trop volumineuse');

echo "\nUne seule version : manifeste.json et plugin.php\n";
$refuse('versions-divergentes', $base(['demo/plugin.php' => "<?php return ['nom' => 'M', 'version' => '9.9.9'];"]), 'incohérente');
ok(Z::apercu(zipper('versions-accord', $base(['demo/plugin.php' => "<?php return [\n    'version'     => '1.0.0',\n];"])), 'x.zip', $ctx)['ok'], 'plugin.php et manifeste.json d\'accord : accepté');
ok(Z::apercu(zipper('versions-1-0', $base(['demo/plugin.php' => "<?php return ['version' => '1.0'];"])), 'x.zip', $ctx)['ok'], '« 1.0 » dans plugin.php et « 1.0.0 » dans le manifeste : la même version');
ok(Z::apercu(zipper('versions-dynamique', $base(['demo/plugin.php' => "<?php return ['version' => VERSION_X];"])), 'x.zip', $ctx)['ok'], 'plugin.php dont la version n\'est pas écrite en clair : pas de contrôle possible, accepté');
echo "\nVersions et compatibilité\n";
$refuse('cœur-trop-ancien', $base(), 'demande AltoCompta 2026.10.11', ['core' => '2026.10.4'] + $ctx);
ok(Z::apercu(zipper('cœur-inconnu', $base()), 'x.zip', ['core' => ''] + $ctx)['ok'], 'version du cœur inconnue : on ne bloque pas');
$r = Z::apercu(zipper('v1', $base()), 'x.zip', ['installees' => ['demo' => '0.9.0']] + $ctx);
ok($r['ok'] && $r['action'] === 'mise_a_jour' && $r['installee'] === '0.9.0', 'installée en 0.9.0, archive en 1.0.0 : mise à jour');
$r = Z::apercu(zipper('v2', $base()), 'x.zip', ['installees' => ['demo' => '1.0.0']] + $ctx);
ok($r['ok'] && $r['action'] === 'identique', 'même version : réinstallation possible');
$refuse('retrogradation', $base(), 'plus récente (1.2.0)', ['installees' => ['demo' => '1.2.0']] + $ctx);
$r = Z::apercu(zipper('v3', $base()), 'x.zip', ['installees' => ['demo' => '1.0']] + $ctx);
ok($r['ok'] && $r['action'] === 'identique', '« 1.0 » et « 1.0.0 » sont la même version');

echo "\nEmpreinte publiée par une boutique\n";
$sha = hash_file('sha256', $f);
$cat = fn(string $sha, string $ver = '1.0.0') => [['boutique'=>['nom'=>'Boutique A'],'produits'=>[['extension'=>'demo','version'=>$ver,'sha256'=>$sha]]]];
$c = Z::confronter($cat($sha), 'demo', '1.0.0', $sha);
ok($c['conforme'] === true && $c['boutique'] === 'Boutique A', 'même empreinte : conforme, avec le nom de la boutique');
ok(Z::confronter($cat(str_repeat('0', 64)), 'demo', '1.0.0', $sha)['conforme'] === false, 'empreinte différente : NON conforme');
ok(Z::confronter($cat($sha, '2.0.0'), 'demo', '1.0.0', $sha) === null, 'empreinte publiée pour une autre version : sans objet');
ok(Z::confronter($cat($sha), 'autre', '1.0.0', $sha) === null && Z::confronter([], 'demo', '1.0.0', $sha) === null, 'extension inconnue ou aucun catalogue : null');
$deux = array_merge($cat(str_repeat('0', 64)), $cat($sha));
ok(Z::confronter($deux, 'demo', '1.0.0', $sha)['conforme'] === true, 'une boutique qui reconnaît le fichier suffit');
ok(Z::apercu($f, 'x.zip', ['catalogues' => $cat(str_repeat('f', 64))] + $ctx)['catalogue']['conforme'] === false, 'l\'aperçu signale le fichier non conforme');

echo "\nInstallation\n";
$plug = "$tmp/plugins"; $sauv = "$tmp/storage/sauvegardes";
$ctxI = fn($inst = []) => ['installees' => $inst] + $ctx;
$f1 = zipper('i1', $base(['demo/src/A.php' => '<?php // v1']));
$r = Z::installer($f1, $plug, $sauv, $ctxI());
ok($r['ok'] && $r['action'] === 'installation' && $r['fichiers'] === 7 && $r['version'] === '1.0.0', 'installation : 7 fichiers écrits');
ok(is_file("$plug/demo/plugin.php") && is_file("$plug/demo/src/A.php") && is_file("$plug/demo/migrations/001.sql") && is_file("$plug/demo/LICENSE"), 'fichiers à leur place, sous plugins/demo/');
ok(!is_file($f1) , 'l\'archive en attente est supprimée après l\'installation');
ok(glob("$plug/.*") === [".", ".."] || count(array_diff(scandir($plug), ['.', '..'])) === 1, 'aucun dossier temporaire laissé');
ok(!is_dir($sauv) || !glob("$sauv/*.zip"), 'première installation : rien à sauvegarder');

$f2 = zipper('i2', $base(['demo/src/B.php' => '<?php // v2', 'demo/manifeste.json' => json_encode(['id'=>'demo','nom'=>'Module','version'=>'1.1.0'])]));
$r = Z::installer($f2, $plug, $sauv, $ctxI(['demo' => '1.0.0']));
ok($r['ok'] && $r['action'] === 'mise_a_jour' && $r['version'] === '1.1.0', 'mise à jour 1.0.0 → 1.1.0');
ok(is_file("$plug/demo/src/B.php") && !is_file("$plug/demo/src/A.php"), 'la nouvelle version remplace l\'ancienne : fichier retiré disparu, nouveau présent');
$sv = glob("$sauv/extension-demo-v1.0.0-*.zip");
ok(count($sv) === 1 && $r['sauvegarde'] === basename($sv[0]), 'sauvegarde de l\'ancienne version : ' . basename($sv[0] ?? '?'));
$zs = new ZipArchive(); $zs->open($sv[0]);
ok($zs->getFromName('demo/src/A.php') === '<?php // v1' && $zs->locateName('demo/plugin.php') !== false, 'la sauvegarde contient bien l\'ancienne version');
$zs->close();
ok(count(array_diff(scandir($plug), ['.', '..'])) === 1, 'aucun dossier .ancien ni .nouveau laissé');

$m11 = json_encode(['id'=>'demo','nom'=>'Module','version'=>'1.1.0']);
$r = Z::installer(zipper('i3', $base(['demo/manifeste.json' => $m11])), $plug, $sauv, $ctxI(['demo' => '1.1.0']));
ok($r['ok'] && $r['action'] === 'identique', 'même version : réinstallation (réparation)');
$r2 = Z::installer(zipper('i5', $base()), $plug, $sauv, $ctxI(['demo' => '1.5.0']));
ok(!$r2['ok'] && str_contains($r2['erreur'], 'plus récente') && json_decode(file_get_contents("$plug/demo/manifeste.json"), true)['version'] === '1.1.0', 'retour en arrière refusé même à l\'installation : la version installée reste la 1.1.0');

echo "\nL'installation revalide tout : on ne se fie pas à l'aperçu\n";
$avant = md5(implode('', array_map('file_get_contents', glob("$plug/demo/src/*.php"))));
foreach ([['traversée', $base(['demo/../../evil.php' => '<?php'])], ['lien', null], ['type interdit', $base(['demo/x.phtml' => 'x'])], ['caché', $base(['demo/.htaccess' => 'x'])]] as [$nom, $fich]) {
    $zf = $fich === null ? zipper('inst-lien', $base(['demo/l.php' => 'x']), ['demo/l.php' => 0120777 << 16]) : zipper('inst-' . md5($nom), $fich);
    $r = Z::installer($zf, $plug, $sauv, $ctxI(['demo' => '1.1.0']));
    ok(!$r['ok'], "archive piégée ($nom) refusée à l'installation");
}
ok(!file_exists("$tmp/evil.php") && !file_exists(dirname($plug) . '/evil.php'), 'aucun fichier n\'est sorti du dossier');
ok($avant === md5(implode('', array_map('file_get_contents', glob("$plug/demo/src/*.php")))), 'et l\'extension installée n\'a pas été touchée');
ok(count(array_diff(scandir($plug), ['.', '..'])) === 1, 'aucun dossier temporaire laissé par les refus');
$r = Z::installer("$tmp/inexistant.zip", $plug, $sauv, $ctxI());
ok(!$r['ok'] && str_contains($r['erreur'], 'introuvable'), 'archive disparue entre l\'aperçu et la confirmation : message clair');
$r = Z::installer(zipper('i6', $base([], 'neuf')), "$tmp/plugins-absent", $sauv, $ctxI());
ok($r['ok'] && is_file("$tmp/plugins-absent/neuf/plugin.php"), 'dossier plugins/ absent : créé');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
