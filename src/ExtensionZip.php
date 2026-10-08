<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Importe une extension depuis un zip, en deux temps : analyser() puis installer().
 *
 * Une extension est du code PHP qui s'exécutera avec les droits de l'application : on ne peut pas en
 * « vérifier l'innocuité » par une analyse, et ce module ne le prétend pas. Ce qu'il garantit :
 *
 *  - RIEN n'est exécuté avant la confirmation. Les métadonnées viennent d'un manifeste JSON statique
 *    (manifeste.json), lu sans lancer aucun code de l'archive ;
 *  - le zip ne peut écrire que sous plugins/<id>/ : chemins relatifs sûrs, aucun lien symbolique, aucun
 *    fichier caché, types de fichiers limités (code, SQL, texte, images) ;
 *  - les tailles sont limitées sur les octets réellement lus, non sur ceux que l'archive déclare : une
 *    « bombe » dont l'en-tête ment est coupée ;
 *  - l'extension existante est sauvegardée avant d'être remplacée, et rétablie si le remplacement échoue ;
 *  - l'empreinte SHA-256 du zip est comparée à celle qu'un catalogue publie, quand il en publie une.
 *
 * Format du zip : un seul dossier racine, nommé comme l'extension, contenant manifeste.json et plugin.php.
 */
final class ExtensionZip
{
    public const TAILLE_ZIP_MAX = 20971520;     // 20 Mo
    public const FICHIERS_MAX   = 600;
    public const OCTETS_MAX     = 41943040;     // 40 Mo décompressés
    public const FICHIER_MAX    = 10485760;     // 10 Mo par fichier
    private const MANIFESTE_MAX = 20480;

