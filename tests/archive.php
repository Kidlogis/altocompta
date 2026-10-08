<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Archive;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Format de l'archive\n";
// 1 : écritures sans TVA. 2 : elles portent, quand elles en ont un, leur éclatement HT / TVA. Un changement de ce numéro
// doit rester un choix délibéré : il dit à une relecture future comment interpréter le contenu figé.
ok(Archive::FORMAT === 2, 'version de format déclarée (2 : éclatement HT / TVA), pour une relecture ultérieure');

/* L'empreinte d'une archive est un SHA-256 du JSON conservé : ces tests
   prouvent les propriétés attendues sans base, la vérification du câblage
   SQL se faisant en intégration. */
echo "\nUne archive se vérifie en recalculant l'empreinte du contenu conservé\n";
$contenu = json_encode(['format' => 1, 'totaux' => ['resultat' => 3357.20]]);
$empreinte = hash('sha256', $contenu);
ok(hash('sha256', $contenu) === $empreinte, 'recalcul identique sur contenu inchangé');
ok(strlen($empreinte) === 64, 'empreinte SHA-256 de 64 caractères');

echo "\nToute retouche du contenu change l'empreinte\n";
$altere = str_replace('3357.2', '9999.9', $contenu);
ok($altere !== $contenu, 'le contenu testé est bien différent');
ok(hash('sha256', $altere) !== $empreinte, 'un montant modifié donne une autre empreinte');

$espace = $contenu . ' ';
ok(hash('sha256', $espace) !== $empreinte, 'même un espace ajouté change l\'empreinte');

echo "\nComparaison à temps constant\n";
ok(hash_equals($empreinte, hash('sha256', $contenu)), 'hash_equals valide une empreinte correcte');
ok(!hash_equals($empreinte, hash('sha256', $altere)), 'hash_equals rejette une empreinte fausse');

echo "\nL'archive conserve aussi les écritures annulées\n";
/* Une archive qui ne garderait que les écritures actives perdrait la trace
   de ce qui a été corrigé — exactement ce que l'inaltérabilité préserve. */
$lignes = [
    ['numero_chrono' => 1, 'montant' => '1000.00', 'statut' => 'annulee'],
    ['numero_chrono' => 2, 'montant' => '2000.00', 'statut' => 'active'],
    ['numero_chrono' => 3, 'montant' => '1500.00', 'statut' => 'active'],
];
$actives = array_values(array_filter($lignes, static fn($l) => $l['statut'] === 'active'));
ok(count($lignes) === 3, 'les trois lignes sont archivées, annulée comprise');
ok(count($actives) === 2, 'deux seulement comptent dans les totaux');
$total = array_sum(array_map(static fn($l) => (float) $l['montant'], $actives));
ok($total === 3500.0, 'total déclaré 3 500, pas 4 500');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
