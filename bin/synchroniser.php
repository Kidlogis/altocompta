<?php
declare(strict_types=1);
/**
 * Synchronisation planifiée de tous les connecteurs bancaires installés.
 *
 * Ligne de tâche planifiée à poser (gestionnaire de cron de l'hébergeur, ou
 * crontab utilisateur sur un VPS) : voir README.md, section « Tâche planifiée ».
 * Cadence conseillée : toutes les 30 minutes — chaque connexion applique ensuite
 * sa propre fréquence.
 *
 * Le script ne connaît aucun service par son nom : il appelle le point d'entrée
 * « synchroniser » que chaque extension déclare dans son manifeste.
 *
 * Options :
 *   --force            ignore la cadence et synchronise toutes les connexions actives
 *   --source=NOM       ne traite que ce connecteur (altoviz, evoliz…)
 *   --connexion=N      ne traite que la connexion N ; exige --source, un numéro
 *                      de connexion n'ayant de sens que dans un connecteur
 *   --silencieux       n'écrit rien sur la sortie standard
 */
require __DIR__ . '/../src/autoload.php';

use Bnc\Config;
use Bnc\Plugin;

$fichierConfig = __DIR__ . '/../config/config.php';
if (!is_file($fichierConfig)) {
    fwrite(STDERR, "AltoCompta n'est pas encore installé : config/config.php est absent.\n");
    exit(1);
}
Config::charger($fichierConfig);
date_default_timezone_set((string) Config::get('app.fuseau', 'Europe/Paris'));

$options    = getopt('', ['force', 'source::', 'connexion::', 'silencieux']);
$force      = array_key_exists('force', $options);
$silencieux = array_key_exists('silencieux', $options);
$ciblee     = isset($options['connexion']) ? (int) $options['connexion'] : null;
// Un script d'alias (bin/sync-altoviz.php) peut imposer le connecteur.
$source     = isset($options['source']) ? (string) $options['source'] : ($GLOBALS['sync_source'] ?? null);

$dire = static function (string $m) use ($silencieux): void {
    if (!$silencieux) {
        echo date('H:i:s'), '  ', $m, PHP_EOL;
    }
};

if ($ciblee !== null && $source === null) {
    fwrite(STDERR, "--connexion exige --source : un numéro de connexion n'a de sens que dans un connecteur.\n");
    exit(2);
}

// Un cron toutes les 30 minutes peut chevaucher une synchronisation lente.
// Le verrou évite deux passages simultanés.
$verrou = fopen(sys_get_temp_dir() . '/altocompta-sync.lock', 'c');
if ($verrou === false || !flock($verrou, LOCK_EX | LOCK_NB)) {
    $dire('Une synchronisation est déjà en cours, passage ignoré.');
    exit(0);
}

try {
    Plugin::charger();
    $connecteurs = array_filter(
        Plugin::connecteurs(),
        static fn($c) => $c['synchroniser'] !== null && ($source === null || $c['source'] === $source)
    );

    if (!$connecteurs) {
        $dire($source !== null
            ? "Aucun connecteur actif pour la source « $source »."
            : 'Aucun connecteur actif.');
        exit(0);
    }

    $traitees = $nouveaux = $erreurs = 0;
    foreach ($connecteurs as $c) {
        $dire('== ' . $c['nom'] . ' ==');
        try {
            $r = ($c['synchroniser'])($force, $ciblee, $dire);
        } catch (\Throwable $e) {
            // Un connecteur défaillant ne doit pas empêcher les suivants.
            $erreurs++;
            $traitees++;
            $dire('  ÉCHEC : ' . $e->getMessage());
            continue;
        }
        $traitees += (int) ($r['traitees'] ?? 0);
        $nouveaux += (int) ($r['nouveaux'] ?? 0);
        $erreurs  += (int) ($r['erreurs'] ?? 0);
        if (($r['traitees'] ?? 0) === 0) {
            $dire('  Aucune connexion à synchroniser.');
        }
    }

    $dire(sprintf('Terminé : %d nouvelle(s) opération(s), %d connexion(s) en erreur sur %d traitée(s).',
        $nouveaux, $erreurs, $traitees));

    // Code de sortie non nul si tout a échoué : exploitable par une supervision.
    exit($erreurs > 0 && $erreurs === $traitees ? 1 : 0);

} finally {
    flock($verrou, LOCK_UN);
    fclose($verrou);
}
