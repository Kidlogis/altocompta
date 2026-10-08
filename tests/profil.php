<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Utilisateur as U;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }
function v(array $d): array { return U::valider($d + ['nom' => 'Test']); }

echo "Nom obligatoire\n";
[, $e] = U::valider(['nom' => '   ']);
ok(in_array('Le nom est obligatoire.', $e, true), 'un nom vide est refusé');
[, $e] = v([]);
ok($e === [], 'une fiche réduite au nom est valide : tout le reste est facultatif');

echo "\nSIRET\n";
[$o, $e, $a] = v(['siret' => '838 137 545 00029']);
ok($o['siret'] === '83813754500029' && !$e && !$a, 'SIRET réel, espaces tolérés, clé de contrôle correcte');
[, $e] = v(['siret' => '1234']);
ok(count($e) === 1, 'un SIRET trop court est refusé');
[$o, $e, $a] = v(['siret' => '12345678901234']);
ok($o['siret'] === '12345678901234' && !$e && count($a) === 1, 'clé de contrôle fausse : enregistré, mais signalé');
ok(U::luhn('83813754500029') && !U::luhn('12345678901234') && !U::luhn('') && !U::luhn('12a4'), 'Luhn : cas valide, invalide, vide, non numérique');
ok(U::siren('83813754500029') === '838137545' && U::siren(null) === null && U::siren('123') === null, 'SIREN = neuf premiers chiffres');

echo "\nCode APE\n";
foreach (['8559A' => '8559A', '85.59 a' => '8559A', '8559a' => '8559A', '85-59A' => '8559A'] as $in => $att) {
    [$o, $e] = v(['code_ape' => $in]);
    ok($o['code_ape'] === $att && !$e, "« $in » → $att");
}
foreach (['8559', '855A9', 'ABCDE', '85599A'] as $mauvais) {
    [, $e] = v(['code_ape' => $mauvais]);
    ok(count($e) === 1, "« $mauvais » refusé");
}

echo "\nNuméro de TVA\n";
[$o, $e] = v(['tva_numero' => 'fr 12 345 678 901']);
ok($o['tva_numero'] === 'FR12345678901' && !$e, 'normalisé en majuscules, espaces retirés');
[, $e] = v(['tva_numero' => '12345']);
ok(count($e) === 1, 'sans préfixe pays : refusé');
[, $e] = v(['tva_numero' => 'FR1']);
ok(count($e) === 1, 'trop court : refusé');

echo "\nNDA\n";
[$o, $e] = v(['nda' => '11 75 55555 75']);
ok($o['nda'] === '11755555575' && !$e, 'onze chiffres, espaces retirés');
[, $e] = v(['nda' => '1175555']);
ok(count($e) === 1, 'NDA incomplet refusé');

echo "\nRégime de TVA : liste fermée\n";
[$o, $e] = v(['tva_regime' => 'exonere_261']);
ok($o['tva_regime'] === 'exonere_261' && !$e, 'régime de TVA connu accepté');
[, $e] = v(['tva_regime' => 'nimporte']);
ok(count($e) === 1, 'régime de TVA inconnu refusé');
[, , $a] = v(['tva_regime' => 'assujetti']);
ok(count($a) === 1, 'assujetti sans numéro de TVA : avertissement');
[, , $a] = v(['tva_regime' => 'assujetti', 'tva_numero' => 'FR12345678901']);
ok(!$a, 'assujetti avec numéro : rien à signaler');
[, , $a] = v(['tva_regime' => 'exonere_261']);
ok(!$a, 'exonéré sans numéro : normal, aucun avertissement');

echo "\nAdresse et code postal\n";
[$o, $e] = v(['code_postal' => '75001', 'ville' => 'Paris']);
ok($o['code_postal'] === '75001' && !$e, 'code postal français valide');
[, $e] = v(['code_postal' => '7500']);
ok(count($e) === 1, 'code postal français à quatre chiffres refusé');
[, $e] = v(['code_postal' => '7500A']);
ok(count($e) === 1, 'code postal français avec une lettre refusé');
[$o, $e] = v(['code_postal' => 'SW1A 1AA', 'pays' => 'Royaume-Uni']);
ok($o['code_postal'] === 'SW1A 1AA' && !$e, 'à l\'étranger, le format est libre');
[, $e] = v(['code_postal' => '7500', 'pays' => 'france']);
ok(count($e) === 1, '« france » en minuscules reste traité comme la France');

ok(U::adresseLigne(['adresse_1' => '12 rue des Lilas', 'code_postal' => '75001', 'ville' => 'Paris'])
   === '12 rue des Lilas, 75001 Paris', 'adresse sur une ligne');
ok(U::adresseLigne(['adresse_1' => '1 rue X', 'adresse_2' => 'Bât. B', 'code_postal' => '69001', 'ville' => 'Lyon', 'pays' => 'France'])
   === '1 rue X, Bât. B, 69001 Lyon', 'complément inclus, « France » omis');
