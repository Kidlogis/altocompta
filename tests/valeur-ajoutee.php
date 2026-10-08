<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Declaration2035E as E;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Seuil d'exigibilité\n";
ok(E::SEUIL_RECETTES === 152500.0, 'seuil de 152 500 €');
ok(E::exigee(200000.0) === true,  'au-delà : annexe exigée');
ok(E::exigee(152500.0) === false, 'au seuil exact : non exigée');
ok(E::exigee(80000.0)  === false, 'en deçà : non exigée');

echo "\nCouverture des postes de dépense\n";
$conso  = array_merge(...array_values(E::CONSOMMATIONS));
$exclus = array_keys(E::EXCLUS);
$tous = ['BA','BB','BC','BD','BS','BE','BF','BG','BH','BJ','BK','BM','BN','BP','BR','BV',
         'BW','BX','BY','BZ','CA','CB','CC','CD','CH'];
ok(array_diff($tous, array_merge($conso, $exclus)) === [],
   'les 25 postes sont classés, aucun ne passe à la trappe');
ok(array_intersect($conso, $exclus) === [], 'aucun poste classé deux fois');

echo "\nClassement des postes structurants\n";
$dans = static fn(string $c, array $liste) => in_array($c, $liste, true);
ok($dans('BA', $conso),  'BA achats : consommation de tiers');
ok($dans('BF', $conso),  'BF loyer : consommation de tiers');
ok($dans('BN', $conso),  'BN honoraires versés : consommation de tiers');
ok($dans('BW', $exclus), 'BW charges sociales personnelles : hors VA');
ok($dans('BB', $exclus), 'BB salaires : hors VA');
ok($dans('CH', $exclus), 'CH amortissements : hors VA');
ok($dans('CC', $exclus), 'CC charges financières : hors VA');

echo "\nCalcul de la valeur ajoutée\n";
$calc = ['total_recettes' => 200000.0, 'depenses' => [
    'BA' => ['total' => 4000.0], 'BF' => ['total' => 6000.0],
    'BY' => ['total' => 2400.0], 'BN' => ['total' => 1800.0],
    'BW' => ['total' => 18000.0], 'CC' => ['total' => 350.0],
]];
$va = E::calculer($calc);
ok($va['total_consommations'] === 14200.0, 'consommations 14 200 (4 000+6 000+2 400+1 800)');
ok($va['valeur_ajoutee'] === 185800.0,     'VA = 200 000 − 14 200 = 185 800');
ok(count($va['exclus']) === 2,             'BW et CC signalés comme exclus');
ok($va['exigee'] === true,                 'annexe exigée à ce niveau de recettes');
ok(E::postesNonClasses($calc) === [],      'aucun poste non classé');

echo "\nPoste inconnu détecté\n";
$calc2 = ['total_recettes' => 200000.0, 'depenses' => ['ZZ' => ['total' => 100.0]]];
ok(E::postesNonClasses($calc2) === ['ZZ'], 'un poste hors référentiel est signalé');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
