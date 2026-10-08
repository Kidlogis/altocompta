<?php
declare(strict_types=1);
/**
 * Applique le schéma initial puis les migrations en attente.
 *   php db/migrate.php            applique
 *   php db/migrate.php --etat     liste sans rien appliquer
 *
 * Fine couche CLI au-dessus de Bnc\Migration, qui porte la logique réelle —
 * partagée avec le module de mise à jour accessible depuis l'interface.
 */
require __DIR__ . '/../src/autoload.php';

use Bnc\Config;
use Bnc\Migration;

$fichierConfig = __DIR__ . '/../config/config.php';
if (!is_file($fichierConfig)) {
    fwrite(STDERR, "AltoCompta n'est pas installé : config/config.php est absent.\n");
    exit(1);
}
Config::charger($fichierConfig);

$etatSeul = in_array('--etat', $argv, true);

if (!Migration::estInstallee()) {
    if ($etatSeul) {
        echo "Base vide : le schéma initial reste à appliquer.\n";
        exit(0);
    }
    $r = Migration::executer();
    echo "Application du schéma initial…\n";
    foreach ($r['appliquees'] as $v) {
        echo "  $v\n";
    }
    exit(0);
}

$enAttente = Migration::enAttente();
$reprises  = Migration::repriseEnAttente();

if ($etatSeul) {
    printf("%d migration(s) appliquée(s), %d en attente.\n", count(Migration::appliquees()), count($enAttente));
    foreach ($enAttente as $v => $_) {
        echo "  en attente : $v\n";
    }
    foreach ($reprises as $v) {
        echo "  reprise de données en attente : $v\n";
    }
    exit(0);
}

if (!$enAttente && !$reprises) {
    echo "Rien à appliquer, la base est à jour.\n";
    exit(0);
}

$r = Migration::executer();
foreach ($r['appliquees'] as $v) {
    echo "  $v\n";
}
if ($r['erreur'] !== null) {
    fwrite(STDERR, $r['erreur'] . "\n");
    fwrite(STDERR, "Les migrations précédentes restent appliquées. Corriger puis relancer.\n");
    exit(1);
}
echo "Terminé.\n";