    private const EXTENSIONS = ['php', 'sql', 'md', 'txt', 'json', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
    private const SANS_EXTENSION = ['LICENSE', 'LICENCE', 'NOTICE', 'LISEZMOI', 'README'];

    /**
     * Inspecte une archive sans rien écrire : ni sur le disque applicatif, ni de copie temporaire.
     *
     * @param array{installees:array<string,string>, core:string, reserves:list<string>, catalogues:list<array>} $ctx
     * @return array{ok:bool, erreur:?string, id:?string, manifeste:?array, fichiers:int, octets:int,
     *               sha256:?string, action:?string, installee:?string, catalogue:?array}
     */
    public static function apercu(string $chemin, string $nomOriginal, array $ctx): array
    {
        $echec = static fn(string $m) => ['ok' => false, 'erreur' => $m, 'id' => null, 'manifeste' => null, 'fichiers' => 0,
            'octets' => 0, 'sha256' => null, 'action' => null, 'installee' => null, 'catalogue' => null];

        if (!class_exists(\ZipArchive::class)) {
            return $echec('L\'extension PHP zip n\'est pas disponible sur ce serveur.');
        }
        if (!preg_match('/\.zip$/i', $nomOriginal)) {
            return $echec('Le fichier envoyé n\'est pas un .zip.');
        }
        $taille = @filesize($chemin);
        if ($taille === false || $taille <= 0) {
            return $echec('Fichier vide ou illisible.');
        }
        if ($taille > self::TAILLE_ZIP_MAX) {
            return $echec('Archive trop volumineuse (' . round(self::TAILLE_ZIP_MAX / 1048576) . ' Mo au plus).');
        }

        $zip = new \ZipArchive();
        if ($zip->open($chemin, \ZipArchive::CHECKCONS) !== true) {
            return $echec('Archive illisible ou corrompue.');
        }
        $r = self::inspecter($zip, $ctx);
        $zip->close();
        if (!$r['ok']) {
            return $echec((string) $r['erreur']);
        }

        $sha = (string) hash_file('sha256', $chemin);
        return ['ok' => true, 'erreur' => null, 'id' => $r['id'], 'manifeste' => $r['manifeste'], 'fichiers' => $r['fichiers'],
            'octets' => $r['octets'], 'sha256' => $sha, 'action' => $r['action'], 'installee' => $r['installee'],
            'catalogue' => self::confronter($ctx['catalogues'] ?? [], (string) $r['id'], (string) $r['manifeste']['version'], $sha)];
    }

    /**
     * Analyse, puis conserve l'archive dans un emplacement stable : le fichier temporaire d'un envoi
     * disparaît à la fin de la requête, et la confirmation est une requête distincte.
     *
     * @return array{ok:bool, erreur:?string, id:?string, manifeste:?array, fichiers:int, octets:int,
     *               sha256:?string, action:?string, installee:?string, catalogue:?array, chemin:?string}
     */
    public static function analyser(string $chemin, string $nomOriginal, array $ctx): array
    {
        $a = self::apercu($chemin, $nomOriginal, $ctx);
        $a['chemin'] = null;
        if (!$a['ok']) {
            return $a;
        }
        $stable = sys_get_temp_dir() . '/altocompta-ext-' . bin2hex(random_bytes(8)) . '.zip';
        if (!copy($chemin, $stable)) {
            return ['ok' => false, 'erreur' => 'Impossible de conserver l\'archive pour confirmation.'] + $a;
        }
        $a['chemin'] = $stable;
        return $a;
    }

    /**
     * Toutes les vérifications sur une archive ouverte. Appelée à l'analyse ET à l'installation : on ne se
     * fie à rien d'affirmé entre les deux requêtes.
     *
     * @return array{ok:bool, erreur:?string, id:?string, manifeste:?array, fichiers:int, octets:int, action:?string,
     *               installee:?string, entrees:list<array{index:int, relatif:string}>}
     */
    private static function inspecter(\ZipArchive $zip, array $ctx): array
    {
        $echec = static fn(string $m) => ['ok' => false, 'erreur' => $m, 'id' => null, 'manifeste' => null, 'fichiers' => 0,
            'octets' => 0, 'action' => null, 'installee' => null, 'entrees' => []];

        if ($zip->numFiles === 0) {
            return $echec('Archive vide.');
        }
        if ($zip->numFiles > self::FICHIERS_MAX) {
            return $echec('Trop de fichiers (' . self::FICHIERS_MAX . ' au plus).');
        }

        $racine = null;
        $entrees = [];
        $octets = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $nom = $stat['name'] ?? false;
            if ($nom === false) {
                return $echec('Entrée illisible dans l\'archive.');
            }
            $premier = explode('/', $nom)[0];
            if ($racine === null) {
                $racine = $premier;
            } elseif ($premier !== $racine) {
                return $echec('L\'archive doit contenir un seul dossier racine, nommé comme l\'extension.');
            }
            if (str_ends_with($nom, '/')) {
                continue;   // un dossier
            }
            if (!self::cheminSur($nom)) {
                return $echec('Chemin suspect dans l\'archive : ' . self::lisible($nom));
            }
            if (self::estLienSymbolique($zip, $i)) {
                return $echec('Lien symbolique refusé dans l\'archive : ' . self::lisible($nom));
            }
            $relatif = substr($nom, strlen((string) $racine) + 1);
            foreach (explode('/', $relatif) as $segment) {
                if (str_starts_with($segment, '.')) {
                    return $echec('Fichier caché refusé dans l\'archive : ' . self::lisible($nom));
                }
            }
            $base = basename($relatif);
            $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if ($ext === '' ? !in_array($base, self::SANS_EXTENSION, true) : !in_array($ext, self::EXTENSIONS, true)) {
                return $echec('Type de fichier refusé : ' . self::lisible($nom));
            }
            $declare = (int) ($stat['size'] ?? 0);
            if ($declare > self::FICHIER_MAX) {
                return $echec('Fichier trop volumineux : ' . self::lisible($nom));
            }
            $octets += $declare;
            if ($octets > self::OCTETS_MAX) {
                return $echec('Archive trop volumineuse une fois décompressée.');
            }
            $entrees[] = ['index' => $i, 'relatif' => $relatif];
        }

        $id = (string) $racine;
        if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/', $id)) {
            return $echec('Le dossier racine « ' . self::lisible($id) . ' » n\'est pas un identifiant d\'extension valide.');
        }
        if (in_array($id, $ctx['reserves'] ?? [], true)) {
            return $echec('L\'identifiant « ' . $id . ' » est réservé : il désigne une ancienne extension remplacée.');
        }

