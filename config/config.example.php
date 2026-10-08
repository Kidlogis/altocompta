<?php
/**
 * Copier ce fichier en config/config.php et l'adapter.
 * config/config.php est ignoré par Git : il contient des secrets.
 */
return [
    'db' => [
        'hote'     => '127.0.0.1',
        'port'     => 3306,
        'base'     => 'bnc',
        'user'     => 'bnc',
        'mdp'      => 'A_REMPLACER',
    ],

    // Clé maître de chiffrement des clés API Altoviz.
    // Générer avec :  php bin/generer-cle.php
    'cle_maitre' => 'A_REMPLACER',

    // Répertoire des justificatifs, HORS docroot.
    'stockage' => __DIR__ . '/../storage/justificatifs',

    'app' => [
        'nom'         => 'AltoCompta',
        'url'         => 'https://compta.exemple.fr',
        'fuseau'      => 'Europe/Paris',
        'debug'       => false,
        'session_nom' => 'altocompta',
    ],

    'altoviz' => [
        'base_url' => 'https://api.altoviz.com',
        'timeout'  => 30,
    ],
];
