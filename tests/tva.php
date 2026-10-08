<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Tva as T;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
function lire(array $in, ?float $ttc, bool $dep = true) { return T::lire($in, $ttc, $dep); }

echo "Aucun éclatement\n";
[$f, $e] = lire([], 120.0);
ok($e === [] && $f === ['montant_ht'=>null,'montant_tva'=>null,'taux_tva'=>null,'tva_non_recuperable'=>null], 'rien de saisi : pas d\'éclatement, pas d\'erreur (l\'écriture reste « à éclater »)');
[$f, $e] = lire(['montant_ht'=>'','montant_tva'=>' ','taux_tva'=>''], 120.0);
ok($e === [] && $f['montant_ht'] === null, 'champs vides ou blancs : idem');
[$f, $e] = lire([], 120.0, false);
ok(array_keys($f) === ['montant_ht','montant_tva','taux_tva'], 'une recette n\'a pas de TVA non récupérable');
[$f, $e] = lire(['montant_ht'=>'100'], null);
ok($e === [] && $f['montant_ht'] === null, 'TTC invalide (signalé ailleurs) : on ne cumule pas les erreurs');

echo "\nHT et TVA saisis\n";
[$f, $e] = lire(['montant_ht'=>'100,00','montant_tva'=>'20,00','taux_tva'=>'20'], 120.0);
ok($e === [] && $f['montant_ht'] === '100.00' && $f['montant_tva'] === '20.00' && $f['taux_tva'] === '20.00', 'HT 100 + TVA 20 = TTC 120 : virgule décimale acceptée, valeurs normalisées');
[$f, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20'], 120.0);
ok($e === [] && $f['taux_tva'] === null, 'le taux est facultatif (facture à plusieurs taux)');
[$f, $e] = lire(['montant_ht'=>'100','montant_tva'=>'21'], 120.0);
ok(count($e) === 1 && str_contains($e[0], 'doit égaler') && str_contains($e[0], '120,00') && $f['montant_ht'] === null, 'HT + TVA ≠ TTC : refusé, avec les trois montants dans le message, rien n\'est « arrangé »');
[$f, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20,01'], 120.0);
ok(count($e) === 1, 'un centime d\'écart suffit à refuser');
[$f, $e] = lire(['montant_ht'=>'83,33','montant_tva'=>'16,67','taux_tva'=>'20'], 100.0);
ok($e === [], '83,33 + 16,67 = 100 au taux de 20 % : l\'arrondi de la facture est toléré');

echo "\nHT seul, TVA seule, taux seul : le reste se déduit du TTC\n";
[$f] = lire(['montant_ht'=>'100'], 120.0);
ok($f['montant_tva'] === '20.00', 'HT seul → TVA = TTC − HT');
[$f] = lire(['montant_tva'=>'20'], 120.0);
ok($f['montant_ht'] === '100.00', 'TVA seule → HT = TTC − TVA');
[$f] = lire(['taux_tva'=>'20'], 120.0);
ok($f['montant_ht'] === '100.00' && $f['montant_tva'] === '20.00' && $f['taux_tva'] === '20.00', 'taux seul : 120 TTC à 20 % → 100 HT + 20 TVA');
[$f] = lire(['taux_tva'=>'5,5'], 100.0);
ok($f['montant_ht'] === '94.79' && $f['montant_tva'] === '5.21' && (int) round((94.79 + 5.21) * 100) === 10000, '100 TTC à 5,5 % : 94,79 + 5,21 — la somme fait exactement le TTC');
[$f] = lire(['taux_tva'=>'20'], 0.10);
ok($f['montant_ht'] === '0.08' && $f['montant_tva'] === '0.02', 'petit montant : 0,10 TTC → 0,08 + 0,02');
[$f] = lire(['taux_tva'=>'0'], 100.0);
ok($f['montant_ht'] === '100.00' && $f['montant_tva'] === '0.00' && $f['taux_tva'] === '0.00', 'taux 0 : HT = TTC, TVA nulle (vente exonérée d\'un assujetti)');
foreach ([[1155.0, 10.0, '1050.00', '105.00'], [-120.0, 20.0, '-100.00', '-20.00'], [1.0, 2.1, '0.98', '0.02'], [9999999.99, 20.0, '8333333.33', '1666666.66']] as [$ttc, $tx, $h, $v])
    ok(T::depuisTaux($ttc, $tx) === [$h, $v], "depuisTaux($ttc, $tx %) = $h + $v");

echo "\nSignes : avoirs et remboursements\n";
[$f, $e] = lire(['montant_ht'=>'-100','montant_tva'=>'-20'], -120.0);
ok($e === [] && $f['montant_ht'] === '-100.00' && $f['montant_tva'] === '-20.00', 'un avoir de −120 TTC : HT et TVA négatifs');
[$f, $e] = lire(['taux_tva'=>'20'], -120.0);
ok($e === [] && $f['montant_ht'] === '-100.00' && $f['montant_tva'] === '-20.00', '...le taux seul fonctionne aussi');
[$f, $e] = lire(['montant_ht'=>'100','montant_tva'=>'-220'], -120.0);
ok($e !== [] && str_contains(implode(' ', $e), 'signe'), 'un HT positif sur un TTC négatif : refusé');
[$f, $e] = lire(['montant_ht'=>'150','montant_tva'=>'-30'], 120.0);
ok($e !== [], 'une TVA négative sur un TTC positif : refusée');
[$f, $e] = lire(['montant_ht'=>'-10','montant_tva'=>'130'], 120.0);
ok($e !== [], 'une TVA supérieure au TTC : refusée');

echo "\nTaux incohérent\n";
[$f, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20','taux_tva'=>'10'], 120.0);
ok(count($e) === 1 && str_contains($e[0], 'ne correspond pas') && str_contains($e[0], '10 %') && str_contains($e[0], 'plusieurs taux'), '100 HT + 20 TVA déclarés à 10 % : refusé, avec le conseil pour les factures multi-taux');
foreach (['abc', '-1', '101', '1e2'] as $t) { [, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20','taux_tva'=>$t], 120.0); ok($e !== [], "taux « $t » : refusé"); }
[, $e] = lire(['montant_ht'=>'abc','montant_tva'=>'20'], 120.0);
ok(count($e) === 1 && str_contains($e[0], 'HT'), 'HT non numérique : refusé');
[, $e] = lire(['montant_ht'=>'100','montant_tva'=>'x'], 120.0);
ok(count($e) === 1 && str_contains($e[0], 'TVA'), 'TVA non numérique : refusée');

echo "\nTVA non récupérable (dépenses)\n";
[$f, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20','tva_non_recuperable'=>'20'], 120.0);
ok($e === [] && $f['tva_non_recuperable'] === '20.00', 'toute la TVA non récupérable (véhicule de tourisme) : acceptée');
[$f, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20','tva_non_recuperable'=>'5'], 120.0);
ok($f['tva_non_recuperable'] === '5.00', 'une partie seulement');
foreach (['0', '', '0,00'] as $nr) { [$f] = lire(['montant_ht'=>'100','montant_tva'=>'20','tva_non_recuperable'=>$nr], 120.0); ok($f['tva_non_recuperable'] === null, "« $nr » : pas de TVA non récupérable (null)"); }
[, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20','tva_non_recuperable'=>'25'], 120.0);
ok(count($e) === 1 && str_contains($e[0], 'dépasser'), 'plus que la TVA de la facture : refusé');
[, $e] = lire(['montant_ht'=>'100','montant_tva'=>'20','tva_non_recuperable'=>'-5'], 120.0);
ok($e !== [], 'de signe contraire à la TVA : refusé');
[$f, $e] = lire(['montant_ht'=>'-100','montant_tva'=>'-20','tva_non_recuperable'=>'-5'], -120.0);
ok($e === [] && $f['tva_non_recuperable'] === '-5.00', 'sur un remboursement : négative, comme la TVA');
[, $e] = lire(['tva_non_recuperable'=>'5'], 120.0);
ok(count($e) === 1 && str_contains($e[0], 'suppose'), 'sans TVA renseignée : refusé');
[$f] = lire(['montant_ht'=>'100','montant_tva'=>'20','tva_non_recuperable'=>'5'], 120.0, false);
ok(!array_key_exists('tva_non_recuperable', $f), 'une recette ignore ce champ');

echo "\nMontant retenu pour le résultat\n";
$l = ['montant'=>'120.00','montant_ht'=>'100.00','montant_tva'=>'20.00','tva_non_recuperable'=>null,'quote_part_pro'=>100];
ok(T::retenu($l, true, true) === 100.0 && T::retenu($l, false, true) === 100.0, 'assujetti, éclaté : le HT');
ok(T::retenu($l, true, false) === 120.0, 'pas assujetti : toujours le TTC, même si des colonnes sont remplies');
ok(T::retenu(['montant'=>'120.00','montant_ht'=>null] + $l, true, true) === 120.0, 'pas encore éclatée : le TTC, faute de mieux');
ok(T::retenu(['tva_non_recuperable'=>'20.00'] + $l, true, true) === 120.0, 'dépense dont toute la TVA est non récupérable : HT + TVA non récupérable = TTC');
ok(T::retenu(['tva_non_recuperable'=>'5.00'] + $l, true, true) === 105.0 && T::retenu(['tva_non_recuperable'=>'5.00'] + $l, false, true) === 100.0, 'partiellement : 105 pour une dépense ; une recette ignore le champ');
ok(T::retenu(['montant'=>'-120.00','montant_ht'=>'-100.00','tva_non_recuperable'=>'-5.00'], true, true) === -105.0, 'remboursement : négatif');
ok(T::deductible($l) === 20.0 && T::deductible(['tva_non_recuperable'=>'5.00','quote_part_pro'=>50] + $l) === 7.5, 'TVA déductible : (20 − 5) × 50 % = 7,50');
ok(T::deductible(['montant_tva'=>null] + $l) === 0.0, 'sans éclatement : aucune TVA déductible (on ne devine pas)');

echo "\nÉcriture d'annulation : l'éclatement opposé\n";
$o = ['montant_ht'=>'100.00','montant_tva'=>'20.00','taux_tva'=>'20.00','tva_non_recuperable'=>'5.00'];
ok(T::oppose($o, T::CHAMPS_DEPENSE) === ['montant_ht'=>'-100.00','montant_tva'=>'-20.00','taux_tva'=>'20.00','tva_non_recuperable'=>'-5.00'], 'les montants changent de signe, le taux non');
ok(T::oppose(['montant_ht'=>'-100.00','montant_tva'=>'-20.00','taux_tva'=>null,'tva_non_recuperable'=>null], T::CHAMPS_DEPENSE) === ['montant_ht'=>'100.00','montant_tva'=>'20.00','taux_tva'=>null,'tva_non_recuperable'=>null], 'l\'annulation d\'un avoir est positive ; null reste null');
ok(T::oppose(['montant'=>'1.00'], T::CHAMPS_DEPENSE) === [], 'colonnes absentes (base pas encore migrée) : rien à inscrire');
ok(T::oppose(['montant_ht'=>'0.00','montant_tva'=>'0.00','taux_tva'=>'0.00','tva_non_recuperable'=>null], T::CHAMPS_DEPENSE)['montant_ht'] === '0.00', 'zéro reste zéro (pas de « -0.00 »)');
ok(array_sum(array_map('floatval', [$o['montant_ht'], T::oppose($o, T::CHAMPS_DEPENSE)['montant_ht']])) === 0.0, 'la somme d\'une écriture et de son annulation est nulle');

echo "\nRectification par un appelant qui ignore l'éclatement\n";
$orig = ['montant'=>'120.00','montant_ht'=>'100.00','montant_tva'=>'20.00','taux_tva'=>'20.00','tva_non_recuperable'=>null];
$r = T::conserver(['montant'=>'120.00','quote_part_pro'=>60], $orig, T::CHAMPS_DEPENSE);
ok($r['montant_ht'] === '100.00' && $r['montant_tva'] === '20.00' && $r['taux_tva'] === '20.00' && $r['quote_part_pro'] === 60, 'même TTC : l\'éclatement est repris de l\'originale (une extension ne le perd pas)');
$r = T::conserver(['montant'=>'150.00'], $orig, T::CHAMPS_DEPENSE);
ok($r['montant_ht'] === null && $r['montant_tva'] === null, 'TTC modifié sans nouvel éclatement : l\'ancien serait faux, l\'écriture repasse « à éclater »');
$r = T::conserver(['montant'=>'120.00','montant_ht'=>null,'montant_tva'=>null,'taux_tva'=>null,'tva_non_recuperable'=>null], $orig, T::CHAMPS_DEPENSE);
ok($r['montant_ht'] === null, 'un appelant qui fournit l\'éclatement (même vide) est obéi : on peut le retirer');
$r = T::conserver(['montant'=>'120.004'], $orig, T::CHAMPS_DEPENSE);
ok($r['montant_ht'] === '100.00', 'comparaison du TTC à un demi-centime près');

echo "\nJournal : instantané\n";
ok(T::instantane($orig, T::CHAMPS_DEPENSE) === ['montant_ht'=>'100.00','montant_tva'=>'20.00','taux_tva'=>'20.00'], 'seuls les champs renseignés sont consignés');
ok(T::instantane(['montant_ht'=>null], T::CHAMPS_DEPENSE) === [], 'non éclatée : rien (le journal d\'un non-assujetti ne change pas)');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
