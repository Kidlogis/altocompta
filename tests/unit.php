<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Config; use Bnc\Crypto; use Bnc\View;

// Config minimale en mémoire
$ref = new ReflectionClass(Config::class);
$p = $ref->getProperty('valeurs'); $p->setAccessible(true);
$p->setValue(null, ['cle_maitre' => Crypto::genererCleMaitre()]);

$ko = 0;
function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Crypto\n";
$clair = 'av_live_9f2c4d8e1b7a3056';
$paq = Crypto::chiffrer($clair);
ok(Crypto::dechiffrer($paq) === $clair, 'aller-retour chiffrement');
ok(Crypto::chiffrer($clair) !== $paq, 'nonce aleatoire : deux chiffrements different');
try { Crypto::dechiffrer(substr($paq,0,-3).'xyz'); ok(false,'alteration rejetee'); }
catch (\Throwable $e) { ok(true,'alteration rejetee'); }

echo "Empreinte\n";
$a = Crypto::empreinteTransaction(1,'2026-08-12','1440.00','VIR SEPA  OF VENDEE','Qonto');
$b = Crypto::empreinteTransaction(1,'2026-08-12','1440.0',' vir sepa of vendee ','Qonto');
ok($a === $b, 'normalisation casse/espaces/decimales -> meme empreinte');
ok($a !== Crypto::empreinteTransaction(2,'2026-08-12','1440.00','VIR SEPA  OF VENDEE','Qonto'), 'cloisonnement par user_id');
ok($a !== Crypto::empreinteTransaction(1,'2026-08-12','1440.01','VIR SEPA  OF VENDEE','Qonto'), 'montant different -> empreinte differente');

echo "Formatage\n";
ok(View::montant(1440) === '1 440,00', 'montant 1 440,00');
ok(View::montant(-780.5, true) === '−780,50', 'montant negatif signe');
ok(View::montant(38412.6, true) === '+38 412,60', 'montant positif signe');
ok(View::date('2026-08-12') === '12/08/2026', 'date jj/mm/aaaa');
ok(View::date(null) === '—', 'date nulle');
ok(View::e('<b>&"') === '&lt;b&gt;&amp;&quot;', 'echappement html');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko ? 1 : 0);