        $relatifs = array_column($entrees, 'relatif');
        foreach (['manifeste.json', 'plugin.php'] as $requis) {
            if (!in_array($requis, $relatifs, true)) {
                return $echec('Fichier obligatoire absent : ' . $id . '/' . $requis . '.');
            }
        }

        // Le manifeste est lu comme DONNÉES : aucun code de l'archive n'est exécuté.
        $iM = $entrees[array_search('manifeste.json', $relatifs, true)]['index'];
        $brut = $zip->getFromIndex($iM, self::MANIFESTE_MAX + 1);
        if ($brut === false || strlen($brut) > self::MANIFESTE_MAX) {
            return $echec('manifeste.json illisible ou trop gros.');
        }
        [$m, $err] = self::manifeste($brut, $id);
        if ($m === null) {
            return $echec($err);
        }

        // La version existe à deux endroits : le manifeste statique (lu ici) et plugin.php (lu par l'application
        // une fois l'extension installée). Qu'ils divergent, et l'aperçu annoncerait une chose et l'installation
        // une autre. plugin.php n'est pas exécuté : on y cherche la ligne de version, comme du texte.
        $iP = $entrees[array_search('plugin.php', $relatifs, true)]['index'];
        $php = (string) $zip->getFromIndex($iP, 1048577);
        if (strlen($php) <= 1048576 && preg_match('/[\'"]version[\'"]\s*=>\s*[\'"](\d+(?:\.\d+){0,3})[\'"]/', $php, $mv)
            && Boutique::comparerVersions($mv[1], $m['version']) !== 0) {
            return $echec('Archive incohérente : plugin.php annonce la version ' . $mv[1] . ', manifeste.json la version ' . $m['version'] . '.');
        }

        $core = (string) ($ctx['core'] ?? '');
        if (!empty($m['core_min']) && $core !== '' && Boutique::comparerVersions($core, $m['core_min']) < 0) {
            return $echec('Cette extension demande AltoCompta ' . $m['core_min'] . ' ou plus récent ; celui-ci est en ' . $core . '.');
        }

        $installee = $ctx['installees'][$id] ?? null;
        if ($installee === null) {
            $action = 'installation';
        } else {
            $cmp = Boutique::comparerVersions($m['version'], (string) $installee);
            $action = $cmp > 0 ? 'mise_a_jour' : ($cmp === 0 ? 'identique' : 'retrogradation');
        }
        if ($action === 'retrogradation') {
            return $echec('Une version plus récente (' . $installee . ') est déjà installée : retour à ' . $m['version'] . ' refusé.');
        }

