<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Socle d'extensions.
 *
 * Un plugin est un dossier sous plugins/, contenant un plugin.php qui
 * retourne son manifeste. Le cœur ne connaît aucun plugin par son nom :
 * il les découvre, charge ceux qui sont actifs, et leur offre des points
 * d'extension — routes, navigation, migrations, lignes de déclaration.
 *
 * Les plugins ont leur propre licence : le cœur est sous GNU AGPL v3, une extension
 * peut être propriétaire (tous droits réservés).
 * L'exception de licence qui permet à toute extension d'avoir la licence de son auteur
 * figure dans LICENSE-EXCEPTION.
 */
final class Plugin
{
    private static ?array $manifestes = null;
    private static array $charges = [];

    public static function racine(): string
    {
        return dirname(__DIR__) . '/plugins';
    }

    /**
     * Manifestes de tous les plugins présents sur le disque, actifs ou non.
     *
     * @return array<string,array>
     */
    /**
     * Extensions remplacées par d'autres : ancien identifiant => nouveaux identifiants.
     *
     * « pro » regroupait le connecteur Altoviz et les immobilisations ; elle est scindée
     * pour qu'un BNC n'ait pas à installer un connecteur dont il ne se sert pas afin
     * d'amortir ses biens.
     */
    public const REMPLACEES = ['pro' => ['immobilisations', 'altoviz']];

    /** Les remplaçantes sont-elles bien présentes sur le disque ? Sinon l'ancienne continue de servir. */
    private static function remplacementDisponible(string $ancien): bool
    {
        foreach (self::REMPLACEES[$ancien] ?? [] as $nouveau) {
            if (!is_file(self::racine() . '/' . $nouveau . '/plugin.php')) {
                return false;
            }
        }
        return isset(self::REMPLACEES[$ancien]);
    }

    /**
     * Reporte l'état d'une extension remplacée (active ou non, licence, date d'installation)
     * sur ses remplaçantes, puis la retire.
     *
     * Les tables ne bougent pas : elles gardent leur nom, et les migrations des remplaçantes
     * sont idempotentes. Au premier chargement, chacune se met à niveau toute seule
     * (version absente en base), sans dépendre du module de mise à jour.
     *
     * Appelée à chaque chargement, donc aussi après une mise à jour par FTP ; sans effet dès
     * que l'ancienne ligne a disparu. Si les fichiers des remplaçantes manquent, l'ancienne
     * extension reste en service plutôt que de laisser l'application sans rien.
     *
     * @param array<string,array> $etats
     * @return array<string,array> états après transition
     */
    private static function passerAuxRemplacantes(array $etats): array
    {
        $fait = false;
        foreach (self::REMPLACEES as $ancien => $nouveaux) {
            if (!isset($etats[$ancien]) || !self::remplacementDisponible($ancien)) {
                continue;
            }
            $a = $etats[$ancien];
            foreach ($nouveaux as $nouveau) {
                // IGNORE : deux requêtes simultanées font la même chose sans se gêner.
                Database::executer(
                    'INSERT IGNORE INTO plugins (identifiant, actif, version, licence_cle, licence_etat, licence_vue, installe_le)
                     VALUES (:i, :a, NULL, :lc, :le, :lv, :in)',
                    ['i' => $nouveau, 'a' => (int) $a['actif'], 'lc' => $a['licence_cle'], 'le' => $a['licence_etat'],
                     'lv' => $a['licence_vue'], 'in' => $a['installe_le']]
                );
            }
            Database::executer('DELETE FROM plugins WHERE identifiant = :i', ['i' => $ancien]);
            self::supprimerDossierObsolete($ancien);
            $fait = true;
        }
        return $fait ? self::etats() : $etats;
    }

