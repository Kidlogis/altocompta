<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Montant;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "lire() — montants en euros, heuristique des milliers\n";
ok(Montant::lire('1 440,00') === 1440.0,  '"1 440,00" -> 1440');
ok(Montant::lire('1,250')    === 1250.0,  '"1,250" -> 1250 (milliers, correct pour des euros)');
ok(Montant::lire('12,50')    === 12.5,    '"12,50" -> 12,50');

echo "\nlireDecimal() — coefficients, virgule toujours decimale\n";
$cas = [
  ['0,7000', 0.7], ['0,529', 0.529], ['0,6360', 0.636], ['1,25', 1.25],
  ['0.427', 0.427], ['1 065,00', 1065.0], ['1.065,50', 1065.5],
  ['-0,5', -0.5], ['', null], ['abc', null],
];
foreach ($cas as [$in, $att]) {
  $got = Montant::lireDecimal($in);
  ok($got === $att || (is_float($att) && is_float($got) && abs($got - $att) < 1e-9),
     sprintf('"%s" -> %s', $in, var_export($got, true)));
}

echo "\nLes deux divergent la ou il faut\n";
ok(Montant::lire('0,529') === 529.0 && Montant::lireDecimal('0,529') === 0.529,
   'un coefficient doit passer par lireDecimal, jamais par lire');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
