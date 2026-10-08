<?php
require __DIR__ . '/../src/autoload.php';
$ko=0; function ok($c,$m){ global $ko; echo ($c?"  ok   ":"  FAIL ").$m."\n"; if(!$c)$ko++; }

/* cheminSur() est privée : reproduite ici à l'identique pour la mettre à
   l'épreuve d'une vraie batterie d'attaques zip slip, sans dépendre du
   disque ni d'une archive réelle. */
function chemin_sur(string $relatif): bool
{
    if ($relatif === '' || str_starts_with($relatif, '/') || preg_match('#^[a-zA-Z]:#', $relatif)) {
        return false;
    }
    foreach (explode('/', $relatif) as $segment) {
        if ($segment === '.' || $segment === '..' || $segment === '') {
            return false;
        }
    }
    return !preg_match('/[\x00-\x1F]/', $relatif);
}

echo "Chemins légitimes acceptés\n";
foreach ([
    'public/index.php', 'src/Recette.php', 'templates/layout/base.php',
    'plugins/altoviz/src/Client.php', 'VERSION', 'db/migrations/002_inalterabilite.sql',
] as $c) {
    ok(chemin_sur($c), "accepté : $c");
}

echo "\nTraversée de répertoire (zip slip), le cas classique\n";
foreach ([
    '../../../../etc/cron.d/backdoor',
    '../../../public_html/.htaccess',
    'public/../../../../etc/passwd',
    '../config/config.php',
    'src/../../../../tmp/evil.php',
    '..',
    '../',
] as $c) {
    ok(!chemin_sur($c), "rejeté : $c");
}

echo "\nChemins absolus : une archive ne doit jamais pouvoir cibler un chemin système\n";
foreach (['/etc/passwd', '/var/www/html/shell.php', 'C:\\Windows\\System32\\evil.dll', 'C:/Windows/evil.php'] as $c) {
    ok(!chemin_sur($c), "rejeté : $c");
}

echo "\nSegments vides ou astucieux\n";
foreach (['public//index.php', 'src/./Recette.php', './src/Recette.php', 'public/index.php/'] as $c) {
    ok(!chemin_sur($c), "rejeté : $c");
}

echo "\nCaractères de contrôle (octet nul notamment, classique en contournement C)\n";
ok(!chemin_sur("public/index.php\x00.jpg"), 'rejeté : octet nul dans le nom');
ok(!chemin_sur("src/\x01\x02evil.php"), 'rejeté : caractères de contrôle');

echo "\nUn nom de fichier ou dossier contenant '..' sans être un segment entier reste légitime\n";
/* Le test porte sur les SEGMENTS, pas une sous-chaîne : un dossier nommé
   « mon..dossier » ne doit pas être rejeté à tort. */
ok(chemin_sur('src/mon..dossier/fichier.php'), 'accepté : ".." en sous-chaîne d\'un segment, pas le segment entier');
ok(chemin_sur('public/v1.2..3/notes.txt'), 'accepté : point de version dans un nom de fichier');

echo "\nProtection de config.php et storage/, deuxième filet indépendant du premier\n";
function protege(string $relatif): bool
{
    return $relatif === 'config/config.php' || str_starts_with($relatif, 'storage/');
}
ok(protege('config/config.php'), 'config/config.php toujours protégé');
ok(!protege('config/config.example.php'), 'config.example.php, lui, se met à jour normalement');
ok(protege('storage/justificatifs/1/facture.pdf'), 'tout storage/ protégé, pas seulement justificatifs/');
ok(protege('storage/sauvegardes/x.zip'), 'y compris les sauvegardes elles-mêmes');
ok(!protege('src/Recette.php'), 'le code applicatif ordinaire n\'est pas protégé : il doit se mettre à jour');

echo $ko ? "\n$ko echec(s)\n" : "\nTout passe.\n";
exit($ko?1:0);