    /** Supprime le dossier d'une extension remplacée. Strictement limité aux ids de REMPLACEES. */
    private static function supprimerDossierObsolete(string $ancien): void
    {
        if (!isset(self::REMPLACEES[$ancien])) {
            return;
        }
        $racine = realpath(self::racine());
        $reel   = realpath(self::racine() . '/' . $ancien);
        if ($racine === false || $reel === false || dirname($reel) !== $racine) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($reel, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($reel);
        self::$manifestes = null;
    }

    public static function decouvrir(): array
    {
        if (self::$manifestes !== null) {
            return self::$manifestes;
        }
        self::$manifestes = [];

        foreach (glob(self::racine() . '/*/plugin.php') ?: [] as $fichier) {
            $identifiant = basename(dirname($fichier));
            // Une extension remplacée ne se charge ni ne s'affiche plus, tant que ses remplaçantes sont là.
            if (isset(self::REMPLACEES[$identifiant]) && self::remplacementDisponible($identifiant)) {
                continue;
            }
            try {
                $m = require $fichier;
            } catch (\Throwable $e) {
                // Un plugin mal formé ne doit pas empêcher l'application de démarrer.
                continue;
            }
            if (!is_array($m) || empty($m['nom'])) {
                continue;
            }
            $m['identifiant'] = $identifiant;
            $m['chemin']      = dirname($fichier);
            self::$manifestes[$identifiant] = $m;
        }
        ksort(self::$manifestes);
        return self::$manifestes;
    }

    public static function manifeste(string $identifiant): ?array
    {
        return self::decouvrir()[$identifiant] ?? null;
    }

    /** État en base : actif, version installée, licence. */
    public static function etats(): array
    {
        $r = [];
        foreach (Database::tous('SELECT * FROM plugins') as $l) {
            $r[$l['identifiant']] = $l;
        }
        return $r;
    }

    /** Cette extension, active, apporte-t-elle sa TVA à la page TVA ? Une version trop ancienne ne le fait pas. */
    public static function contribueTva(string $identifiant): bool
    {
        $m = self::manifeste($identifiant);
        return $m !== null && isset($m['tva']) && is_callable($m['tva']);
    }

    public static function estActif(string $identifiant): bool
    {
        static $cache = null;
        $cache ??= self::etats();
        return (int) ($cache[$identifiant]['actif'] ?? 0) === 1
            && self::manifeste($identifiant) !== null;
    }

    /**
     * Charge les plugins actifs : autoloaders puis initialisation.
     * Appelé une fois, au démarrage, après la connexion à la base.
     */
    public static function charger(): void
    {
        if (self::$charges) {
            return;
        }
        $etats = self::passerAuxRemplacantes(self::etats());

        foreach (self::decouvrir() as $id => $m) {
            if ((int) ($etats[$id]['actif'] ?? 0) !== 1) {
                continue;
            }

            foreach ($m['autoload'] ?? [] as $prefixe => $dossier) {
                spl_autoload_register(static function (string $classe) use ($prefixe, $dossier): void {
                    if (!str_starts_with($classe, $prefixe)) {
                        return;
                    }
                    $chemin = rtrim($dossier, '/') . '/'
                            . str_replace('\\', '/', substr($classe, strlen($prefixe))) . '.php';
                    if (is_file($chemin)) {
                        require $chemin;
                    }
                });
            }

            if (!empty($m['templates'])) {
                View::ajouterRacine((string) $m['templates']);
            }

            if (isset($m['initialiser']) && is_callable($m['initialiser'])) {
                ($m['initialiser'])();
            }
            self::$charges[$id] = $m;

            self::mettreANiveau($id, $m, (string) ($etats[$id]['version'] ?? ''));
        }
    }

    /** @var array<string,string> identifiant => message, pour les migrations qui ont échoué */
    private static array $erreursMigration = [];

    /** @return array<string,string> */
    public static function erreursMigration(): array
    {
        return self::$erreursMigration;
    }

    /**
     * Met une extension active à niveau quand sa version a changé.
     *
     * Remplacer le code d'une extension ne suffit pas : ses migrations
     * doivent suivre. Les confier au seul module de mise à jour est fragile,
     * puisque c'est la version *précédente* du module qui applique la
     * livraison suivante — il ne connaît pas encore ce que la nouvelle
     * version lui demanderait de faire. Un dépôt par FTP, ou une extension
     * ajoutée à la main, y échapperait de la même façon.
     *
     * La comparaison porte sur la version déclarée au manifeste et celle
     * enregistrée en base : aucune requête supplémentaire tant qu'elles
     * coïncident, c'est-à-dire presque toujours.
     */
    private static function mettreANiveau(string $id, array $m, string $versionEnBase): void
    {
        $versionCode = (string) ($m['version'] ?? '');
        if ($versionCode === '' || $versionCode === $versionEnBase) {
            return;
        }

        try {
            // Verrou : deux requêtes simultanées ne doivent pas rejouer la
            // même migration en parallèle.
            $verrou = (int) Database::valeur("SELECT GET_LOCK('altocompta_maj_extension', 10)", [], 0);
            if ($verrou !== 1) {
                return;
            }

            try {
                $erreur = self::appliquerMigrations($id, $m);
                if ($erreur === '') {
                    Database::modifier('plugins', ['version' => $versionCode],
                        'identifiant = :i', ['i' => $id]);
                } else {
                    self::$erreursMigration[$id] = $erreur;
                }
            } finally {
                Database::executer("DO RELEASE_LOCK('altocompta_maj_extension')");
            }
        } catch (\Throwable $e) {
            self::$erreursMigration[$id] = $e->getMessage();
        }
    }

    /** @return array<string,array> plugins actifs et chargés */
    public static function actifs(): array
    {
        return self::$charges;
    }

    public static function enregistrerRoutes(Router $r): void
    {
        foreach (self::$charges as $m) {
            if (isset($m['routes']) && is_callable($m['routes'])) {
                ($m['routes'])($r);
            }
        }
    }

    /** Entrées de navigation apportées par les plugins. */
    public static function navigation(): array
    {
        $entrees = [];
        foreach (self::$charges as $m) {
            if (isset($m['navigation']) && is_callable($m['navigation'])) {
                foreach (($m['navigation'])() as $e) {
                    $entrees[] = $e;
                }
            }
        }
        return $entrees;
    }

    // --- Connecteurs ---------------------------------------------------

    /**
     * Connecteurs bancaires des extensions actives.
     *
     * Une extension qui synchronise des opérations depuis un service tiers le
     * déclare dans son manifeste (clé « connecteur » : nom affiché, source
     * enregistrée sur les opérations, route de sa page). Le cœur n'a ainsi pas
     * à connaître les services : ses messages parlent de ceux qui sont
     * réellement installés, et un nouveau connecteur n'y demande aucune
     * modification.
     *
     * Peut aussi fournir deux points d'entrée, appelés par les scripts du cœur :
     *  - « synchroniser » : fn(bool $force, ?int $connexionId, callable $dire): array
     *    appelée par bin/synchroniser.php (tâche planifiée) ;
     *  - « webhook » : fn(int $connexionId, string $secret): ?array{code:int, texte:string}
     *    appelée par public/webhook.php, null si la connexion est inconnue.
     *
     * @return array<int,array{id:string, nom:string, source:string, route:string,
     *         synchroniser:?callable, webhook:?callable}> triés par nom
     */
    public static function connecteurs(): array
    {
        $liste = [];
        foreach (self::$charges as $id => $m) {
            $c = $m['connecteur'] ?? null;
            if (is_array($c) && !empty($c['nom'])) {
                $liste[] = [
                    'id'     => (string) $id,
                    'nom'    => (string) $c['nom'],
                    'source' => (string) ($c['source'] ?? $id),
                    'route'  => (string) ($c['route'] ?? '/'),
                    'synchroniser' => isset($c['synchroniser']) && is_callable($c['synchroniser']) ? $c['synchroniser'] : null,
                    'webhook'      => isset($c['webhook']) && is_callable($c['webhook']) ? $c['webhook'] : null,
                ];
            }
        }
        usort($liste, static fn($a, $b) => strcasecmp($a['nom'], $b['nom']));
        return $liste;
    }

    /** « A », « A ou B », « A, B ou C » : énumération à la française. */
    public static function enumeration(array $mots, string $conjonction = 'ou'): string
    {
        $mots = array_values(array_filter(array_map('strval', $mots), static fn($m) => $m !== ''));
        $n = count($mots);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $mots[0];
        }
        return implode(', ', array_slice($mots, 0, -1)) . ' ' . $conjonction . ' ' . $mots[$n - 1];
    }

