<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Auth;
use Bnc\Exercice as E;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
$ex = fn(array $a) => array_map(fn($x) => ['annee' => $x], $a);

echo "Année acceptable pour un nouvel exercice (courante : 2026)\n";
ok(E::anneeValide(2026, 2026) && E::anneeValide(2027, 2026) && E::anneeValide(2000, 2026), "2000, l'année en cours et la suivante sont acceptées");
ok(!E::anneeValide(2028, 2026) && !E::anneeValide(2072, 2026), '2028 et 2072 (faute de frappe) sont refusées');
ok(!E::anneeValide(1999, 2026) && !E::anneeValide(0, 2026) && !E::anneeValide(-5, 2026), 'avant 2000, zéro ou négatif : refusés');

echo "\nAnnée proposée\n";
ok(E::anneeProposee($ex([2026]), 2026) === 2027, 'avec 2026 seul : on propose 2027');
ok(E::anneeProposee($ex([2025, 2026]), 2026) === 2027, 'avec 2025 et 2026 : 2027');
ok(E::anneeProposee($ex([2026, 2027]), 2026) === 2025, '2027 déjà créé : la suivante (2028) serait refusée, on propose 2025 pour saisir un exercice passé');
ok(E::anneeProposee($ex([2000, 2027]), 2026) === 2000, 'jamais en dessous de 2000');
ok(E::anneeProposee([], 2026) === 2026, 'aucun exercice : l\'année en cours');

echo "\nChemin de retour : interne seulement\n";
foreach (['/', '/recettes', '/depenses?poste=BA&sans_justif=1', '/declaration#cloture'] as $bon) ok(Auth::cheminLocal($bon) === $bon, "accepté : $bon");
foreach (['//autre.site', 'https://autre.site', '/\\autre.site', "/ok\r\nSet-Cookie: x=1", 'javascript:alert(1)', '', 'recettes'] as $mauvais)
    ok(Auth::cheminLocal($mauvais) === '/', 'refusé, ramené à / : ' . json_encode($mauvais));

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
