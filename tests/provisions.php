<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Provisions as P;
use Bnc\Urssaf as U;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
function pres($a,$b,$t=0.011){ return abs($a-$b) <= $t; }

echo "Situation envoyée à l'Urssaf\n";
$e = ['recettes'=>60000.4,'charges'=>20000,'debut_activite'=>'2018-03-01','famille'=>'couple','enfants'=>2,'parent_isole'=>true,'autres_revenus'=>20000];
$s = U::situation($e);
ok($s["entreprise . chiffre d'affaires"] === '60000 €/an' && $s['entreprise . charges'] === '20000 €/an', 'montants arrondis à l\'euro, avec leur unité');
ok($s['entreprise . date de création'] === '01/03/2018', 'date de début au format jj/mm/aaaa');
ok($s['impôt . foyer fiscal . situation de famille'] === "'couple'" && $s['impôt . foyer fiscal . enfants à charge'] === 2, 'famille et enfants');
ok($s['impôt . foyer fiscal . parent isolé'] === 'non', 'parent isolé ignoré pour un couple');
ok($s['impôt . foyer fiscal . revenu imposable . autres revenus imposables'] === '20000 €/an', 'autres revenus');
ok($s['impôt . méthode de calcul'] === "'barème standard'", 'méthode de l\'impôt : barème standard (le taux neutre donnerait zéro)');
ok($s['dirigeant'] === "'indépendant'" && $s['entreprise . imposition . IR . type de bénéfices'] === "'BNC'"
   && $s['entreprise . imposition . régime'] === "'déclaration contrôlée'" && $s['entreprise . activité . nature . libérale . réglementée'] === 'non',
   'BNC, déclaration contrôlée, profession libérale non réglementée');
ok(count($s) === 20, 'vingt réglages, tous explicites (aucun défaut silencieux sur ce que nous savons) : ' . count($s));
$sj = json_encode($s, JSON_UNESCAPED_UNICODE);
ok(!preg_match('/siret|adresse|email|nom"/i', $sj), 'rien d\'identifiant dans ce qui part');
ok(U::situation(['famille'=>'célibataire','enfants'=>1,'parent_isole'=>true] + $e)['impôt . foyer fiscal . parent isolé'] === 'oui', 'parent isolé : célibataire avec un enfant');
ok(U::situation(['famille'=>'célibataire','enfants'=>0,'parent_isole'=>true] + $e)['impôt . foyer fiscal . parent isolé'] === 'non', '...mais pas sans enfant');
ok(U::situation(['famille'=>'inconnue'] + $e)['impôt . foyer fiscal . situation de famille'] === "'célibataire'", 'famille inconnue : célibataire');
ok(!isset(U::situation(['debut_activite'=>null] + $e)['entreprise . date de création']) && !isset(U::situation(['debut_activite'=>'hier'] + $e)['entreprise . date de création']), 'sans date, ou date illisible : la clé est omise');
ok(U::situation(['recettes'=>-5,'charges'=>-9,'autres_revenus'=>-1] + $e)["entreprise . charges"] === '0 €/an', 'jamais de montant négatif');
ok(U::situation(['enfants'=>99] + $e)['impôt . foyer fiscal . enfants à charge'] === 20, 'nombre d\'enfants borné');

echo "\nRéponse de l'Urssaf\n";
$idx = array_flip(array_keys(U::SORTIES)); $n = count($idx);
$val = fn(array $over = []) => array_replace(array_fill(0, $n, ['nodeValue' => 100, 'missingVariables' => []]), $over);
$rep = fn(array $d) => json_encode($d);
$r = U::lireReponse(200, $rep(['evaluate' => $val([$idx['cotisations'] => ['nodeValue' => 10977, 'missingVariables' => ['a . b', 'c']], $idx['impot'] => ['nodeValue' => 2136, 'missingVariables' => ['c' => 1, 'd' => 1]]]),
    'warnings' => [['message' => 'Cette règle est tagguée comme experimentale.'], ['message' => 'Attention : revenu élevé.']]]));
