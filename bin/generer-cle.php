<?php
declare(strict_types=1);
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Crypto.php';

echo "Clé maître à recopier dans config/config.php (champ cle_maitre) :\n\n";
echo Bnc\Crypto::genererCleMaitre(), "\n\n";
echo "Conserver cette clé : sans elle, les clés API enregistrées sont irrécupérables.\n";
