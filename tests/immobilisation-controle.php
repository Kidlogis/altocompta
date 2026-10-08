<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Declaration as D;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Avertissement « dépenses à immobiliser »\n";
ok(D::controleImmobilisation(0, false) === null && D::controleImmobilisation(0, true) === null, 'aucune dépense concernée : rien à signaler');
$c = D::controleImmobilisation(1, false);
ok($c['niveau'] === 'attention' && $c['nb'] === 1, 'un niveau « attention », pas une erreur bloquante');
ok(str_contains($c['texte'], '500 €') && !str_contains($c['texte'], 'dépenses'), 'singulier : « dépense de 500 € ou plus »');
ok(str_contains(D::controleImmobilisation(3, false)['texte'], 'dépenses'), 'pluriel pour plusieurs');

echo "\nExtension absente : le cœur dit quoi faire\n";
$a = D::controleImmobilisation(2, false);
ok(str_contains($a['texte'], 'sans registre des immobilisations'), 'le texte précise que le registre manque');
ok($a['lien'] === '/extensions', 'et renvoie vers les extensions');
ok(str_contains($a['detail'], 'surévalue'), 'et dit la conséquence : un résultat surévalué');

echo "\nExtension présente : même règle, autre message\n";
$p = D::controleImmobilisation(2, true);
ok(!str_contains($p['texte'], 'sans registre') && $p['lien'] === '/depenses', 'pas de « sans registre », lien vers les dépenses');

echo "\nLa règle de détection\n";
ok(D::SEUIL_IMMOBILISATION === 500.0, 'seuil à 500 €');
ok(D::POSTES_IMMOBILISABLES === ['BA', 'BK', 'BR', 'BY'], 'postes : achats, petit outillage, véhicules, fournitures');
foreach (['BF', 'BP', 'BN', 'BW', 'BC', 'BE'] as $poste) {
    ok(!in_array($poste, D::POSTES_IMMOBILISABLES, true), "le poste $poste (loyer, assurance, honoraires, charges…) ne déclenche jamais l'alerte");
}
echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
