<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\{Boutique as B, Config};
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
$errs = fn($j, $integree = false) => B::valider($j, $integree)[1];
$valide = ['schema'=>1,'boutique'=>['id'=>'ma-boutique','nom'=>'Ma boutique'],'produits'=>[
  ['id'=>'mod','titre'=>'Mon module','url_achat'=>'https://exemple.fr/mod','prix'=>['montant'=>29.9]]]];
$produit = fn(array $surcharge) => ['produits' => [array_replace($valide['produits'][0], $surcharge)]] + $valide;

echo "Le catalogue livré avec l'application\n";
$brut = json_decode(file_get_contents(__DIR__ . '/../boutique/kidlogis.json'), true);
[$s, $e] = B::valider($brut, true);
ok($s !== null && !$e, 'le catalogue de Kidlogis est valide : ' . implode(' ', $e));
ok(count($s['produits']) === 4, 'quatre produits');
$prix = array_map(fn($p) => B::prixAffiche($p['prix']), $s['produits']);
ok($prix === ['29,90 €', '29,90 €', '29,90 €', 'Gratuit'], 'prix : trois à 29,90 €, un gratuit (' . implode(' | ', $prix) . ')');
foreach ($s['produits'] as $p) {
    ok(str_starts_with($p['image'], 'images/') && is_file(__DIR__ . '/../boutique/' . $p['image']), 'image présente, hors du dossier public : ' . $p['image']);
    ok(B::urlHttps($p['url_achat']) && str_starts_with($p['url_achat'], 'https://kidlogis.fr/'), 'lien d\'achat en https sur kidlogis.fr : ' . $p['extension']);
}
ok(array_column($s['produits'], 'extension') === ['immobilisations', 'altoviz', 'evoliz', 'domicile'], 'identifiants d\'extension : ceux des dossiers de plugins');
foreach (array_column($s['produits'], 'extension') as $id) ok(is_dir(__DIR__ . "/../plugins/$id"), "le dossier plugins/$id existe");

echo "\nFormat général\n";
ok(B::valider($valide, false)[0] !== null, 'un catalogue minimal est accepté');
ok(str_contains($errs('pas du json')[0], 'objet JSON') && str_contains($errs([1,2])[0], 'objet JSON'), 'ni un texte ni une liste');
ok(str_contains($errs(['boutique'=>[]] )[0], 'schéma'), 'schéma absent : refusé');
ok(str_contains($errs(['schema'=>2] + $valide)[0], 'schéma « 2 »'), 'schéma plus récent : refusé, avec le numéro');
ok(str_contains($errs(['schema'=>1,'produits'=>[]])[0], 'boutique'), 'bloc boutique absent');
ok($errs(['schema'=>1,'boutique'=>$valide['boutique']]) !== [], 'liste de produits absente');
ok($errs(['boutique'=>['id'=>'Maj !','nom'=>'x']] + $valide) !== [] && $errs(['boutique'=>['id'=>'ok-id']] + $valide) !== [], 'identifiant de boutique invalide, nom absent');
$trop = ['produits'=>array_fill(0, 101, $valide['produits'][0])] + $valide;
ok(str_contains($errs($trop)[0], 'Trop de produits'), '101 produits : refusé');
ok(count($errs(['produits'=>[$valide['produits'][0], $valide['produits'][0]]] + $valide)) === 1 && str_contains($errs(['produits'=>[$valide['produits'][0], $valide['produits'][0]]] + $valide)[0], 'déjà utilisé'), 'deux produits au même identifiant : refusé');
ok(B::valider(['extra'=>['x'=>1]] + $valide, false)[0] !== null, 'champs inconnus ignorés : le format peut évoluer');

echo "\nLiens et images : tout ce qui n'est pas du https public est refusé\n";
foreach (['http://exemple.fr/x', 'javascript:alert(1)', 'data:text/html,<script>', '//exemple.fr/x', 'ftp://exemple.fr', 'https://user:pass@exemple.fr/', 'https://exemple.fr/"onclick="x', "https://exemple.fr/<script>", 'https://exemple.fr/é', 'https://exemple.fr/a b', 'https://', str_repeat('a', 600)] as $u)
    ok($errs($produit(['url_achat' => $u])) !== [], 'url_achat refusée : ' . mb_substr($u, 0, 45));
