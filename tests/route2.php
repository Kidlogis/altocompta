<?php
require __DIR__ . '/../src/autoload.php';
use Bnc\Router;
$tr = new ArrayObject();
$r = new Router();
$r->post('/affectation/{id:\d+}',              function($p) use($tr){ $tr->append('affecter:'.$p['id']); });
$r->post('/affectation/valider-suggestions',   function() use($tr){ $tr->append('valider'); });
$r->post('/affectation/tout-ignorer',          function() use($tr){ $tr->append('ignorer-tout'); });
$r->get('/recettes/{id:\d+}/modifier',         function($p) use($tr){ $tr->append('edit:'.$p['id']); });
$r->get('/recettes/nouvelle',                  function() use($tr){ $tr->append('nouvelle'); });

$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

ob_start();
$r->dispatch('POST','/affectation/42');
$r->dispatch('POST','/affectation/valider-suggestions');
$r->dispatch('POST','/affectation/tout-ignorer');
$r->dispatch('GET','/recettes/7/modifier');
$r->dispatch('GET','/recettes/nouvelle');
ob_end_clean();

$got = iterator_to_array($tr);
ok($got === ['affecter:42','valider','ignorer-tout','edit:7','nouvelle'],
   'routes litterales et numeriques distinguees : '.implode(' | ',$got));

// Un id non numerique ne doit plus matcher la route parametree
$avant = count($got);
ob_start(); $r->dispatch('POST','/affectation/nimportequoi'); ob_get_clean();
ok(count(iterator_to_array($tr)) === $avant, 'id non numerique -> 404, pas de match parasite');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
