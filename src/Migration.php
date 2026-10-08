<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Application des migrations de schéma.
 *
 * Logique unique, partagée entre `php db/migrate.php` (CLI) et le module de
 * mise à jour intégré (déclenché depuis l'interface) : les deux chemins
 * doivent se comporter identiquement, sans quoi l'un pourrait laisser un
 * schéma que l'autre ne reproduit pas. C'est exactement le type d'écart
 * trouvé et corrigé entre schema.sql et une migration au tour précédent —
 * d'où le choix de n'avoir plus qu'un seul endroit où cette logique existe.
 */
final class Migration
{
    private static function dossier(): string
    {
        return dirname(__DIR__) . '/db/migrations';
    }

    public static function estInstallee(): bool
    {
        return (bool) Database::valeur(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'users'"
        );
    }

    /** @return array<int,string> versions déjà appliquées */
    public static function appliquees(): array
    {
        return array_column(Database::tous('SELECT version FROM migrations'), 'version');
    }

    /** @return array<string,string> version => chemin du fichier, en attente */
    public static function enAttente(): array
    {
        $appliquees = self::appliquees();
        $fichiers = glob(self::dossier() . '/*.sql') ?: [];
        sort($fichiers);

        $attente = [];
        foreach ($fichiers as $f) {
            $version = basename($f, '.sql');
            if (!in_array($version, $appliquees, true)) {
                $attente[$version] = $f;
            }
        }
        return $attente;
    }

    /**
     * Applique le schéma initial si la base est vide, puis les migrations
     * en attente, dans l'ordre. S'arrête à la première erreur : les
     * migrations précédentes restent appliquées.
     *
     * @return array{initiale:bool, appliquees:array<int,string>, erreur:?string}
     */
    public static function executer(): array
    {
        // Des fichiers viennent peut-être d'être remplacés : sans cela, la suite
        // de la requête et les suivantes pourraient exécuter d'anciennes versions
        // gardées en cache. Le module de mise à jour installé avant ce correctif
        // n'y pense pas, mais il appelle bien cette méthode une fois les fichiers
        // écrits.
        Opcache::vider();

        $pdo = Database::pdo();
        $resultat = ['initiale' => false, 'appliquees' => [], 'erreur' => null];

        if (!self::estInstallee()) {
            $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/db/schema.sql'));
            Database::inserer('migrations', ['version' => '001_initial', 'applique_le' => date('Y-m-d H:i:s')]);
            $resultat['initiale'] = true;
            $resultat['appliquees'][] = '001_initial';

            // schema.sql intègre déjà toute migration livrée à ce jour : on les
            // marque appliquées pour ne pas les rejouer sur une base neuve.
            foreach (glob(self::dossier() . '/*.sql') ?: [] as $f) {
                $version = basename($f, '.sql');
                Database::inserer('migrations', ['version' => $version, 'applique_le' => date('Y-m-d H:i:s')]);
                $resultat['appliquees'][] = $version . ' (intégrée au schéma)';
            }
            self::reprises($resultat);
            return $resultat;
        }

        foreach (self::enAttente() as $version => $fichier) {
            try {
                $pdo->exec((string) file_get_contents($fichier));
                Database::inserer('migrations', ['version' => $version, 'applique_le' => date('Y-m-d H:i:s')]);
                $resultat['appliquees'][] = $version;
            } catch (\Throwable $e) {
                $resultat['erreur'] = "Échec sur {$version} : " . $e->getMessage();
                return $resultat;
            }
        }

        self::reprises($resultat);
        return $resultat;
    }

    /**
     * Passe les reprises de données une fois le schéma à jour. Un échec est
     * remonté sans défaire les migrations : le marqueur n'étant posé qu'en
     * cas de réussite, la reprise sera retentée au prochain passage.
     */
    private static function reprises(array &$resultat): void
    {
        try {
            foreach (Reprise::executer() as $description) {
                $resultat['appliquees'][] = 'reprise : ' . $description;
            }
        } catch (\Throwable $e) {
            $resultat['erreur'] = 'Reprise des données en échec : ' . $e->getMessage();
        }
    }

    /** @return array<int,string> reprises pas encore passées, quand la base est installée */
    public static function repriseEnAttente(): array
    {
        if (!self::estInstallee()) {
            return [];
        }
        return in_array(Reprise::CONTREPASSATIONS, Reprise::faites(), true) ? [] : [Reprise::CONTREPASSATIONS];
    }
}
