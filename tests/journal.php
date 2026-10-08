<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Journal;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

/* Le chaînage est reproduit ici à l'identique de Journal::empreinte() pour
   tourner sans base : ces tests prouvent les propriétés du mécanisme, pas
   son câblage SQL, vérifié séparément en intégration. */
$empreinte = static fn(int $seq, string $h, string $type, string $objet, ?int $oid,
                       ?int $eid, string $acteur, string $charge, string $prec): string
    => hash('sha256', implode("\x1F", [
        (string) $seq, $h, $type, $objet, (string) ($oid ?? ''),
        (string) ($eid ?? ''), $acteur, $charge, $prec,
    ]));

echo "Empreinte de genèse\n";
ok(strlen(Journal::GENESE) === 64, 'longueur 64, comme une empreinte SHA-256');
ok(Journal::GENESE === str_repeat('0', 64), 'que des zéros : aucune entrée avant la première');

echo "\nDéterminisme\n";
$a = $empreinte(1, '2026-01-01 10:00:00.000000', 'creation', 'recette', 5, 1, 'thomas', '{"m":100}', Journal::GENESE);
$b = $empreinte(1, '2026-01-01 10:00:00.000000', 'creation', 'recette', 5, 1, 'thomas', '{"m":100}', Journal::GENESE);
ok($a === $b, 'mêmes entrées, même empreinte');
ok(strlen($a) === 64, 'empreinte de 64 caractères');

echo "\nToute modification change l'empreinte\n";
$ref = ['seq'=>1,'h'=>'2026-01-01 10:00:00.000000','type'=>'creation','objet'=>'recette',
        'oid'=>5,'eid'=>1,'acteur'=>'thomas','charge'=>'{"m":100}','prec'=>Journal::GENESE];
$base = $empreinte(...array_values($ref));
$cas = [
    'le montant'      => ['charge' => '{"m":200}'],
    'le type d\'acte' => ['type' => 'annulation'],
    'l\'horodatage'   => ['h' => '2026-01-01 10:00:01.000000'],
    'l\'acteur'       => ['acteur' => 'quelquun'],
    'l\'objet visé'   => ['oid' => 6],
    'la séquence'     => ['seq' => 2],
];
foreach ($cas as $libelle => $diff) {
    $v = array_values(array_merge($ref, $diff));
    ok($empreinte(...$v) !== $base, "changer $libelle change l'empreinte");
}

echo "\nChaînage : une entrée dépend de la précédente\n";
$e1 = $empreinte(1, '2026-01-01 10:00:00.000000', 'creation', 'recette', 1, 1, 'thomas', '{"m":100}', Journal::GENESE);
$e2 = $empreinte(2, '2026-01-01 11:00:00.000000', 'creation', 'recette', 2, 1, 'thomas', '{"m":200}', $e1);
$e3 = $empreinte(3, '2026-01-01 12:00:00.000000', 'creation', 'recette', 3, 1, 'thomas', '{"m":300}', $e2);

// Si l'entrée 1 est retouchée, l'entrée 2 ne se rattache plus à elle.
$e1_truque = $empreinte(1, '2026-01-01 10:00:00.000000', 'creation', 'recette', 1, 1, 'thomas', '{"m":999}', Journal::GENESE);
ok($e1_truque !== $e1, 'retoucher la 1re entrée change son empreinte');
$e2_attendu = $empreinte(2, '2026-01-01 11:00:00.000000', 'creation', 'recette', 2, 1, 'thomas', '{"m":200}', $e1_truque);
ok($e2_attendu !== $e2, 'la 2e entrée ne correspond plus : la rupture se propage');

echo "\nSéparateur : aucune ambiguïté entre champs\n";
/* Sans séparateur, 'ab'+'c' et 'a'+'bc' donneraient la même chaîne.
   Le \x1F ne peut pas apparaître dans les valeurs, donc deux découpages
   différents ne peuvent pas produire la même empreinte. */
$x = $empreinte(1, '2026-01-01 10:00:00.000000', 'creation', 'recette', 1, 1, 'ab', 'c', Journal::GENESE);
$y = $empreinte(1, '2026-01-01 10:00:00.000000', 'creation', 'recette', 1, 1, 'a', 'bc', Journal::GENESE);
ok($x !== $y, 'un décalage de contenu entre deux champs ne passe pas inaperçu');

echo "\nTypes d'actes couverts\n";
foreach (['creation','rectification','annulation','cloture','archivage'] as $t) {
    ok(isset(Journal::TYPES[$t]), "type « $t » prévu");
}

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
