<?php
declare(strict_types=1);
/**
 * Point d'entrée des notifications des services connectés.
 *
 * URL à déclarer côté service (Altoviz) :
 *   https://VOTRE-DOMAINE/webhook.php?c=<id de connexion>&s=<secret>
 *
 * Le secret est propre à chaque connexion et comparé en temps constant. Le cœur
 * n'interprète rien : il passe la notification aux connecteurs installés qui
 * savent en recevoir, et répond ce que le premier à reconnaître la connexion
 * a décidé. Une connexion inconnue et un secret faux reçoivent la même réponse.
 */
$racine = dirname(__DIR__);
$fichierConfig = $racine . '/config/config.php';

if (!is_file($fichierConfig)) {
    http_response_code(503);
    exit('non configuré');
}

require $racine . '/src/autoload.php';

use Bnc\Config;
use Bnc\Plugin;

Config::charger($fichierConfig);
date_default_timezone_set((string) Config::get('app.fuseau', 'Europe/Paris'));
header('Content-Type: text/plain; charset=UTF-8');

$id     = (int) ($_GET['c'] ?? 0);
$secret = (string) ($_GET['s'] ?? '');

$refuser = static function (): never {
    http_response_code(404);
    exit('inconnu');
};

if ($id <= 0 || $secret === '') {
    $refuser();
}

try {
    Plugin::charger();
    foreach (Plugin::connecteurs() as $c) {
        if ($c['webhook'] === null) {
            continue;
        }
        $reponse = ($c['webhook'])($id, $secret);
        if ($reponse !== null) {
            http_response_code((int) $reponse['code']);
            exit((string) $reponse['texte']);
        }
    }
} catch (\Throwable $e) {
    error_log('[AltoCompta webhook] ' . $e->getMessage());
    http_response_code(500);
    exit('erreur');
}

$refuser();
