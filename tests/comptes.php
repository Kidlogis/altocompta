<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\CompteBancaire;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

// Ces tests portent sur la logique de reconnaissance et d'appariement,
// reproduite ici à l'identique : ils tournent sans base de données.

echo "Normalisation des libellés de compte\n";
$normaliser = static fn(string $s) => mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
ok($normaliser('Qonto ···4417')  === 'QONTO4417', 'ponctuation et espaces retirés');
ok($normaliser('FR76 3000 4000') === 'FR7630004000', 'IBAN espacé normalisé');
ok($normaliser('CA — 0932')      === 'CA0932', 'tirets longs retirés');

echo "\nCorrespondance d'un motif dans un libellé de relevé\n";
$correspond = static function (string $motif, string $releve) use ($normaliser): bool {
    $m = $normaliser($motif);
    return $m !== '' && str_contains($normaliser($releve), $m);
};
ok($correspond('Qonto 4417', 'Qonto ···4417'),          'motif avec ponctuation différente');
ok($correspond('4417', 'Compte Qonto 4417 EUR'),        'fin d\'IBAN retrouvée dans le libellé');
ok($correspond('CREDIT AGRICOLE', 'Crédit Agricole Pro') === false, 'accent non normalisé : pas de correspondance');
ok(!$correspond('0932', 'Qonto 4417'),                  'compte différent : pas de correspondance');

echo "\nAppariement d'un virement interne\n";
$apparie = static function (array $a, array $b, int $tolerance = 5): bool {
    if ((int) $a['compte_id'] === (int) $b['compte_id']) return false;
    if (abs((float) $a['montant'] + (float) $b['montant']) >= 0.005) return false;
    $ecart = abs((strtotime($a['date']) - strtotime($b['date'])) / 86400);
    return $ecart <= $tolerance;
};
$debit  = ['compte_id'=>1,'montant'=>-2000.00,'date'=>'2026-03-12'];
$credit = ['compte_id'=>2,'montant'=> 2000.00,'date'=>'2026-03-12'];
ok($apparie($debit,$credit), 'même montant, sens opposé, comptes distincts, même jour');
ok($apparie($debit, ['compte_id'=>2,'montant'=>2000.00,'date'=>'2026-03-15']), 'écart de 3 jours toléré');
ok(!$apparie($debit, ['compte_id'=>2,'montant'=>2000.00,'date'=>'2026-03-25']), 'écart de 13 jours refusé');
ok(!$apparie($debit, ['compte_id'=>1,'montant'=>2000.00,'date'=>'2026-03-12']), 'même compte : pas un virement interne');
ok(!$apparie($debit, ['compte_id'=>2,'montant'=>1999.00,'date'=>'2026-03-12']), 'montant différent refusé');
ok(!$apparie($debit, ['compte_id'=>2,'montant'=>-2000.00,'date'=>'2026-03-12']), 'même sens refusé');

echo "\nNatures de compte\n";
ok(count(CompteBancaire::NATURES) === 3, 'trois natures');
ok(isset(CompteBancaire::NATURES['professionnel']), 'plusieurs comptes professionnels possibles');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
