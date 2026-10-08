<?php
declare(strict_types=1);
/**
 * Alias historique de bin/synchroniser.php, limité au connecteur Altoviz.
 *
 * Ce nom figure dans la documentation depuis les premières versions : une tâche
 * planifiée déjà posée continue donc de fonctionner. Les options sont celles de
 * bin/synchroniser.php ; --connexion=N s'applique à Altoviz.
 */
$GLOBALS['sync_source'] = 'altoviz';
require __DIR__ . '/synchroniser.php';
