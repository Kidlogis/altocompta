<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Montant; use Bnc\ReleveParser; use Bnc\Regle; use Bnc\Affectation;

$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Montant::lire — formats bancaires reels\n";
$cas = [
  ['1 440,00', 1440.0], ['1440.00', 1440.0], ['1.440,00', 1440.0], ['1,440.00', 1440.0],
  ['-780,50', -780.5], ['−74,30', -74.3], ['(123,45)', -123.45], ['18,90 €', 18.9],
  ['1 234 567,89', 1234567.89], ['0,01', 0.01], ['', null], ['abc', null], ['12,345', 12345.0],
];
foreach ($cas as [$in,$att]) {
  $got = Montant::lire($in);
  ok($got === $att || (is_float($att) && is_float($got) && abs($got-$att)<0.001),
     sprintf('"%s" -> %s', $in, var_export($got,true)));
}

echo "\nMontant::lireDate\n";
foreach ([['12/08/2026','2026-08-12'],['2026-08-12','2026-08-12'],['12-08-2026','2026-08-12'],
          ['12.08.2026','2026-08-12'],['20260812','2026-08-12'],['pas une date',null]] as [$in,$att]) {
  ok(Montant::lireDate($in) === $att, sprintf('"%s" -> %s', $in, var_export(Montant::lireDate($in),true)));
}

echo "\nReleveParser — CSV francais point-virgule, colonne montant unique\n";
$csv1 = "Date;Libelle;Montant;Compte\n12/08/2026;VIR SEPA OF VENDEE;1 440,00;Qonto\n10/08/2026;PRLV URSSAF;-780,00;Qonto\n";
$a = ReleveParser::analyser($csv1);
ok(count($a['lignes'])===2, 'deux lignes lues');
ok($a['lignes'][0]['montant']===1440.0, 'credit positif');
ok($a['lignes'][1]['montant']===-780.0, 'debit negatif');
ok($a['lignes'][0]['compte']==='Qonto', 'compte capture');

echo "\nReleveParser — CSV anglo-saxon, colonnes credit/debit separees\n";
$csv2 = "Transaction Date,Description,Credit,Debit\n2026-08-12,INCOMING TRANSFER,1440.00,\n2026-08-10,DIRECT DEBIT URSSAF,,780.00\n";
$b = ReleveParser::analyser($csv2);
ok(count($b['lignes'])===2, 'deux lignes lues');
ok($b['lignes'][0]['montant']===1440.0, 'colonne credit -> positif');
ok($b['lignes'][1]['montant']===-780.0, 'colonne debit -> negatif');

echo "\nReleveParser — robustesse\n";
$csv3 = "\xEF\xBB\xBFDate;Libelle;Montant\n12/08/2026;Avec BOM;10,00\n";
ok(count(ReleveParser::analyser($csv3)['lignes'])===1, 'BOM UTF-8 retire');
$csv4 = "Date\tLibelle\tMontant\n12/08/2026\tTabulations\t10,00\n";
ok(count(ReleveParser::analyser($csv4)['lignes'])===1, 'separateur tabulation detecte');
$csv5 = "Date;Libelle;Montant\n12/08/2026;Bonne;10,00\nPAS UNE DATE;Mauvaise;20,00\n;;\n";
$e = ReleveParser::analyser($csv5);
ok(count($e['lignes'])===1 && $e['ignorees']===1, 'ligne invalide ignoree, ligne vide sautee');
$latin = mb_convert_encoding("Date;Libelle;Montant\n12/08/2026;Café Réunion;10,00\n", 'Windows-1252', 'UTF-8');
$f = ReleveParser::analyser($latin);
ok(count($f['lignes'])===1 && str_contains($f['lignes'][0]['libelle'],'Café'), 'Windows-1252 converti : '.$f['lignes'][0]['libelle']);

echo "\nRegle::appliquer\n";
$regles = [
  ['id'=>1,'priorite'=>10,'motif'=>'URSSAF','type_match'=>'contient','sens_montant'=>'debit','cible_type'=>'depense','poste_2035'=>'BW'],
  ['id'=>2,'priorite'=>20,'motif'=>'TOTAL|ESSO','type_match'=>'regex','sens_montant'=>'debit','cible_type'=>'depense','poste_2035'=>'BR'],
  ['id'=>3,'priorite'=>30,'motif'=>'[','type_match'=>'regex','sens_montant'=>'tous','cible_type'=>'depense','poste_2035'=>'BA'],
];
$r1 = Regle::appliquer($regles, ['libelle'=>'PRLV URSSAF PAYS DE LOIRE','montant'=>-780.0]);
ok($r1 && $r1['poste_2035']==='BW', 'contient -> BW');
$r2 = Regle::appliquer($regles, ['libelle'=>'CB TOTAL ENERGIES','montant'=>-74.3]);
ok($r2 && $r2['poste_2035']==='BR', 'regex -> BR');
$r3 = Regle::appliquer($regles, ['libelle'=>'VIR URSSAF REMBOURSEMENT','montant'=>500.0]);
ok($r3 === null, 'regle debit ignoree sur un credit');
$r4 = Regle::appliquer($regles, ['libelle'=>'NIMPORTE','montant'=>-10.0]);
ok($r4 === null, 'regex invalide ne casse rien et ne matche pas');
ok(Regle::valider('regex','[') !== '', 'regex invalide rejetee a la saisie');
ok(Regle::valider('contient','URSSAF') === '', 'motif valide accepte');

echo "\nAffectation — extraction et nettoyage des libelles\n";
ok(Affectation::referenceFacture('VIR SEPA OF VENDEE REF FA-2026-0341')==='FA-2026-0341', 'reference de facture extraite');
ok(Affectation::referenceFacture('CB BOULANGERIE')===null, 'pas de reference -> null');
ok(Affectation::deviner('VIR SEPA OF VENDEE FORMATION')==='OF VENDEE FORMATION', 'prefixe VIR SEPA retire');
ok(Affectation::deviner('PRLV URSSAF PAYS DE LOIRE')==='URSSAF PAYS DE LOIRE', 'prefixe PRLV retire');
ok(Affectation::deviner('CB TOTAL ENERGIES')==='TOTAL ENERGIES', 'prefixe CB retire');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
