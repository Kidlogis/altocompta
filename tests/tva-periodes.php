<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\TvaPeriodes as P;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
$dec = fn($d, $f, $p) => P::decouper($d, $f, $p);
$lib = fn(array $l) => array_column($l, 'libelle');

echo "Périodes d'un exercice civil\n";
$m = $dec('2026-01-01', '2026-12-31', 'mensuelle');
ok(count($m) === 12 && $m[0]['debut'] === '2026-01-01' && $m[0]['fin'] === '2026-01-31' && $m[1]['fin'] === '2026-02-28' && $m[11]['fin'] === '2026-12-31', 'mensuelle : 12 périodes, février 2026 finit le 28');
ok($lib($m)[0] === 'Janvier 2026' && $lib($m)[7] === 'Août 2026' && $lib($m)[11] === 'Décembre 2026', 'libellés en français : Janvier 2026, Août 2026, Décembre 2026');
ok(!array_filter($m, fn($x) => $x['partielle']), 'aucune période partielle');
ok($dec('2028-01-01', '2028-12-31', 'mensuelle')[1]['fin'] === '2028-02-29', 'année bissextile : février 2028 finit le 29');
$q = $dec('2026-01-01', '2026-12-31', 'trimestrielle');
ok(count($q) === 4 && $lib($q) === ['T1 2026', 'T2 2026', 'T3 2026', 'T4 2026'], 'trimestrielle : T1 à T4');
ok($q[0]['fin'] === '2026-03-31' && $q[1]['debut'] === '2026-04-01' && $q[1]['fin'] === '2026-06-30' && $q[2]['fin'] === '2026-09-30' && $q[3]['fin'] === '2026-12-31', 'bornes des trimestres : 31/03, 30/06, 30/09, 31/12');
$a = $dec('2026-01-01', '2026-12-31', 'annuelle');
ok(count($a) === 1 && $a[0]['libelle'] === 'Exercice 2026' && $a[0]['debut'] === '2026-01-01' && $a[0]['fin'] === '2026-12-31', 'annuelle : une période, l\'exercice');

echo "\nExercices qui ne sont pas un calendrier\n";
$m = $dec('2026-04-15', '2026-12-31', 'mensuelle');
ok(count($m) === 9 && $m[0]['debut'] === '2026-04-15' && $m[0]['fin'] === '2026-04-30' && $m[0]['partielle'] === true && $m[1]['partielle'] === false, 'premier exercice commencé le 15 avril : 9 mois, le premier partiel (15 → 30 avril)');
$q = $dec('2026-04-15', '2026-12-31', 'trimestrielle');
ok(count($q) === 3 && $q[0]['libelle'] === 'T2 2026' && $q[0]['debut'] === '2026-04-15' && $q[0]['fin'] === '2026-06-30' && $q[0]['partielle'] === true, 'trimestrielle : T2 partiel (15/04 → 30/06), puis T3 et T4');
$q = $dec('2026-01-01', '2026-05-10', 'trimestrielle');
ok(count($q) === 2 && $q[1]['debut'] === '2026-04-01' && $q[1]['fin'] === '2026-05-10' && $q[1]['partielle'] === true && $q[0]['partielle'] === false, 'exercice qui se termine le 10 mai : T2 partiel (01/04 → 10/05)');
$q = $dec('2026-07-01', '2027-06-30', 'trimestrielle');
ok($lib($q) === ['T3 2026', 'T4 2026', 'T1 2027', 'T2 2027'] && !array_filter($q, fn($x) => $x['partielle']), 'exercice juillet → juin : T3, T4, T1, T2, tous complets');
$m = $dec('2026-07-01', '2027-06-30', 'mensuelle');
ok(count($m) === 12 && $lib($m)[0] === 'Juillet 2026' && $lib($m)[11] === 'Juin 2027', 'mensuelle à cheval sur deux années : Juillet 2026 → Juin 2027');
ok($dec('2026-07-01', '2027-06-30', 'annuelle')[0]['libelle'] === 'Exercice 2026–2027', 'libellé d\'un exercice à cheval : 2026–2027');
ok(count($dec('2026-12-31', '2026-12-31', 'mensuelle')) === 1 && $dec('2026-12-31', '2026-12-31', 'mensuelle')[0]['debut'] === '2026-12-31', 'exercice d\'un seul jour : une période');
ok($dec('2026-12-31', '2026-01-01', 'mensuelle') === [], 'dates inversées : aucune période');