    /**
     * Par quoi les opérations arrivent, selon ce qui est installé : « la
     * synchronisation Altoviz ou Evoliz » avec des connecteurs, « l'import d'un
     * relevé » sans.
     */
    public static function phraseOrigine(): string
    {
        $noms = array_column(self::connecteurs(), 'nom');
        return $noms ? 'la synchronisation ' . self::enumeration($noms) : 'l\'import d\'un relevé';
    }

    /**
     * Libellé d'une source d'opération. Le nom d'un connecteur désactivé depuis
     * reste connu : les opérations qu'il a apportées demeurent en base.
     */
    public static function libelleSource(string $source): string
    {
        return match ($source) {
            'csv', 'ofx' => 'import ' . $source,
            'manuel'     => 'saisie manuelle',
            default      => 'via ' . (self::nomConnecteurDeSource($source) ?? ucfirst($source)),
        };
    }

    private static function nomConnecteurDeSource(string $source): ?string
    {
        foreach (self::connecteurs() as $c) {
            if ($c['source'] === $source) {
                return $c['nom'];
            }
        }
        return null;
    }

    /**
     * Contributions des plugins au calcul de la déclaration.
     *
     * Chaque plugin peut ajouter des postes de dépense, des annexes et des
     * contrôles. Le cœur n'a ainsi pas à connaître les amortissements pour
     * que la case CH existe quand l'extension est là.
     *
     * @return array{depenses:array, annexes:array, controles:array, donnees:array}
     */
    public static function contribuerDeclaration(int $userId, int $exerciceId, int $annee): array
    {
        $sortie = ['depenses' => [], 'annexes' => [], 'controles' => [], 'donnees' => []];

        foreach (self::$charges as $id => $m) {
            if (!isset($m['declaration']) || !is_callable($m['declaration'])) {
                continue;
            }
            $c = ($m['declaration'])($userId, $exerciceId, $annee);
            if (!is_array($c)) {
                continue;
            }
            foreach (['depenses', 'annexes', 'controles'] as $cle) {
                foreach ($c[$cle] ?? [] as $k => $v) {
                    $sortie[$cle][is_int($k) ? count($sortie[$cle]) : $k] = $v;
                }
            }
            $sortie['donnees'][$id] = $c['donnees'] ?? [];
        }
        return $sortie;
    }