ok(U::adresseLigne(['adresse_1' => '5 High St', 'code_postal' => 'SW1', 'ville' => 'Londres', 'pays' => 'Royaume-Uni'])
   === '5 High St, SW1 Londres, Royaume-Uni', 'pays étranger affiché');
ok(U::adresseLigne([]) === '', 'adresse vide : chaîne vide');

echo "\nContact\n";
[$o, $e] = v(['telephone' => '+33 6 12 34 56 78']);
ok($o['telephone'] === '+33 6 12 34 56 78' && !$e, 'téléphone international accepté');
[, $e] = v(['telephone' => 'appelez-moi']);
ok(count($e) === 1, 'téléphone en lettres refusé');
[$o, $e] = v(['email_pro' => '  Contact@Exemple.FR ']);
ok($o['email_pro'] === 'contact@exemple.fr' && !$e, 'e-mail normalisé en minuscules');
[, $e] = v(['email_pro' => 'pas-un-mail']);
ok(count($e) === 1, 'e-mail invalide refusé');
[$o, $e] = v(['site_web' => 'www.exemple.fr']);
ok($o['site_web'] === 'https://www.exemple.fr' && !$e, 'site sans schéma : https:// ajouté');
[$o] = v(['site_web' => 'http://exemple.fr']);
ok($o['site_web'] === 'http://exemple.fr', 'schéma http conservé tel quel');

echo "\nSécurité des URL : seuls http et https sont acceptés\n";
foreach (['javascript:alert(1)', 'ftp://exemple.fr', 'data:text/html,x', 'file:///etc/passwd'] as $dangereux) {
    [$o, $e] = v(['site_web' => $dangereux]);
    ok($o['site_web'] === null || str_starts_with((string) $o['site_web'], 'https://'),
       "« $dangereux » ne produit jamais un schéma dangereux");
}
[, $e] = v(['site_web' => 'javascript:alert(1)']);
ok(count($e) === 1, 'javascript: est refusé, pas "réparé" en lien');

echo "\nDate de début d'activité\n";
[$o, $e] = v(['date_debut_activite' => '2018-03-01']);
ok($o['date_debut_activite'] === '2018-03-01' && !$e, 'date ISO acceptée');
[$o, $e] = v(['date_debut_activite' => '01/03/2018']);
ok($o['date_debut_activite'] === '2018-03-01' && !$e, 'date française acceptée');
[, $e] = v(['date_debut_activite' => 'hier']);
ok(count($e) === 1, 'date illisible refusée');
[, , $a] = v(['date_debut_activite' => '2999-01-01']);
ok(count($a) === 1, 'date future : avertissement');

echo "\nForme juridique : texte libre\n";
[$o, $e] = v(['forme_juridique' => '  EI   (ancien micro-entrepreneur) ']);
ok($o['forme_juridique'] === 'EI (ancien micro-entrepreneur)' && !$e, 'tout texte est accepté, espaces ramenés à un seul');
[$o] = v(['forme_juridique' => 'Association loi 1901']);
ok($o['forme_juridique'] === 'Association loi 1901', 'une forme que la liste ne connaissait pas');
[$o] = v(['forme_juridique' => '']);
ok($o['forme_juridique'] === null, 'vide : null');
[$o] = v(['forme_juridique' => str_repeat('é', 300)]);
ok(mb_strlen($o['forme_juridique']) === 100, 'tronquée à 100 caractères, comptés en lettres et non en octets');

echo "\nTextes libres\n";
[$o] = v(['denomination' => "  EI   Czyhir \n  Hélène  "]);
ok($o['denomination'] === 'EI Czyhir Hélène', 'espaces et retours à la ligne ramenés à un espace');
[$o] = v(['denomination' => str_repeat('é', 400)]);
ok(mb_strlen($o['denomination']) === 190, 'tronqué à 190 caractères (en caractères, pas en octets)');
[$o] = v(['profession' => '   ']);
ok($o['profession'] === null, 'champ vide ou blanc → null, pas une chaîne vide');
[$o] = v(['adresse_1' => '<script>alert(1)</script>']);
ok($o['adresse_1'] === '<script>alert(1)</script>', 'texte conservé tel quel : l\'échappement se fait à l\'affichage');

echo "\nCohérence entre la validation et la lecture du profil\n";
/* Une colonne validée et enregistrée mais absente de COLONNES_PROFIL serait
   écrite en base sans jamais être relue : c'est le bug qui avait fait
   apparaître une fiche vide après enregistrement. */
[$sortie] = U::valider(['nom' => 'X']);
$validees = array_diff(array_keys($sortie), ['nom']);
sort($validees);
$lues = U::COLONNES_PROFIL;
sort($lues);
ok($validees === $lues, 'valider() et COLONNES_PROFIL portent exactement les mêmes champs');
ok(count(U::COLONNES_PROFIL) === count(array_unique(U::COLONNES_PROFIL)), 'aucune colonne en double');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