        return ['ok' => true, 'erreur' => null, 'id' => $id, 'manifeste' => $m, 'fichiers' => count($entrees), 'octets' => $octets,
                'action' => $action, 'installee' => $installee, 'entrees' => $entrees];
    }

    /** @return array{0:?array<string,string>, 1:string} manifeste normalisé, erreur */
    public static function manifeste(string $brut, string $idAttendu): array
    {
        $d = json_decode($brut, true, 6);
        if (!is_array($d) || array_is_list($d)) {
            return [null, 'manifeste.json : objet JSON attendu.'];
        }
        $texte = static function (string $cle, int $max, bool $requis) use ($d, &$erreur): ?string {
            $v = $d[$cle] ?? null;
            if ($v === null || $v === '') {
                if ($requis) {
                    $erreur = "manifeste.json : « $cle » obligatoire.";
                }
                return null;
            }
            if (!is_string($v) || mb_strlen($v) > $max || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                $erreur = "manifeste.json : « $cle » invalide.";
                return null;
            }
            return trim($v);
        };
        $erreur = '';
        $id  = $texte('id', 41, true);
        $nom = $texte('nom', 100, true);
        $ver = $texte('version', 20, true);
        $min = $texte('core_min', 20, false);
        $desc = $texte('description', 500, false);
        $aut = $texte('auteur', 100, false);
        $lic = $texte('licence', 60, false);
        if ($erreur !== '') {
            return [null, $erreur];
        }
        if ($id !== $idAttendu) {
            return [null, 'manifeste.json : l\'identifiant « ' . $id . ' » ne correspond pas au dossier « ' . $idAttendu . ' ».'];
        }
        foreach (['version' => $ver, 'core_min' => $min] as $cle => $v) {
            if ($v !== null && !preg_match('/^\d+(\.\d+){0,3}$/', $v)) {
                return [null, "manifeste.json : « $cle » doit être un numéro de version (ex. 1.2.0)."];
            }
        }
        return [['id' => $id, 'nom' => (string) $nom, 'version' => (string) $ver, 'core_min' => (string) $min,
                 'description' => (string) $desc, 'auteur' => (string) $aut, 'licence' => (string) $lic], ''];
    }

    /**
     * Compare l'empreinte du zip à celle qu'un catalogue publie pour cette version de cette extension.
     *
     * @return ?array{conforme:bool, boutique:string} null si aucun catalogue n'en publie
     */
    public static function confronter(array $catalogues, string $id, string $version, string $sha256): ?array
    {
        $resultat = null;
        foreach ($catalogues as $store) {
            foreach ($store['produits'] ?? [] as $p) {
                if (($p['extension'] ?? null) === $id && ($p['version'] ?? null) === $version && !empty($p['sha256'])) {
                    $ok = hash_equals((string) $p['sha256'], $sha256);
                    $resultat = ['conforme' => $ok, 'boutique' => (string) ($store['boutique']['nom'] ?? '?')];
                    if ($ok) {
                        return $resultat;   // une boutique qui reconnaît ce fichier suffit
                    }
                }
            }
        }
        return $resultat;
    }

    /**
     * Installe l'archive : sauvegarde de l'existant, extraction à côté, puis échange de dossiers.
     *
     * @return array{ok:bool, erreur:?string, id:?string, version:?string, action:?string, fichiers:int, sauvegarde:?string}
     */
    public static function installer(string $cheminZip, string $dossierPlugins, string $dossierSauvegardes, array $ctx): array
    {
        $echec = static fn(string $m) => ['ok' => false, 'erreur' => $m, 'id' => null, 'version' => null, 'action' => null, 'fichiers' => 0, 'sauvegarde' => null];
        if (!is_file($cheminZip)) {
            return $echec('Archive introuvable : l\'avez-vous confirmée dans les temps ?');
        }
        $zip = new \ZipArchive();
        if ($zip->open($cheminZip, \ZipArchive::CHECKCONS) !== true) {
            return $echec('Archive illisible au moment de l\'installer.');
        }
        $r = self::inspecter($zip, $ctx);
        if (!$r['ok']) {
            $zip->close();
            return $echec((string) $r['erreur']);
        }
        $id = (string) $r['id'];
        $racine = rtrim($dossierPlugins, '/');
        if (!is_dir($racine) && !@mkdir($racine, 0755, true)) {
            $zip->close();
            return $echec('Le dossier des extensions est introuvable et ne peut pas être créé.');
        }
        $final   = $racine . '/' . $id;
        $nouveau = $racine . '/.' . $id . '.nouveau-' . bin2hex(random_bytes(4));
        $ancien  = $racine . '/.' . $id . '.ancien-' . bin2hex(random_bytes(4));

        // 1. Sauvegarde de l'existant : sans point de retour, on ne remplace rien.
        $sauvegarde = null;
        if (is_dir($final)) {
            [$sauvegarde, $errSauv] = self::sauvegarderDossier($final, $id, (string) ($r['installee'] ?? '?'), $dossierSauvegardes);
            if ($errSauv !== '') {
                $zip->close();
                return $echec('Sauvegarde impossible, installation annulée par prudence : ' . $errSauv);
            }
        }

        // 2. Extraction à côté, sur les octets RÉELLEMENT lus.
        $total = 0;
        $ecrits = 0;
        set_time_limit(120);
        foreach ($r['entrees'] as $e) {
            $contenu = $zip->getFromIndex($e['index'], self::FICHIER_MAX + 1);
            if ($contenu === false || strlen($contenu) > self::FICHIER_MAX) {
                $zip->close();
                self::supprimerDossier($nouveau);
                return $echec('Fichier illisible ou trop volumineux : ' . self::lisible($e['relatif']) . '.');
            }
            $total += strlen($contenu);
            if ($total > self::OCTETS_MAX) {
                $zip->close();
                self::supprimerDossier($nouveau);
                return $echec('Archive trop volumineuse une fois décompressée.');
            }
            $cible = $nouveau . '/' . $e['relatif'];
            $dir = dirname($cible);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $zip->close();
                self::supprimerDossier($nouveau);
                return $echec('Impossible de créer ' . self::lisible($e['relatif']) . '. Vérifier les droits d\'écriture.');
            }
            if (file_put_contents($cible, $contenu) === false) {
                $zip->close();
                self::supprimerDossier($nouveau);
                return $echec('Échec d\'écriture sur ' . self::lisible($e['relatif']) . '. Vérifier les droits d\'écriture.');
            }
            $ecrits++;
        }
        $zip->close();

        // 3. Échange : l'ancien dossier est écarté, le nouveau prend sa place, et le premier revient si le second échoue.
        if (is_dir($final) && !@rename($final, $ancien)) {
            self::supprimerDossier($nouveau);
            return $echec('Impossible d\'écarter l\'ancienne version. Vérifier les droits d\'écriture sur plugins/.');
        }
        if (!@rename($nouveau, $final)) {
            if (is_dir($ancien)) {
                @rename($ancien, $final);
            }
            self::supprimerDossier($nouveau);
            return $echec('Impossible de mettre la nouvelle version en place : l\'ancienne a été rétablie.');
        }
        self::supprimerDossier($ancien);
        @unlink($cheminZip);

        if (class_exists(Opcache::class)) {
            Opcache::vider();
        }
        return ['ok' => true, 'erreur' => null, 'id' => $id, 'version' => (string) $r['manifeste']['version'],
                'action' => $r['action'], 'fichiers' => $ecrits, 'sauvegarde' => $sauvegarde];
    }

    // --- Détails ---------------------------------------------------------

    /** Chemin relatif sans « .. », sans racine, au jeu de caractères restreint. */
    private static function cheminSur(string $nom): bool
    {
        // Jeu de caractères restreint : lettres ASCII, chiffres, « . _ - / ». Cela écarte d'un coup les
        // antislash, les caractères de contrôle, les noms qui trompent l'œil (renversement de texte
        // Unicode, homoglyphes) et tout ce qui n'a pas sa place dans un nom de fichier de code.
        if ($nom === '' || str_starts_with($nom, '/') || !preg_match('#^[A-Za-z0-9._/-]+$#', $nom)) {
            return false;
        }
        foreach (explode('/', rtrim($nom, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** Un zip créé sous Unix peut contenir un lien symbolique : extrait, il pourrait pointer hors du dossier. */
    private static function estLienSymbolique(\ZipArchive $zip, int $i): bool
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
            return false;
        }
        return $opsys === \ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0xF000) === 0xA000);
    }

    /** Un nom tiré d'une archive hostile ne doit ni polluer un message ni casser l'affichage. */
    private static function lisible(string $s): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E\p{L}\p{N}\/._-]/u', '?', $s), 0, 80);
    }

    /** @return array{0:?string, 1:string} nom du zip de sauvegarde, erreur */
    private static function sauvegarderDossier(string $dossier, string $id, string $version, string $dossierSauvegardes): array
    {
        if (!is_dir($dossierSauvegardes) && !@mkdir($dossierSauvegardes, 0750, true) && !is_dir($dossierSauvegardes)) {
            return [null, 'dossier de sauvegarde introuvable.'];
        }
        $nom = 'extension-' . $id . '-v' . preg_replace('/[^0-9.]/', '', $version) . '-' . date('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($dossierSauvegardes . '/' . $nom, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return [null, 'impossible de créer ' . $nom . '.'];
        }
        $base = realpath($dossier);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $zip->addFile($f->getPathname(), $id . '/' . ltrim(substr($f->getPathname(), strlen((string) $base)), '/'));
            }
        }
        if (!$zip->close()) {
            return [null, 'écriture de la sauvegarde impossible.'];
        }
        return [$nom, ''];
    }

    private static function supprimerDossier(string $dossier): void
    {
        if (!is_dir($dossier) || is_link($dossier)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            ($f->isDir() && !$f->isLink()) ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dossier);
    }
}