    /**
     * Ce que les extensions apportent à la page TVA : la TVA des acquisitions qu'elles gèrent et qui ne passent pas par
     * les dépenses (un matériel immobilisé, par exemple). Chaque ligne dit son état — comptée ou non, et pourquoi —
     * pour que la page n'additionne jamais en silence ce qu'elle ne sait pas justifier.
     *
     * @return array{acquisitions:list<array{date:string, libelle:string, tva:float, etat:string, detail:string, extension:string}>}
     */
    public static function contribuerTva(int $userId, int $exerciceId, array $exercice): array
    {
        $sortie = ['acquisitions' => []];
        foreach (self::$charges as $id => $m) {
            if (!isset($m['tva']) || !is_callable($m['tva'])) {
                continue;
            }
            $c = ($m['tva'])($userId, $exerciceId, $exercice);
            foreach (is_array($c) ? ($c['acquisitions'] ?? []) : [] as $ligne) {
                $sortie['acquisitions'][] = $ligne + ['extension' => (string) $id];
            }
        }
        return $sortie;
    }

    // --- Activation ---------------------------------------------------

    public static function activer(string $identifiant): string
    {
        $m = self::manifeste($identifiant);
        if ($m === null) {
            return 'Extension introuvable.';
        }

        $erreur = self::appliquerMigrations($identifiant, $m);
        if ($erreur !== '') {
            return $erreur;
        }

        Database::executer(
            'INSERT INTO plugins (identifiant, actif, version, installe_le)
             VALUES (:i, 1, :v, NOW())
             ON DUPLICATE KEY UPDATE actif = 1, version = VALUES(version)',
            ['i' => $identifiant, 'v' => (string) ($m['version'] ?? '')]
        );
        return '';
    }

