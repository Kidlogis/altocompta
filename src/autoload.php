<?php
declare(strict_types=1);

spl_autoload_register(static function (string $classe): void {
    if (!str_starts_with($classe, 'Bnc\\')) {
        return;
    }
    $chemin = __DIR__ . '/' . str_replace('\\', '/', substr($classe, 4)) . '.php';
    if (is_file($chemin)) {
        require $chemin;
    }
});