ok($r['ok'] && $r['valeurs']['cotisations'] === 10977.0 && $r['valeurs']['impot'] === 2136.0, 'réponse correcte lue, dans l\'ordre des sorties');
ok($r['manquantes'] === ['a . b', 'c', 'd'], 'données manquantes : listes et tableaux associatifs, sans doublon');
ok($r['avertissements'] === ['Attention : revenu élevé.'], 'les avertissements « expérimentaux » (pour les auteurs du modèle) sont écartés');
$r = U::lireReponse(200, $rep(['evaluate' => $val([$idx['maladie'] => ['nodeValue' => null]])]));
ok($r['ok'] && $r['valeurs']['maladie'] === null, 'une ligne de détail absente n\'empêche pas l\'estimation');
$r = U::lireReponse(200, $rep(['evaluate' => $val([$idx['cotisations'] => ['nodeValue' => null]])]));
ok(!$r['ok'] && str_contains($r['erreur'], 'cotisations et contributions'), 'cotisations absentes : échec, avec le nom de la règle');
$r = U::lireReponse(200, $rep(['evaluate' => $val([$idx['impot'] => ['nodeValue' => null]])]));
ok(!$r['ok'] && str_contains($r['erreur'], 'impôt . montant'), 'impôt absent : échec');
$r = U::lireReponse(200, $rep(['situationError' => ['message' => "La règle 'x' n'existe pas"]]));
ok(!$r['ok'] && str_contains($r['erreur'], 'modèle a sans doute changé') && str_contains($r['erreur'], "n'existe pas"), 'situation refusée : modèle changé, avec le détail');
ok(str_contains(U::lireReponse(503, '')['erreur'], 'indisponible') && str_contains(U::lireReponse(502, '<html>Bad gateway</html>')['erreur'], 'indisponible'), '5xx sans explication (corps vide, page HTML) : service indisponible');
$r = U::lireReponse(500, $rep(['error' => ['message' => 'Unknown rule x']]));
ok(!$r['ok'] && str_contains($r['erreur'], 'renommée') && str_contains($r['erreur'], 'Unknown rule x'), '5xx AVEC message d\'erreur du moteur : la vraie cause est montrée, pas « indisponible »');
$r = U::lireReponse(200, $rep(['error' => ['message' => 'Unknown rule dirigeant . x']]));
ok(!$r['ok'] && str_contains($r['erreur'], 'renommée') && str_contains($r['erreur'], 'Unknown rule'), 'erreur du moteur : règle renommée, avec le détail');
$r = U::lireReponse(400, $rep([['message' => 'must be array', 'dataPath' => '.expressions']]));
ok(!$r['ok'] && str_contains($r['erreur'], 'must be array'), '400 : message de validation cité');
ok(str_contains(U::lireReponse(429, '')['erreur'], 'limite'), '429 : limite d\'appels');
ok(str_contains(U::lireReponse(200, '<html>maintenance</html>')['erreur'], 'illisible'), 'réponse qui n\'est pas du JSON');
ok(str_contains(U::lireReponse(200, $rep(['evaluate' => [['nodeValue' => 1]]]))['erreur'], '1 expression(s) sur ' . $n), 'nombre de réponses incohérent');
ok(str_contains(U::lireReponse(200, $rep(['autre' => 1]))['erreur'], 'inattendue'), 'forme inconnue');

echo "\nValidation des paramètres\n";
[$p, $er] = P::valider(['famille'=>'couple','enfants'=>'2','autres_revenus'=>'20 000,50','mode'=>'ce_jour']);
ok(!$er && $p['situation_famille']==='couple' && $p['enfants']===2 && $p['autres_revenus']===20000.5, 'saisie correcte, décimales françaises');
[$p, $er] = P::valider(['famille'=>'?','enfants'=>'11','autres_revenus'=>'abc','mode'=>'x']);
ok(count($er) === 4, 'quatre erreurs distinctes : ' . count($er));
[, $er] = P::valider(['famille'=>'couple','enfants'=>0,'autres_revenus'=>'-5','mode'=>'ce_jour']);
ok(count($er) === 1, 'autres revenus négatifs refusés');
[, $er] = P::valider(['famille'=>'couple','enfants'=>0,'autres_revenus'=>'','mode'=>'ce_jour']);
ok(!$er, 'autres revenus vides : zéro');
[, $er] = P::valider(['famille'=>'couple','enfants'=>0,'mode'=>'libre']);
ok(count($er) === 1, 'saisie libre sans recettes : refusée');
[$p, $er] = P::valider(['famille'=>'couple','enfants'=>0,'mode'=>'libre','recettes_libre'=>'60 000','charges_libre'=>'']);
ok(!$er && $p['recettes_libre'] === 60000.0 && $p['charges_libre'] === 0.0, 'charges vides = 0 en saisie libre');
[$p] = P::valider(['famille'=>'couple','enfants'=>2,'parent_isole'=>'1','mode'=>'ce_jour']);
ok($p['parent_isole'] === false, 'parent isolé impossible en couple');
[$p] = P::valider(['famille'=>'veuf','enfants'=>1,'parent_isole'=>'1','mode'=>'ce_jour']);
ok($p['parent_isole'] === true, 'parent isolé possible pour une personne veuve avec enfant');