echo "\nPropriété : les périodes recouvrent l'exercice, sans trou ni chevauchement\n";
foreach ([['2026-01-01','2026-12-31'], ['2026-04-15','2026-12-31'], ['2026-01-01','2026-05-10'], ['2026-07-01','2027-06-30'], ['2028-02-10','2029-02-09'], ['2026-03-31','2026-04-01']] as [$d, $f])
    foreach (['mensuelle', 'trimestrielle', 'annuelle'] as $pe) {
        $l = $dec($d, $f, $pe); $trou = false;
        for ($i = 1; $i < count($l); $i++) if ((new DateTimeImmutable($l[$i-1]['fin']))->modify('+1 day')->format('Y-m-d') !== $l[$i]['debut']) $trou = true;
        ok($l && $l[0]['debut'] === $d && end($l)['fin'] === $f && !$trou, "$pe $d → $f : " . count($l) . ' période(s), contiguës, de bout en bout');
    }

echo "\nEnchaînement du crédit de TVA\n";
$per = fn(array $nets) => array_map(fn($n) => ['collectee_c' => max($n, 0), 'deductible_c' => max(-$n, 0), 'acquisitions_c' => 0], $nets);
[$r, $fin] = P::enchainer($per([10000, -30000, 5000, 40000]), 0);
ok(array_column($r, 'a_payer_c') === [10000, 0, 0, 15000] && array_column($r, 'credit_sortant_c') === [0, 30000, 25000, 0] && array_column($r, 'credit_entrant_c') === [0, 0, 30000, 25000] && $fin === 0,
   '100 à payer ; −300 → crédit 300 ; +50 consomme le crédit (250 restent) ; +400 − 250 = 150 à payer');
[$r, $fin] = P::enchainer($per([20000]), 50000);
ok($r[0]['a_payer_c'] === 0 && $r[0]['credit_sortant_c'] === 30000 && $fin === 30000, 'crédit d\'ouverture de 500 et 200 de TVA nette : rien à payer, 300 de crédit restent');
[$r, $fin] = P::enchainer($per([20000]), 20000);
ok($r[0]['a_payer_c'] === 0 && $r[0]['credit_sortant_c'] === 0 && $fin === 0, 'crédit exactement égal à la TVA nette : ni paiement ni crédit');
[$r] = P::enchainer($per([20000]), -9999);
ok($r[0]['a_payer_c'] === 20000 && $r[0]['credit_entrant_c'] === 0, 'un « crédit » négatif ne se transforme pas en dette cachée : il est ignoré');
[$r, $fin] = P::enchainer($per([0, 0]), 0);
ok($r[1]['a_payer_c'] === 0 && $r[1]['credit_sortant_c'] === 0 && $fin === 0, 'périodes sans TVA : rien');
[$r] = P::enchainer([['collectee_c' => 10000, 'deductible_c' => 2000, 'acquisitions_c' => 3000]], 0);
ok($r[0]['net_c'] === 5000 && $r[0]['a_payer_c'] === 5000, 'les acquisitions se déduisent comme les dépenses : 100 − 20 − 30 = 50');
[$r, $fin] = P::enchainer([], 12345);
ok($r === [] && $fin === 12345, 'aucune période : le crédit d\'ouverture est conservé tel quel');
mt_srand(42); $tous = true;
for ($k = 0; $k < 300; $k++) {
    $nets = array_map(fn() => mt_rand(-200000, 200000), range(1, mt_rand(1, 12))); $init = mt_rand(0, 100000);
    [$r, $fin] = P::enchainer($per($nets), $init);
    // invariant comptable : somme des nets − crédit d'ouverture = somme à payer − crédit final
    if (array_sum($nets) - $init !== array_sum(array_column($r, 'a_payer_c')) - $fin) $tous = false;
    foreach ($r as $x) if ($x['a_payer_c'] > 0 && $x['credit_sortant_c'] > 0 || $x['a_payer_c'] < 0 || $x['credit_sortant_c'] < 0) $tous = false;
}
ok($tous, '300 tirages aléatoires : somme des nets − crédit d\'ouverture = Σ à payer − crédit final, jamais payer ET reporter à la fois, jamais négatif');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
