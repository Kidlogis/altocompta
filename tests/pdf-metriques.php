<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\MetriquesHelvetica as M;
use Bnc\Pdf;
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

echo "Largeurs conformes aux métriques AFM d'Helvetica (millièmes d'em)\n";
/* Valeurs de référence publiées pour Helvetica : H 722, e 556, l 222, o 556. */
ok(M::largeur('Hello', false) === 722 + 556 + 222 + 222 + 556, 'Hello en Helvetica = 2278');
/* Helvetica-Bold : H 722, e 556, l 278, o 611. */
ok(M::largeur('Hello', true) === 722 + 556 + 278 + 278 + 611, 'Hello en Helvetica-Bold = 2445');
ok(M::largeur('iiii', false) === 4 * 222, 'quatre « i » étroits = 888');
ok(M::largeur('mmmm', false) === 4 * 833, 'quatre « m » larges = 3332');
ok(M::largeur('', false) === 0, 'chaîne vide = 0');
ok(M::largeur('é', false) === M::largeur('e', false), 'un e accentué a la largeur du e');
ok(M::largeur("a\u{00A0}b", false) === M::largeur('a b', false), 'espace insécable = espace ordinaire');
ok(M::largeur('x', true) !== M::largeur('x', false) || M::largeur('mw', true) > M::largeur('mw', false),
   'le gras est mesuré avec sa propre table');
ok(M::largeur("\u{4E2D}", false) === M::DEFAUT, 'caractère hors table : largeur de repli, pas d\'erreur');

echo "\nChiffres tabulaires : tous les chiffres ont la même largeur\n";
$lg = array_map(fn($c) => M::largeur((string) $c, false), range(0, 9));
ok(count(array_unique($lg)) === 1, 'sans quoi des montants ne s\'aligneraient pas à droite');

echo "\nNormalisation des caractères absents de Windows-1252\n";
ok(Pdf::normaliser("\u{2212}1 234,50") === '-1 234,50', 'le signe moins typographique devient un tiret ASCII');
ok(Pdf::normaliser("1\u{202F}000") === '1 000', 'espace fine insécable → espace');
ok(Pdf::normaliser('texte normal é à ç') === 'texte normal é à ç', 'un texte ordinaire n\'est pas modifié');

$p = new Pdf('t', 't');
$echap = new ReflectionMethod($p, 'echapper'); $echap->setAccessible(true);
ok(!str_contains($echap->invoke($p, "\u{2212}12,00"), '?'), 'un montant négatif ne s\'imprime plus avec un « ? »');
ok($echap->invoke($p, "\u{2212}12") === '-12', 'et s\'imprime avec un tiret');

echo "\nLa mesure du PDF et l'impression voient le même texte\n";
ok(abs($p->largeurTexte("\u{2212}1 234,50", 8) - $p->largeurTexte('-1 234,50', 8)) < 1e-9,
   'mesurer « −12 » ou « -12 » donne la même largeur : on mesure ce qui est imprimé');

echo "\nOrdre de grandeur, contrôlé contre le rendu réel d'un lecteur\n";
/* Phrase mesurée à 188,9 mm par poppler en Helvetica 7,5 pt (TeX Gyre Heros). */
$phrase = "Les montants sont ceux effectivement encaissés sur l'exercice, hors toute facturation non réglée. "
        . "1 écriture annulée, barrée ci-dessus, reste inscrite au registre et";
$mm = $p->largeurTexte($phrase, 7.5);
ok(abs($mm - 188.9) < 1.5, sprintf('largeur calculée %.1f mm pour 188,9 mm rendus (écart %.1f mm)', $mm, abs($mm - 188.9)));
ok($mm > 179.9, 'nettement au-dessus de l\'ancienne estimation (179,9 mm) qui laissait déborder');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
