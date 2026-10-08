<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Crypto;
use Bnc\Doublons as D;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Mots significatifs d'un libellé bancaire\n";
ok(D::mots('Vir Sepa Alternative Solutions Et Format') === ['alternative', 'solutions', 'format'],
   'les mots techniques (vir, sepa, et) sont écartés');
ok(D::mots('CARTE 19/09/26 ALTOVIZ* O Ñ37191 CB*8006') === ['altoviz'],
   'dates, références, numéros et lettres isolées écartés ; casse ignorée');
ok(D::mots('Élodie Hélène Çelik') === ['elodie', 'helene', 'celik'], 'les accents sont ramenés à la lettre de base');
ok(D::mots("PRLV SEPA BOUYGUES TELECOM BOUYGUES TELE 06xxxxx937 FR26091646064220") === ['bouygues', 'telecom', 'tele'],
   'un mot répété compte une fois, les références chiffrées disparaissent');
ok(D::mots('') === [], 'libellé vide : aucun mot');

echo "\nLibellés qui désignent le même tiers\n";
$memes = [
    ['Vir Sepa Alternative Solutions Et Format', 'ALTERNATIVE SOLUTIONS ET FORMAT', 'même tiers, un préfixe bancaire en plus'],
    ['BOUYGUES TELECOM BOUYGUES TELE', 'BOUYGUES TELECOM', 'libellé tronqué'],
    ['Vir Inst Elsy Glondu Ei', 'INST ELSY GLONDU EI', 'casse et préfixe'],
    ["MAMMOUTH AI\\+33670335985\\ FR", 'MAMMOUTH AI CARTE 26/09/26 MAMMOUTH AI CB*8006', 'opération en attente puis sa version définitive'],
];
foreach ($memes as [$a, $b, $nom]) { ok(D::similaires($a, $b), "similaires : $nom"); }

echo "\nLibellés de tiers différents\n";
$autres = [
    ['Vir Sepa Cpf', 'Vir Sepa Lingueo Sas', 'deux clients'],
    ['CARTE ALTOVIZ', 'CARTE MAMMOUTH AI', 'deux fournisseurs'],
    ['Bouygues Telecom', 'Orange Business', 'deux opérateurs'],
];
foreach ($autres as [$a, $b, $nom]) { ok(!D::similaires($a, $b), "distincts : $nom"); }
ok(!D::similaires('', ''), 'deux libellés vides ne se ressemblent pas');
ok(D::similaires('12345', '12345'), 'sans mot exploitable, seule l\'identité stricte compte');
ok(!D::similaires('12345', '12346'), 'et deux références différentes restent distinctes');

echo "\nEmpreintes : le rang distingue les occurrences sans casser l'existant\n";
$ancienne = hash('sha256', implode('|', [1, '2026-06-18', '412.50', 'vir sepa cpf', 'Boursorama']));
ok(Crypto::empreinteTransaction(1, '2026-06-18', '412.50', 'Vir Sepa Cpf', 'Boursorama') === $ancienne,
   'rang 1 = empreinte d\'avant l\'introduction du rang : les données déjà importées sont reconnues');
$r2 = Crypto::empreinteTransaction(1, '2026-06-18', '412.50', 'Vir Sepa Cpf', 'Boursorama', 2);
$r3 = Crypto::empreinteTransaction(1, '2026-06-18', '412.50', 'Vir Sepa Cpf', 'Boursorama', 3);
ok($r2 !== $ancienne && $r3 !== $ancienne && $r2 !== $r3, 'deux virements identiques le même jour ont deux empreintes distinctes');
ok(Crypto::empreinteTransaction(1, '2026-06-18', '412.5', ' vir   SEPA cpf ', 'Boursorama', 2) === $r2,
   'et chaque rang est stable d\'un import à l\'autre (casse, espaces, décimales normalisés)');
ok(Crypto::empreinteIdentifiant(1, 'altoviz', '', '9001') === Crypto::empreinteIdentifiant(1, 'altoviz', '', '9001'),
   'l\'empreinte par identifiant est déterministe');
ok(Crypto::empreinteIdentifiant(1, 'altoviz', '', '9001') !== Crypto::empreinteIdentifiant(2, 'altoviz', '', '9001'),
   'propre à chaque utilisateur');
ok(Crypto::empreinteIdentifiant(1, 'ofx', 'A', '1') !== Crypto::empreinteIdentifiant(1, 'ofx', 'B', '1'),
   'un même FITID sur deux comptes OFX ne se confond pas');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