ok($errs($produit(['url_achat' => 'https://exemple.fr/p?a=1&b=2#x'])) === [], 'une adresse https ordinaire avec paramètres : acceptée');
ok($errs($produit(['image' => 'images/x.webp'])) !== [], 'image locale refusée pour une boutique importée (elle n\'a pas de fichiers ici)');
ok($errs($produit(['image' => 'images/x.webp']), true) === [], '...acceptée pour la boutique intégrée');
foreach (['images/../../config/config.php', 'images/x.php', '/etc/passwd', 'images/.htaccess', 'images/sous/x.webp', '/assets/boutique/x.webp', '../images/x.webp', 'images/'] as $img)
    ok($errs($produit(['image' => $img]), true) !== [], 'image locale refusée : ' . $img);
ok($errs($produit(['image' => 'https://cdn.exemple.fr/c.webp'])) === [], 'image distante en https : acceptée');

echo "\nImages servies par l'application\n";
ok(B::cheminImage('immobilisations.webp') !== null && str_ends_with(B::cheminImage('altoviz.webp'), '/boutique/images/altoviz.webp'), 'une image livrée est retrouvée dans boutique/images/');
foreach (['../kidlogis.json', '..%2fkidlogis.json', '../../config/config.example.php', 'kidlogis.json', '.htaccess', 'x.php', 'absente.webp', '', 'a/b.webp', 'immobilisations.webp/../../x', "immobilisations.webp\0.php"] as $n)
    ok(B::cheminImage($n) === null, 'nom d\'image refusé : ' . json_encode($n));
ok(B::typeImage('x.webp') === 'image/webp' && B::typeImage('x.PNG') === 'image/png' && B::typeImage('x.jpeg') === 'image/jpeg', 'types d\'image');
ok(B::urlImage('images/altoviz.webp') === '/boutique/image/altoviz.webp' && B::urlImage('https://cdn.exemple.fr/c.webp') === 'https://cdn.exemple.fr/c.webp', 'adresse d\'une image locale (la route) ou distante (telle quelle)');

echo "\nTextes : jamais interprétés, jamais corrigés en silence\n";
[$s] = B::valider($produit(['titre' => '<script>alert(1)</script>']), false);
ok($s['produits'][0]['titre'] === '<script>alert(1)</script>', 'du HTML est conservé tel quel (il sera échappé à l\'affichage, jamais supprimé en douce)');
ok($errs($produit(['titre' => str_repeat('é', 101)])) !== [], 'titre de 101 caractères : refusé (comptés en lettres, pas en octets)');
ok($errs($produit(['titre' => "a\x00b"])) !== [] && $errs($produit(['titre' => "a\x1bb"])) !== [], 'caractères de contrôle refusés');
ok($errs($produit(['description' => "ligne 1\nligne 2"])) === [], 'une description peut avoir des retours à la ligne');
ok($errs($produit(['description' => str_repeat('x', 4001)])) !== [], 'description de 4 001 caractères : refusée');
ok($errs($produit(['titre' => ''])) !== [] && $errs($produit(['titre' => 12])) !== [], 'titre vide ou non textuel : refusé');
ok($errs($produit(['points_forts' => array_fill(0, 13, 'x')])) !== [] && $errs($produit(['points_forts' => 'x'])) !== [], 'plus de 12 points forts, ou pas une liste : refusé');
ok($errs($produit(['version' => '1.0.0', 'core_min' => '2026.10.11'])) === [] && $errs($produit(['version' => 'v1'])) !== [] && $errs($produit(['core_min' => '1.x'])) !== [], 'numéros de version');
ok($errs($produit(['sha256' => str_repeat('a', 64)])) === [] && $errs($produit(['sha256' => str_repeat('A', 64)])) !== [] && $errs($produit(['sha256' => 'abc'])) !== [], 'empreinte : 64 caractères hexadécimaux minuscules');

echo "\nPrix\n";
ok($errs($produit(['prix' => ['montant' => -1]])) !== [] && $errs($produit(['prix' => ['montant' => 1e9]])) !== [] && $errs($produit(['prix' => ['montant' => 'abc']])) !== [], 'montant négatif, démesuré ou non numérique : refusé');
ok($errs($produit(['prix' => ['devise' => 'eur']])) !== [] && $errs($produit(['prix' => ['periodicite' => 'jamais']])) !== [], 'devise ou périodicité invalides');
$pa = fn($p) => B::prixAffiche(B::valider($produit(['prix' => $p]), false)[0]['produits'][0]['prix']);
ok($pa(['montant' => 0]) === 'Gratuit' && $pa(['montant' => 49]) === '49,00 €' && $pa(['montant' => 1234.5]) === '1 234,50 €', 'Gratuit, 49,00 €, 1 234,50 €');
ok($pa(['montant' => 9, 'periodicite' => 'annuel']) === '9,00 € par an' && $pa(['montant' => 5, 'devise' => 'USD']) === '5,00 $', 'périodicité et devise');
ok($pa(['libelle' => 'Sur devis']) === 'Sur devis' && $pa(['montant' => 10, 'libelle' => 'Dès 10 €']) === 'Dès 10 €', 'le libellé du vendeur l\'emporte');
ok($pa([]) === '', 'pas de prix : rien n\'est affiché');
ok($pa(['montant' => '29.90']) === '29,90 €', 'montant en texte « 29.90 » accepté');

