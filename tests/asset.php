<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\View;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
$racine = dirname(__DIR__);
$version = trim((string) file_get_contents($racine . '/VERSION'));

echo "Le fichier est trouvé par le serveur web, où qu'il soit\n";
$tmp = sys_get_temp_dir() . '/asset-' . getmypid(); @mkdir("$tmp/assets", 0755, true);
file_put_contents("$tmp/assets/neuf.js", 'x'); touch("$tmp/assets/neuf.js", 1700000000);
$_SERVER['DOCUMENT_ROOT'] = $tmp;
ok(View::asset('/assets/neuf.js') === '/assets/neuf.js?v=1700000000', 'dossier public ANOMMÉ (public_html, www, htdocs…) : trouvé par DOCUMENT_ROOT, daté de sa modification');
touch("$tmp/assets/neuf.js", 1700000500);
clearstatcache(true);   // PHP met en cache les dates de fichier dans un même processus ; entre deux requêtes, ça n'arrive pas
ok(View::asset('/assets/neuf.js') === '/assets/neuf.js?v=1700000500', 'le fichier change → le numéro change → le navigateur le recharge');

echo "\nDOCUMENT_ROOT absent ou ailleurs : le dossier public du dépôt\n";
$_SERVER['DOCUMENT_ROOT'] = '';
$v = View::asset('/assets/app.js');
ok(preg_match('#^/assets/app\.js\?v=\d{9,}$#', $v) === 1, 'ligne de commande (pas de DOCUMENT_ROOT) : trouvé dans public/, daté : ' . $v);
$_SERVER['DOCUMENT_ROOT'] = $tmp;
ok(preg_match('#^/assets/app\.css\?v=\d{9,}$#', View::asset('/assets/app.css')) === 1, 'DOCUMENT_ROOT qui ne contient pas ce fichier : on cherche ensuite dans public/');

echo "\nDernier recours : le numéro de version de l'application\n";
ok(View::asset('/assets/inexistant.js') === '/assets/inexistant.js?v=' . rawurlencode($version), 'fichier introuvable partout : « ?v=' . $version . ' » — il change à chaque mise à jour, c\'est déjà ce qu\'il faut');
ok(str_contains(View::asset('/assets/inexistant.js'), '?v='), 'jamais sans numéro de version : plus de cache périmé silencieux');

exec('rm -rf ' . escapeshellarg($tmp));
echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
