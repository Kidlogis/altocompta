<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Mise à jour depuis l'interface : dépose un zip, l'application remplace
 * ses propres fichiers puis applique les migrations en attente.
 *
 * C'est une fonctionnalité à haut risque par construction — accorder à un
 * compte le pouvoir d'écraser le code qui s'exécute est, en substance,
 * accorder une exécution de code à distance. Les précautions prises :
 *
 *  - Réservé aux administrateurs (contrôlé par l'appelant).
 *  - Chaque entrée du zip est validée avant écriture : un chemin qui
 *    contient « .. », qui est absolu, ou dont la résolution finale sortirait
 *    du dossier visé, fait échouer l'opération entière — c'est la défense
 *    contre le « zip slip », où une archive peut sinon écrire n'importe où
 *    sur le disque via des chemins comme « ../../../../etc/cron.d/x ».
 *  - `config/config.php` et tout `storage/` existant ne sont jamais
 *    touchés, même si le zip en contenait (il ne devrait jamais).
 *  - Une sauvegarde complète est créée avant toute écriture, et conservée :
 *    rien n'est automatique sans un point de retour.
 *  - Rien ne s'applique tant que l'administrateur n'a pas confirmé un
 *    aperçu (nombre de fichiers, détection d'une version) : jamais en un
 *    seul clic silencieux.
 */
final class MiseAJour
{
    /** Sauvegardes conservées ; les plus anciennes sont purgées au-delà. */
    private const SAUVEGARDES_CONSERVEES = 5;

    public static function extensionDisponible(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    private static function docroot(): string
    {
        // Le dossier contenant le point d'entrée public actuellement servi —
        // « public_html » chez l'hébergeur, quel que soit son nom réel.
        return dirname($_SERVER['SCRIPT_FILENAME'] ?? (__DIR__ . '/../public/index.php'));
    }

    private static function racine(): string
    {
        return dirname(self::docroot());
    }

    private static function dossierSauvegardes(): string
    {
        $d = self::racine() . '/storage/sauvegardes';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    /**
     * Ouvre le zip et en extrait un aperçu, sans rien écrire sur le disque
     * applicatif. Le fichier est conservé dans un dossier temporaire dont le
     * chemin est retourné, à repasser à appliquer() pour éviter un second
     * envoi.
     *
     * @return array{ok:bool, erreur:?string, chemin:?string, nb_fichiers:int,
     *                nb_public:int, nb_autres:int, version:?string}
     */
    public static function analyser(string $cheminEnvoye, string $nomOriginal): array
    {
        if (!self::extensionDisponible()) {
            return self::echec('L\'extension PHP zip n\'est pas disponible sur ce serveur.');
        }
        if (!preg_match('/\.zip$/i', $nomOriginal)) {
            return self::echec('Le fichier envoyé n\'est pas un .zip.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($cheminEnvoye) !== true) {
            return self::echec('Archive illisible ou corrompue.');
        }

        $racine = self::detecterPrefixe($zip);
        $nbPublic = 0;
        $nbAutres = 0;
        $version = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = $zip->getNameIndex($i);
            if ($nom === false || str_ends_with($nom, '/')) {
                continue;   // un dossier, pas un fichier
            }
            $relatif = self::sansPrefixe($nom, $racine);
            if ($relatif === null) {
                continue;
            }
            if (!self::cheminSur($relatif)) {
                $zip->close();
                return self::echec("Chemin suspect dans l'archive : {$nom}");
            }
            if (str_starts_with($relatif, 'public/')) {
                $nbPublic++;
            } else {
                $nbAutres++;
            }
            if ($relatif === 'VERSION') {
                $version = trim((string) $zip->getFromIndex($i));
            }
        }
        $zip->close();

        if ($nbPublic === 0 && $nbAutres === 0) {
            return self::echec('Archive vide, ou structure non reconnue.');
        }

        // Déplacé vers un emplacement stable : le fichier temporaire d'upload
        // disparaît à la fin de la requête PHP.
        $stable = sys_get_temp_dir() . '/altocompta-maj-' . bin2hex(random_bytes(8)) . '.zip';
        if (!copy($cheminEnvoye, $stable)) {
            return self::echec('Impossible de conserver l\'archive pour confirmation.');
        }

        return [
            'ok' => true, 'erreur' => null, 'chemin' => $stable,
            'nb_fichiers' => $nbPublic + $nbAutres, 'nb_public' => $nbPublic, 'nb_autres' => $nbAutres,
            'version' => $version,
        ];
    }

    /**
     * Applique une archive déjà analysée : sauvegarde, puis remplacement des
     * fichiers, puis migrations.
     *
     * @return array{ok:bool, erreur:?string, nb_fichiers:int, sauvegarde:?string,
     *                migrations:array<int,string>}
     */
    public static function appliquer(string $cheminZip): array
    {
        if (!is_file($cheminZip)) {
            return self::echecApplication('Archive introuvable : l\'avez-vous bien confirmée dans les temps ?');
        }

        $zip = new \ZipArchive();
        if ($zip->open($cheminZip) !== true) {
            return self::echecApplication('Archive illisible au moment de l\'appliquer.');
        }

        $racinePrefixe = self::detecterPrefixe($zip);
        $docroot = self::docroot();
        $racineApp = self::racine();

        // Toute entrée est revalidée ici aussi : l'aperçu et l'application
        // sont deux requêtes distinctes, on ne fait confiance à rien
        // d'affirmé entre les deux.
        $operations = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = $zip->getNameIndex($i);
            if ($nom === false || str_ends_with($nom, '/')) {
                continue;
            }
            $relatif = self::sansPrefixe($nom, $racinePrefixe);
            if ($relatif === null || !self::cheminSur($relatif)) {
                $zip->close();
                return self::echecApplication("Chemin suspect dans l'archive : {$nom}");
            }
            if (self::protege($relatif)) {
                continue;   // config/config.php, storage/* : jamais écrasés
            }

            $cible = str_starts_with($relatif, 'public/')
                ? $docroot . '/' . substr($relatif, 7)
                : $racineApp . '/' . $relatif;

            // Le chemin final doit rester sous le dossier visé — seconde
            // barrière, après la validation syntaxique de cheminSur().
            $base = str_starts_with($relatif, 'public/') ? $docroot : $racineApp;
            $resolu = self::resoudre($cible);
            if ($resolu === null || !str_starts_with($resolu, rtrim(self::resoudre($base) ?? $base, '/') . '/')) {
                $zip->close();
                return self::echecApplication("Cible hors périmètre refusée : {$relatif}");
            }

            $operations[] = ['index' => $i, 'cible' => $cible, 'public' => str_starts_with($relatif, 'public/')];
        }
        $zip->close();

        if (!$operations) {
            return self::echecApplication('Aucun fichier à écrire après filtrage.');
        }

        [$sauvegarde, $errSauvegarde] = self::sauvegarder();
        if ($errSauvegarde !== '') {
            return self::echecApplication('Sauvegarde impossible, mise à jour annulée par prudence : ' . $errSauvegarde);
        }

        set_time_limit(120);
        $zip = new \ZipArchive();
        $zip->open($cheminZip);

        $ecrits = 0;
        foreach ($operations as $op) {
            $contenu = $zip->getFromIndex($op['index']);
            if ($contenu === false) {
                continue;
            }
            $dossier = dirname($op['cible']);
            // Le dossier public est servi par le serveur web, qui n'est pas toujours le propriétaire ni dans le groupe :
            // un dossier nouveau en 0750 y renverrait un 403 sur tout ce qu'il contient, alors que le reste du site marche.
            $mode = $op['public'] ? 0755 : 0750;
            if (!is_dir($dossier) && !mkdir($dossier, $mode, true) && !is_dir($dossier)) {
                $zip->close();
                return self::echecApplication('Impossible de créer ' . $dossier . '. Vérifier les droits d\'écriture.');
            }
            if ($op['public']) {
                self::ouvrirAuWeb($dossier, $docroot);   // aussi pour un dossier créé par une version antérieure en 0750
            }
            if (file_put_contents($op['cible'], $contenu) === false) {
                $zip->close();
                return self::echecApplication('Échec d\'écriture sur ' . $op['cible'] . '. Vérifier les droits d\'écriture.');
            }
            $ecrits++;
        }
        $zip->close();
        @unlink($cheminZip);

        // Les fichiers viennent d'être remplacés : vider le cache d'opcodes, sans quoi
        // les requêtes suivantes mélangeraient anciennes et nouvelles versions.
        Opcache::vider();

        // Les migrations ne se font PAS ici : cette requête a déjà chargé en mémoire
        // des classes de l'ancienne version, et une migration qui s'appuierait sur
        // le nouveau code (une reprise de données, par exemple) en exécuterait des
        // morceaux périmés. Elles tournent dans la requête suivante, avec le code
        // neuf : voir la route /mise-a-jour/finaliser.
        return [
            'ok' => true, 'erreur' => null,
            'nb_fichiers' => $ecrits, 'sauvegarde' => $sauvegarde, 'migrations' => [],
        ];
    }

    /**
     * Migrations en attente : celles du cœur, la reprise de données éventuelle,
     * puis celles des extensions actives. Vide quand la base est à jour.
     *
     * @return array<int,string>
     */
    public static function migrationsEnAttente(): array
    {
        $liste = array_keys(Migration::enAttente());
        foreach (Migration::repriseEnAttente() as $r) {
            $liste[] = $r;
        }
        foreach (array_keys(Plugin::actifs()) as $identifiant) {
            foreach (Plugin::migrationsEnAttente($identifiant) as $v) {
                $liste[] = $identifiant . ' : ' . $v;
            }
        }
        return $liste;
    }

    /**
     * Applique tout ce qui est en attente, dans l'ordre : le cœur d'abord — une
     * migration d'extension peut en dépendre —, puis chaque extension active.
     *
     * Appelée à la fin d'une mise à jour, et depuis le bouton de la page
     * Mise à jour : sur un hébergement mutualisé sans accès SSH, c'est le seul
     * moyen de rattraper une base restée en retard sur le code.
     *
     * @return array{appliquees:array<int,string>, erreur:?string}
     */
    public static function migrer(): array
    {
        $r = Migration::executer();
        $appliquees = $r['appliquees'];
        if ($r['erreur'] !== null) {
            return ['appliquees' => $appliquees, 'erreur' => $r['erreur']];
        }

        // Les extensions actives peuvent avoir gagné des migrations entre deux
        // versions : sans elles, leur schéma resterait en retard sur leur code.
        foreach (array_keys(Plugin::actifs()) as $identifiant) {
            $attente = Plugin::migrationsEnAttente($identifiant);
            if (!$attente) {
                continue;
            }
            $err = Plugin::mettreAJourMigrations($identifiant);
            if ($err !== '') {
                return ['appliquees' => $appliquees,
                    'erreur' => 'Migration de l\'extension « ' . $identifiant . ' » en échec : ' . $err];
            }
            foreach ($attente as $v) {
                $appliquees[] = $identifiant . ' : ' . $v;
            }
        }
        return ['appliquees' => $appliquees, 'erreur' => null];
    }

    /**
     * Sauvegarde intégrale de l'installation courante avant mise à jour —
     * y compris config.php et storage/, contrairement aux exclusions
     * appliquées à l'archive entrante : une sauvegarde sert à tout
     * restaurer, pas à protéger sélectivement.
     *
     * @return array{0:?string, 1:string} nom du fichier de sauvegarde, ou motif d'échec
     */
    public static function sauvegarder(): array
    {
        if (!self::extensionDisponible()) {
            return [null, 'Extension zip indisponible.'];
        }

        $nom = 'sauvegarde-' . date('Y-m-d-His') . '.zip';
        $chemin = self::dossierSauvegardes() . '/' . $nom;

        $zip = new \ZipArchive();
        if ($zip->open($chemin, \ZipArchive::CREATE) !== true) {
            return [null, 'Création de l\'archive de sauvegarde impossible.'];
        }

        $ajouterDossier = static function (string $dossier, string $prefixeZip) use ($zip, &$ajouterDossier): void {
            if (!is_dir($dossier)) {
                return;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fichier) {
                /** @var \SplFileInfo $fichier */
                if (!$fichier->isFile()) {
                    continue;
                }
                $relatif = substr($fichier->getPathname(), strlen($dossier) + 1);
                // La sauvegarde ne se sauvegarde pas elle-même.
                if (str_starts_with($prefixeZip . '/' . $relatif, 'storage/sauvegardes/')) {
                    continue;
                }
                $zip->addFile($fichier->getPathname(), $prefixeZip . '/' . $relatif);
            }
        };

        $ajouterDossier(self::docroot(), 'public');
        foreach (['src', 'db', 'bin', 'templates', 'tests', 'plugins', 'config', 'storage'] as $d) {
            $ajouterDossier(self::racine() . '/' . $d, $d);
        }
        if (is_file(self::racine() . '/VERSION')) {
            $zip->addFile(self::racine() . '/VERSION', 'VERSION');
        }

        $zip->close();
        self::purgerAnciennes();

        return [$nom, ''];
    }

    private static function purgerAnciennes(): void
    {
        $fichiers = glob(self::dossierSauvegardes() . '/sauvegarde-*.zip') ?: [];
        rsort($fichiers);   // le plus récent nom d'abord, le format de date le permet
        foreach (array_slice($fichiers, self::SAUVEGARDES_CONSERVEES) as $f) {
            @unlink($f);
        }
    }

    /** @return array<int,array{nom:string, taille:int, date:int}> plus récente d'abord */
    public static function sauvegardesExistantes(): array
    {
        $fichiers = glob(self::dossierSauvegardes() . '/sauvegarde-*.zip') ?: [];
        rsort($fichiers);
        return array_map(static fn($f) => [
            'nom'   => basename($f),
            'taille' => filesize($f) ?: 0,
            'date'  => filemtime($f) ?: 0,
        ], $fichiers);
    }

    public static function cheminSauvegarde(string $nom): ?string
    {
        // basename() retire tout « .. » ou séparateur : pas de traversée
        // possible via le nom demandé au téléchargement.
        $s = basename($nom);
        if (!preg_match('/^sauvegarde-[\d-]+\.zip$/', $s)) {
            return null;
        }
        $chemin = self::dossierSauvegardes() . '/' . $s;
        return is_file($chemin) ? $chemin : null;
    }

    public static function versionCourante(): ?string
    {
        $f = self::racine() . '/VERSION';
        return is_file($f) ? trim((string) file_get_contents($f)) : null;
    }

    // --- Garde-fous sur les chemins -----------------------------------

    /**
     * Un chemin relatif est sûr s'il ne contient aucun segment « . » ou
     * « .. », n'est pas absolu, et ne contient aucun caractère de contrôle.
     * C'est la première barrière contre le zip slip, syntaxique : elle
     * s'applique avant toute résolution sur le disque.
     */
    private static function cheminSur(string $relatif): bool
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

    /** Résolution réelle sur le disque, pour la seconde barrière (le dossier parent peut ne pas encore exister). */
    private static function resoudre(string $chemin): ?string
    {
        $parent = dirname($chemin);
        $reel = is_dir($parent) ? realpath($parent) : null;
        if ($reel === false || $reel === null) {
            // Le dossier n'existe pas encore (premier niveau d'un nouveau
            // sous-dossier) : on résout le premier ancêtre qui existe.
            $courant = $parent;
            while (!is_dir($courant) && $courant !== dirname($courant)) {
                $courant = dirname($courant);
            }
            $reel = realpath($courant);
            if ($reel === false) {
                return null;
            }
        }
        return $reel . '/' . basename($chemin);
    }

    /** Rend lisibles par le serveur web les dossiers entre la racine publique et ce dossier. */
    private static function ouvrirAuWeb(string $dossier, string $docroot): void
    {
        $docroot = rtrim($docroot, '/');
        while (str_starts_with($dossier, $docroot . '/')) {
            $droits = @fileperms($dossier);
            if ($droits !== false && ($droits & 0005) !== 0005) {
                @chmod($dossier, 0755);
            }
            $dossier = dirname($dossier);
        }
    }

    private static function protege(string $relatif): bool
    {
        return $relatif === 'config/config.php' || str_starts_with($relatif, 'storage/');
    }

    /**
     * Détecte un éventuel dossier englobant unique (ex. « altocompta/ »),
     * présent sur chaque entrée de l'archive. S'il n'y en a pas un seul
     * commun à tout, on ne retire rien plutôt que de deviner.
     */
    private static function detecterPrefixe(\ZipArchive $zip): ?string
    {
        $premier = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = $zip->getNameIndex($i);
            if ($nom === false) {
                continue;
            }
            $segment = explode('/', $nom)[0];
            if ($premier === null) {
                $premier = $segment;
            } elseif ($segment !== $premier) {
                return null;
            }
        }
        return $premier;
    }

    private static function sansPrefixe(string $nom, ?string $prefixe): ?string
    {
        if ($prefixe !== null && str_starts_with($nom, $prefixe . '/')) {
            return substr($nom, strlen($prefixe) + 1);
        }
        return $prefixe === null ? $nom : null;
    }

    /** @return array{ok:bool, erreur:string, chemin:null, nb_fichiers:int, nb_public:int, nb_autres:int, version:null} */
    private static function echec(string $motif): array
    {
        return ['ok' => false, 'erreur' => $motif, 'chemin' => null,
            'nb_fichiers' => 0, 'nb_public' => 0, 'nb_autres' => 0, 'version' => null];
    }

    private static function echecApplication(string $motif): array
    {
        return ['ok' => false, 'erreur' => $motif, 'nb_fichiers' => 0, 'sauvegarde' => null, 'migrations' => []];
    }
}