echo "\nÉtat d'un produit sur cette installation\n";
$p = ['extension'=>'mod','version'=>'1.1.0','core_min'=>'2026.10.11'];
$e = B::etat($p, [], [], '2026.10.11');
ok($e['statut'] === 'absente' && !$e['maj'] && $e['compatible'], 'non installée, compatible');
$e = B::etat($p, ['mod'=>['version'=>'1.0.0']], ['mod'=>['actif'=>1]], '2026.10.11');
ok($e['statut'] === 'active' && $e['installee'] === '1.0.0' && $e['maj'] === true, 'installée et active, mise à jour 1.0.0 → 1.1.0 disponible');
$e = B::etat($p, ['mod'=>['version'=>'1.1.0']], ['mod'=>['actif'=>0]], '2026.10.11');
ok($e['statut'] === 'inactive' && !$e['maj'], 'inactive, à jour');
ok(B::etat($p, ['mod'=>['version'=>'1.2.0']], [], '2026.10.11')['maj'] === false, 'une version installée plus récente que le catalogue : pas de « mise à jour »');
ok(B::etat($p, [], [], '2026.10.4')['compatible'] === false && B::etat($p, [], [], '')['compatible'] === true, 'cœur trop ancien : incompatible ; version du cœur inconnue : on ne bloque pas');
ok(B::etat(['extension'=>'mod'], ['mod'=>['version'=>'1.0.0']], [], '1')['maj'] === false, 'produit sans version : jamais de mise à jour annoncée');

echo "\nComparaison de versions\n";
ok(B::comparerVersions('1.0', '1.0.0') === 0 && B::comparerVersions('1', '1.0.0.0') === 0, '« 1.0 », « 1.0.0 » et « 1 » sont la même version');
ok(B::comparerVersions('1.10.0', '1.9.0') === 1 && B::comparerVersions('2026.10.4', '2026.10.11') === -1, '1.10 > 1.9 (comparaison numérique) ; 2026.10.4 < 2026.10.11');
ok(B::comparerVersions('2026.10.11', '2026.10.11') === 0, 'égalité');
echo "\nAdresses : le serveur ne doit pas servir de relais vers l'intérieur\n";
foreach (['127.0.0.1', '127.1.2.3', '10.0.0.5', '172.16.0.1', '172.31.255.255', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '192.0.2.1', '198.18.0.1', '::1', 'fe80::1', 'fc00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1'] as $ip)
    ok(!B::ipPublique($ip), "adresse interne ou réservée refusée : $ip");
foreach (['8.8.8.8', '93.184.216.34', '1.1.1.1', '2001:4860:4860::8888', '::ffff:8.8.8.8'] as $ip)
    ok(B::ipPublique($ip), "adresse publique acceptée : $ip");
$cible = fn($u) => B::cible($u);
foreach (['http://93.184.216.34/x.json', 'ftp://93.184.216.34/', 'https://user:pass@93.184.216.34/', 'https://93.184.216.34:8443/x', 'https://127.0.0.1/', 'https://[::1]/', 'https://169.254.169.254/latest/meta-data/', 'https://localhost/', 'https://2130706433/', 'https://0x7f.0.0.1/', 'https://exa mple.fr/', '', 'https://', str_repeat('https://a/', 80)] as $u)
    ok($cible($u)[0] === null && $cible($u)[1] !== '', 'adresse refusée : ' . mb_substr($u, 0, 48));
[$c, $err] = $cible('https://93.184.216.34/boutique.json');
ok($c !== null && $c['ip'] === '93.184.216.34' && $c['port'] === 443 && $c['https'] === true, 'une adresse https publique : acceptée, IP figée pour la connexion');
file_put_contents($f = sys_get_temp_dir() . '/cfg-test-' . getmypid() . '.php', '<?php return ["boutique" => ["reseau_prive" => true]];');
Config::charger($f);
[$c] = $cible('http://127.0.0.1:3999/boutique.json');
ok($c !== null && $c['port'] === 3999 && $c['https'] === false, 'réglage boutique.reseau_prive (essais en local) : http et réseau privé permis');
ok($cible('https://user:pass@127.0.0.1/')[0] === null, '...mais les identifiants dans l\'adresse restent refusés');
unlink($f);

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
