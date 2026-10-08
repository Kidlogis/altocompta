<?php
declare(strict_types=1);
/**
 * Crée un utilisateur.  php bin/creer-utilisateur.php email@exemple.fr "Nom Prénom"
 * Le mot de passe est demandé de façon interactive.
 */
require __DIR__ . '/../src/autoload.php';

use Bnc\Config;
use Bnc\Database;
use Bnc\Exercice;

Config::charger(__DIR__ . '/../config/config.php');

$email = $argv[1] ?? null;
$nom   = $argv[2] ?? null;

if (!$email || !$nom) {
    fwrite(STDERR, "Usage : php bin/creer-utilisateur.php email \"Nom\"\n");
    exit(1);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Adresse e-mail invalide.\n");
    exit(1);
}

echo 'Mot de passe : ';
shell_exec('stty -echo 2>/dev/null');
$mdp = trim((string) fgets(STDIN));
shell_exec('stty echo 2>/dev/null');
echo "\n";

if (strlen($mdp) < 12) {
    fwrite(STDERR, "12 caractères minimum.\n");
    exit(1);
}

$id = Database::inserer('users', [
    'email'        => mb_strtolower($email),
    'mot_de_passe' => password_hash($mdp, PASSWORD_DEFAULT),
    'nom'          => $nom,
    'role'         => 'user',
    'actif'        => 1,
    'cree_le'      => date('Y-m-d H:i:s'),
]);

Exercice::creer($id, (int) date('Y'));
echo "Utilisateur #{$id} créé, exercice " . date('Y') . " ouvert.\n";