echo "\nBase de calcul\n";
$calc = ['total_recettes'=>30000.0,'total_depenses'=>15000.0,'depenses'=>['BW'=>['total'=>3000.0]]];
$ex = ['date_debut'=>'2026-01-01','date_fin'=>'2026-12-31','statut'=>'ouvert'];
$libre = ['recettes_libre'=>null,'charges_libre'=>null];
$b = P::base($calc, $ex, 'ce_jour', $libre, new DateTimeImmutable('2026-07-02'));
ok($b['recettes']===30000.0 && $b['charges']===12000.0 && $b['bw']===3000.0, 'les cotisations personnelles (BW) sont retirées des charges envoyées');
ok($b['annuel']===false && $b['facteur']===1.0, 'à ce jour : pas une année pleine');
$b = P::base($calc, $ex, 'annualise', $libre, new DateTimeImmutable('2026-07-02'));
ok($b['mode_effectif']==='annualise' && pres($b['facteur'], 365/183, 0.0001) && pres($b['recettes'], 30000*365/183) && pres($b['charges'], 12000*365/183), '183 jours écoulés sur 365 : projection au prorata');
ok($b['annuel']===true && str_contains($b['note'], '183 sur 365'), 'annuel, et la note dit sur quoi repose la projection');
ok($b['brut_recettes']===30000.0 && $b['brut_charges']===12000.0, 'les montants à ce jour sont conservés pour détecter une estimation périmée');
$b = P::base($calc, $ex, 'annualise', $libre, new DateTimeImmutable('2027-02-01'));
ok($b['mode_effectif']==='ce_jour' && $b['annuel']===true && $b['recettes']===30000.0 && str_contains($b['note'], 'terminé'), 'exercice terminé : montants réels, rien à projeter');
$b = P::base($calc, ['statut'=>'clos'] + $ex, 'annualise', $libre, new DateTimeImmutable('2026-03-01'));
ok($b['mode_effectif']==='ce_jour' && $b['annuel']===true, 'exercice clos : année entière même avant sa fin civile');
$b = P::base($calc, $ex, 'annualise', $libre, new DateTimeImmutable('2026-01-15'));
ok($b['mode_effectif']==='ce_jour' && $b['recettes']===30000.0 && str_contains($b['note'], 'trop tôt'), 'quinze jours seulement : on ne projette pas, on le dit');
$b = P::base($calc, $ex, 'annualise', $libre, new DateTimeImmutable('2025-12-01'));
ok($b['mode_effectif']==='ce_jour' && $b['facteur']===1.0, 'exercice pas encore commencé : pas de projection');
$b = P::base($calc, $ex, 'libre', ['recettes_libre'=>60000.0,'charges_libre'=>20000.0], new DateTimeImmutable('2026-07-02'));
ok($b['mode_effectif']==='libre' && $b['recettes']===60000.0 && $b['charges']===20000.0 && $b['annuel']===true, 'saisie libre : les montants saisis, année pleine');
$b = P::base($calc, $ex, 'libre', $libre, new DateTimeImmutable('2026-07-02'));
ok($b['mode_effectif']==='ce_jour', 'saisie libre sans montants enregistrés : retombe sur les écritures');
$b = P::base(['total_recettes'=>1000.0,'total_depenses'=>500.0,'depenses'=>['BW'=>['total'=>900.0]]], $ex, 'ce_jour', $libre, new DateTimeImmutable('2026-07-02'));
ok($b['charges']===0.0, 'jamais de charges négatives');
$b = P::base(['total_recettes'=>1000.0,'total_depenses'=>500.0,'depenses'=>[]], $ex, 'ce_jour', $libre, new DateTimeImmutable('2026-07-02'));
ok($b['bw']===0.0, 'pas de poste BW : zéro');

