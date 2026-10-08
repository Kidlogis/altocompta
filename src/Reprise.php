<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Reprises de données : corrections de l'existant qu'un fichier SQL ne sait
 * pas exprimer proprement, parce qu'elles passent par la logique métier.
 *
 * Chaque reprise est idempotente et porte un marqueur dans la table des
 * migrations : elle ne rejoue jamais, et peut sans risque être relancée.
 */
final class Reprise
{
    public const CONTREPASSATIONS = 'reprise:001_contrepassations';

    /**
     * Inscrit l'écriture d'annulation des écritures annulées avant que
     * l'annulation ne s'inscrive au registre.
     *
     * S'appuie sur Corrections, et non sur Recette ou Depense : pendant une
     * mise à jour par l'ancien module, ces deux-là peuvent déjà être chargées en
     * mémoire dans leur version précédente, sans la méthode nécessaire.
     *
     * Jusque-là, corriger une écriture se contentait de la marquer « annulée » :
     * sur le registre, elle gardait l'allure d'une ligne ordinaire, et la
     * somme des lignes imprimées ne retombait plus sur le total déclaré. Cette
     * reprise ajoute, pour chacune, la ligne d'annulation qui manquait. Rien
     * n'est modifié ni supprimé : on ne fait qu'ajouter, et chaque ajout est
     * consigné au journal.
     *
     * @return int nombre d'écritures d'annulation inscrites
     */
    public static function contrepassations(): int
    {
        $n = 0;

        $recettes = Database::tous(
            "SELECT r.* FROM recettes r
              WHERE r.statut = 'annulee'
                AND NOT EXISTS (SELECT 1 FROM recettes c WHERE c.contrepasse_id = r.id)
              ORDER BY r.user_id, r.id"
        );
        foreach ($recettes as $r) {
            Database::transaction(static function () use ($r, &$n): void {
                $userId = (int) $r['user_id'];
                $contre = Corrections::recette($userId, $r, (string) ($r['motif_annulation'] ?? ''));
                Journal::consigner($userId, 'annulation', 'recette', (int) $r['id'], (int) $r['exercice_id'], [
                    'motif'           => (string) ($r['motif_annulation'] ?? ''),
                    'contrepassation' => $contre,
                    'reprise'         => 'Écriture d\'annulation inscrite après coup',
                ]);
                $n++;
            });
        }

        $depenses = Database::tous(
            "SELECT d.* FROM depenses d
              WHERE d.statut = 'annulee'
                AND NOT EXISTS (SELECT 1 FROM depenses c WHERE c.contrepasse_id = d.id)
              ORDER BY d.user_id, d.id"
        );
        foreach ($depenses as $d) {
            Database::transaction(static function () use ($d, &$n): void {
                $userId = (int) $d['user_id'];
                $contre = Corrections::depense($userId, $d, (string) ($d['motif_annulation'] ?? ''));
                Journal::consigner($userId, 'annulation', 'depense', (int) $d['id'], (int) $d['exercice_id'], [
                    'motif'           => (string) ($d['motif_annulation'] ?? ''),
                    'contrepassation' => $contre,
                    'reprise'         => 'Écriture d\'annulation inscrite après coup',
                ]);
                $n++;
            });
        }

        return $n;
    }

    /** @return array<int,string> marqueurs de reprise déjà passés */
    public static function faites(): array
    {
        return array_column(
            Database::tous("SELECT version FROM migrations WHERE version LIKE 'reprise:%'"),
            'version'
        );
    }

    /**
     * Passe les reprises pas encore faites.
     *
     * @return array<int,string> descriptions des reprises effectuées
     */
    public static function executer(): array
    {
        $faites = [];
        if (!in_array(self::CONTREPASSATIONS, self::faites(), true)) {
            $n = self::contrepassations();
            Database::inserer('migrations', ['version' => self::CONTREPASSATIONS, 'applique_le' => date('Y-m-d H:i:s')]);
            $faites[] = $n > 0
                ? sprintf('%d écriture(s) d\'annulation inscrite(s) pour les corrections antérieures', $n)
                : 'aucune correction antérieure à reprendre';
        }
        return $faites;
    }
}