    /**
     * Désactive sans rien effacer : les données du plugin restent en base,
     * pour qu'une réactivation les retrouve intactes.
     */
    public static function desactiver(string $identifiant): string
    {
        Database::modifier('plugins', ['actif' => 0], 'identifiant = :i', ['i' => $identifiant]);
        return '';
    }

    /**
     * Applique les migrations en attente d'un plugin déjà actif.
     *
     * L'activation les applique déjà ; mais un plugin qui gagne une nouvelle
     * migration entre deux versions doit pouvoir la recevoir sans qu'on ait
     * à le désactiver puis le réactiver — manœuvre qu'un utilisateur
     * hésiterait légitimement à faire sur des données comptables.
     */
    public static function mettreAJourMigrations(string $identifiant): string
    {
        $m = self::manifeste($identifiant);
        if ($m === null) {
            return 'Extension introuvable.';
        }
        return self::appliquerMigrations($identifiant, $m);
    }

    /** @return array<int,string> versions en attente pour ce plugin */
    public static function migrationsEnAttente(string $identifiant): array
    {
        $m = self::manifeste($identifiant);
        $dossier = $m['migrations'] ?? null;
        if (!$dossier || !is_dir($dossier)) {
            return [];
        }
        $appliquees = array_column(
            Database::tous('SELECT version FROM migrations WHERE version LIKE :p',
                ['p' => 'plugin:' . $identifiant . ':%']),
            'version'
        );
        $attente = [];
        foreach (glob(rtrim($dossier, '/') . '/*.sql') ?: [] as $f) {
            $version = 'plugin:' . $identifiant . ':' . basename($f, '.sql');
            if (!in_array($version, $appliquees, true)) {
                $attente[] = basename($f, '.sql');
            }
        }
        sort($attente);
        return $attente;
    }

    /** Migrations propres au plugin, tracées avec un préfixe pour ne pas se mêler à celles du cœur. */
    private static function appliquerMigrations(string $identifiant, array $m): string
    {
        $dossier = $m['migrations'] ?? null;
        if (!$dossier || !is_dir($dossier)) {
            return '';
        }

        $appliquees = array_column(
            Database::tous('SELECT version FROM migrations WHERE version LIKE :p',
                ['p' => 'plugin:' . $identifiant . ':%']),
            'version'
        );

        $fichiers = glob(rtrim($dossier, '/') . '/*.sql') ?: [];
        sort($fichiers);

        foreach ($fichiers as $f) {
            $version = 'plugin:' . $identifiant . ':' . basename($f, '.sql');
            if (in_array($version, $appliquees, true)) {
                continue;
            }
            try {
                Database::pdo()->exec((string) file_get_contents($f));
                Database::inserer('migrations', ['version' => $version, 'applique_le' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $e) {
                return 'Migration ' . basename($f) . ' en échec : ' . $e->getMessage();
            }
        }
        return '';
    }

    // --- Licence ------------------------------------------------------

    public static function licence(string $identifiant): array
    {
        $l = Database::un('SELECT licence_cle, licence_etat, licence_vue FROM plugins WHERE identifiant = :i',
            ['i' => $identifiant]);
        return [
            'cle'  => $l['licence_cle'] ?? null,
            'etat' => $l['licence_etat'] ?? 'absente',
            'vue'  => $l['licence_vue'] ?? null,
        ];
    }

    public static function enregistrerLicence(string $identifiant, string $cle, string $etat): void
    {
        Database::modifier('plugins', [
            'licence_cle'  => $cle ?: null,
            'licence_etat' => $etat,
            'licence_vue'  => date('Y-m-d H:i:s'),
        ], 'identifiant = :i', ['i' => $identifiant]);
    }
}
