<?php
declare(strict_types=1);

require __DIR__ . '/autoload.php';

use Bnc\Config;
use Bnc\Auth;
use Bnc\Plugin;

$fichierConfig = __DIR__ . '/../config/config.php';

// Application pas encore installée : on oriente vers l'installateur
// plutôt que de laisser remonter une erreur fatale.
if (!is_file($fichierConfig)) {
    if (is_file(__DIR__ . '/../public/install.php')) {
        header('Location: /install.php');
        exit;
    }
    http_response_code(503);
    exit('Application non configurée : copier config/config.example.php en config/config.php.');
}

Config::charger($fichierConfig);

date_default_timezone_set((string) Config::get('app.fuseau', 'Europe/Paris'));
mb_internal_encoding('UTF-8');

if (Config::get('app.debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

Auth::demarrer();

/**
 * Dernier filet : une exception non rattrapée donnait la page blanche d'un 500,
 * sans un mot d'explication. On la consigne au journal du serveur avec une
 * référence, et on montre une page qui dit quoi faire.
 *
 * Le cas le plus fréquent après une mise à jour est une base restée en retard
 * sur le code (colonne ou table absente, SQLSTATE 42S22 / 42S02) : il reçoit
 * un message à part, qui renvoie à l'application des migrations plutôt que de
 * laisser croire à un défaut du logiciel.
 *
 * Le détail technique n'est montré qu'en mode débogage : il peut contenir des
 * noms de tables, voire des fragments de requête.
 */
set_exception_handler(static function (\Throwable $e): void {
    $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log(sprintf('[AltoCompta %s] %s : %s (%s:%d)',
        $reference, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    if (!headers_sent()) {
        http_response_code(500);
    }

    $schemaEnRetard = $e instanceof \PDOException && in_array((string) $e->getCode(), ['42S22', '42S02'], true);

    $titre = $schemaEnRetard ? 'La base de données n\'est pas à jour' : 'Une erreur est survenue';
    $message = $schemaEnRetard
        ? 'Le code de l\'application est plus récent que la base de données : une colonne ou une table '
        . 'attendue est absente. Cela arrive quand une mise à jour n\'a pas pu appliquer toutes ses migrations. '
        . 'Un administrateur peut les appliquer depuis « Mise à jour » (bouton « Appliquer les migrations »). '
        . 'Aucune donnée n\'est perdue.'
        : 'L\'opération n\'a pas pu aboutir. L\'erreur a été consignée dans le journal du serveur.';
    $message .= ' Référence : ' . $reference . '.';
    if (Config::get('app.debug')) {
        $message .= ' — ' . get_class($e) . ' : ' . $e->getMessage()
                  . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }

    try {
        \Bnc\View::rendre('erreur', ['titre_page' => $titre, 'titre' => $titre, 'message' => $message], 'layout/nu');
    } catch (\Throwable) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $titre . "\n\n" . $message . "\n";
    }
});

// Les extensions actives sont chargées ici : autoloaders, gabarits, hooks.
// Une base pas encore migrée ne doit pas empêcher l'application de démarrer.
try {
    Plugin::charger();
} catch (\Throwable $e) {
    if (Config::get('app.debug')) {
        throw $e;
    }
}
