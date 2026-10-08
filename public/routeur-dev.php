<?php
/**
 * Routeur du serveur PHP intégré (php -S), pour le développement.
 * Reproduit la règle d'Apache : un fichier réel est servi tel quel,
 * tout le reste part dans index.php.
 * N'est pas utilisé en production.
 */
$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fichier = __DIR__ . $chemin;

if ($chemin !== '/' && is_file($fichier)) {
    return false;   // le serveur sert le fichier directement
}
require __DIR__ . '/index.php';
