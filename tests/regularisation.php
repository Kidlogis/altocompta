<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Regularisation as R;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Sens disponibles\n";
ok(count(R::SENS) === 2, 'deux sens');
ok(isset(R::SENS['charge_a_payer']), 'charge à payer');
ok(isset(R::SENS['produit_a_recevoir']), 'produit à recevoir');

/* Le mécanisme de contre-passation, reproduit ici pour tourner sans base :
   une régularisation pèse positivement sur son exercice et négativement
   sur le suivant, ce qui neutralise le paiement réel quand il survient. */
$impact = static function (array $reguls, int $annee): float {
    $total = 0.0;
    foreach ($reguls as $r) {
        $signe = $r['sens'] === 'charge_a_payer' ? 1 : -1;
        if ($r['annee'] === $annee) {
            $total += $signe * $r['montant'];
        } elseif ($r['annee'] === $annee - 1 && $r['contrepasser']) {
            $total -= $signe * $r['montant'];
        }
    }
    return round($total, 2);
};

$reguls = [['annee'=>2025,'sens'=>'charge_a_payer','montant'=>2400.0,'contrepasser'=>true]];

echo "\nCharge à payer de 2 400 saisie en 2025\n";
ok($impact($reguls, 2025) === 2400.0,  '2025 : +2 400 de dépenses');
ok($impact($reguls, 2026) === -2400.0, '2026 : −2 400, neutralise le paiement réel');
ok($impact($reguls, 2027) === 0.0,     '2027 : plus aucun effet');

echo "\nRésultat sur deux exercices\n";
$res2025 = 40000.0 - (0.0 + $impact($reguls, 2025));
$res2026 = 50000.0 - (2400.0 + $impact($reguls, 2026));
ok($res2025 === 37600.0, '2025 : 40 000 − 2 400 = 37 600');
ok($res2026 === 50000.0, '2026 : 50 000 − (2 400 payés − 2 400 contre-passés) = 50 000');

echo "\nSans contre-passation : la double déduction\n";
$sans = [['annee'=>2025,'sens'=>'charge_a_payer','montant'=>2400.0,'contrepasser'=>false]];
ok($impact($sans, 2026) === 0.0, '2026 : aucune neutralisation');
ok(50000.0 - (2400.0 + $impact($sans, 2026)) === 47600.0,
   '2026 : 47 600 — la cotisation est déduite deux fois');

echo "\nProduit à recevoir\n";
$pr = [['annee'=>2025,'sens'=>'produit_a_recevoir','montant'=>1000.0,'contrepasser'=>true]];
ok($impact($pr, 2025) === -1000.0, '2025 : effet inverse d\'une charge');
ok($impact($pr, 2026) === 1000.0,  '2026 : contre-passé');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