echo "\nChiffres dérivés (cas de référence : 60 000 € de recettes, 20 000 € de charges)\n";
$vals = ['cotisations'=>10977.0,'resultat_fiscal'=>30106.0,'impot'=>2136.0];
$c = P::chiffres($vals, ['recettes'=>60000.0,'charges'=>20000.0,'bw'=>4000.0,'annuel'=>true]);
ok($c['avant_cotisations']===40000.0 && $c['a_prevoir']===13113.0, 'résultat avant cotisations 40 000, à prévoir 13 113');
ok(pres($c['taux'], 0.3278, 0.00001) && $c['mensuel']===1092.75, 'taux de 32,78 %, 1 092,75 € par mois');
ok($c['deja_verse']===4000.0 && $c['reste_cotisations']===6977.0 && $c['reste_a_mettre']===9113.0, 'déjà versé 4 000, reste 6 977 de cotisations, 9 113 avec l\'impôt');
ok($c['minimales']===false, 'bénéfice : pas de cotisations minimales');
$c = P::chiffres($vals, ['recettes'=>60000.0,'charges'=>20000.0,'bw'=>0.0,'annuel'=>false]);
ok($c['mensuel']===null, 'exercice partiel : pas de montant mensuel (il serait trompeur)');
$c = P::chiffres(['cotisations'=>813.0,'resultat_fiscal'=>-10813.0,'impot'=>0.0], ['recettes'=>10000.0,'charges'=>20000.0,'bw'=>0.0,'annuel'=>true]);
ok($c['minimales']===true && $c['taux']===null && $c['a_prevoir']===813.0, 'déficit : cotisations minimales signalées, pas de taux');
$c = P::chiffres($vals, ['recettes'=>60000.0,'charges'=>20000.0,'bw'=>15000.0,'annuel'=>true]);
ok($c['reste_cotisations']===0.0 && $c['reste_a_mettre']===2136.0, 'cotisations déjà versées au-delà de l\'estimation : reste = impôt seul');

echo "\nDétail des cotisations\n";
$complet = ['cotisations'=>10977.0,'maladie'=>1244.0,'indemnites_journalieres'=>90.0,'retraite_base'=>3041.0,'retraite_complementaire'=>2709.0,'invalidite_deces'=>151.0,'csg_crds'=>3622.0,'formation'=>120.0,'allocations_familiales'=>0.0];
$d = P::detailCotisations($complet);
ok(count($d['lignes']) === 7 && $d['autres'] === 0.0, 'cas de référence : sept lignes, aucun écart (les indemnités journalières comptent)');
ok(!isset($d['lignes']['Allocations familiales']), 'une ligne nulle n\'est pas affichée');
$d = P::detailCotisations(['indemnites_journalieres'=>null] + $complet);
ok($d['autres'] === 90.0, 'une ligne qui manque apparaît en « autres » : l\'écart est montré, pas caché');
$d = P::detailCotisations(['cotisations'=>12000.0] + $complet);
ok($d['autres'] === 1023.0, 'si le total dépasse le détail (nouvelle composante), la différence est affichée');
$d = P::detailCotisations(['cotisations'=>10977.4] + $complet);
ok($d['autres'] === 0.0, 'un écart d\'arrondi inférieur à un euro n\'est pas affiché');

echo "\nEstimation périmée\n";
$est = ['entree'=>['brut_recettes'=>1000.0,'brut_charges'=>500.0]];
ok(!P::perimee($est, ['brut_recettes'=>1000.0,'brut_charges'=>500.0]), 'écritures inchangées : à jour');
ok(!P::perimee($est, ['brut_recettes'=>1000.5,'brut_charges'=>500.0]), 'écart d\'un demi-euro : toléré');
ok(P::perimee($est, ['brut_recettes'=>1002.0,'brut_charges'=>500.0]) && P::perimee($est, ['brut_recettes'=>1000.0,'brut_charges'=>480.0]), 'recettes ou charges modifiées : à actualiser');

ok(!P::perimee(['mode'=>'libre'] + $est, ['brut_recettes'=>9999.0,'brut_charges'=>1.0]), 'saisie libre : jamais périmée, les écritures ne la concernent pas');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n"; exit($ko?1:0);
