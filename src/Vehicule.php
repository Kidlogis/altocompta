<?php
declare(strict_types=1);

namespace Bnc;

/** Véhicules et registre des trajets professionnels. */
final class Vehicule
{
    /**
     * Les véhicules relèvent du cœur, les immobilisations d'une extension :
     * aucune jointure ici, sans quoi le cœur ne fonctionnerait plus sans elle.
     * Le lien éventuel n'est qu'un identifiant, résolu par l'extension si
     * elle est installée.
     */
    public static function lister(int $userId, bool $actifsSeuls = false): array
    {
        return Database::tous(
            'SELECT * FROM vehicules WHERE user_id = :u'
            . ($actifsSeuls ? ' AND actif = 1' : '')
            . ' ORDER BY actif DESC, designation',
            ['u' => $userId]
        );
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un('SELECT * FROM vehicules WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    public static function creer(int $userId, array $d): int
    {
        return Database::inserer('vehicules', [
            'user_id'           => $userId,
            'designation'       => $d['designation'],
            'immatriculation'   => $d['immatriculation'] ?: null,
            'type_vehicule'     => $d['type_vehicule'],
            'puissance_cv'      => $d['puissance_cv'],
            'electrique'        => $d['electrique'] ?? 0,
            'immobilisation_id' => $d['immobilisation_id'] ?: null,
            'actif'             => 1,
            'cree_le'           => date('Y-m-d H:i:s'),
        ]);
    }

    public static function modifier(int $userId, int $id, array $d): void
    {
        Database::modifier('vehicules', [
            'designation'       => $d['designation'],
            'immatriculation'   => $d['immatriculation'] ?: null,
            'type_vehicule'     => $d['type_vehicule'],
            'puissance_cv'      => $d['puissance_cv'],
            'electrique'        => $d['electrique'] ?? 0,
            'immobilisation_id' => $d['immobilisation_id'] ?: null,
        ], 'id = :i AND user_id = :u', ['i' => $id, 'u' => $userId]);
    }

    public static function basculerActif(int $userId, int $id): void
    {
        Database::executer('UPDATE vehicules SET actif = 1 - actif WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    public static function supprimer(int $userId, int $id): void
    {
        Database::executer('DELETE FROM vehicules WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    // --- Trajets ------------------------------------------------------

    public static function trajets(int $userId, int $exerciceId, ?int $vehiculeId = null): array
    {
        $sql = 'SELECT t.*, v.designation AS vehicule
                  FROM trajets t JOIN vehicules v ON v.id = t.vehicule_id
                 WHERE t.user_id = :u AND t.exercice_id = :e';
        $p = ['u' => $userId, 'e' => $exerciceId];
        if ($vehiculeId !== null) {
            $sql .= ' AND t.vehicule_id = :v';
            $p['v'] = $vehiculeId;
        }
        return Database::tous($sql . ' ORDER BY t.date_trajet DESC, t.id DESC', $p);
    }

    public static function ajouterTrajet(int $userId, int $exerciceId, array $d): int
    {
        return Database::inserer('trajets', [
            'user_id'     => $userId,
            'vehicule_id' => $d['vehicule_id'],
            'exercice_id' => $exerciceId,
            'date_trajet' => $d['date_trajet'],
            'motif'       => mb_substr($d['motif'], 0, 255),
            'kilometres'  => $d['kilometres'],
            'cree_le'     => date('Y-m-d H:i:s'),
        ]);
    }

    public static function supprimerTrajet(int $userId, int $id): void
    {
        Database::executer('DELETE FROM trajets WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    /** Kilomètres parcourus par véhicule sur un exercice. */
    public static function kmParVehicule(int $userId, int $exerciceId): array
    {
        $r = [];
        foreach (Database::tous(
            'SELECT vehicule_id, SUM(kilometres) AS km, COUNT(*) AS nb
               FROM trajets WHERE user_id = :u AND exercice_id = :e
              GROUP BY vehicule_id',
            ['u' => $userId, 'e' => $exerciceId]) as $l) {
            $r[(int) $l['vehicule_id']] = ['km' => (float) $l['km'], 'nb' => (int) $l['nb']];
        }
        return $r;
    }

    /**
     * Indemnités kilométriques de l'exercice, véhicule par véhicule.
     *
     * @return array{lignes:array<int,array>, total:float, sans_bareme:int}
     */
    public static function indemnites(int $userId, int $exerciceId, int $annee): array
    {
        $km = self::kmParVehicule($userId, $exerciceId);
        $lignes = [];
        $total = 0.0;
        $sansBareme = 0;

        foreach (self::lister($userId) as $v) {
            $parcourus = $km[(int) $v['id']]['km'] ?? 0.0;
            if ($parcourus <= 0) {
                continue;
            }

            $calc = BaremeKm::calculer(
                $annee, $v['type_vehicule'], (int) $v['puissance_cv'],
                (int) $v['electrique'] === 1, $parcourus
            );

            if ($calc === null) {
                $sansBareme++;
            } else {
                $total += $calc['montant'];
            }

            $lignes[] = [
                'vehicule'   => $v,
                'kilometres' => $parcourus,
                'nb_trajets' => $km[(int) $v['id']]['nb'] ?? 0,
                'calcul'     => $calc,
            ];
        }

        return ['lignes' => $lignes, 'total' => round($total, 2), 'sans_bareme' => $sansBareme];
    }

    /**
     * Identifiants d'immobilisations rattachées à un véhicule.
     *
     * Sous option barème, leur dotation n'est pas déductible. Le cœur ne
     * connaît que ces identifiants ; c'est l'extension qui sait ce qu'ils
     * désignent.
     */
    public static function immobilisationsSousBareme(int $userId): array
    {
        return array_map('intval', array_filter(array_column(
            Database::tous(
                'SELECT immobilisation_id FROM vehicules
                  WHERE user_id = :u AND immobilisation_id IS NOT NULL',
                ['u' => $userId]
            ), 'immobilisation_id'
        )));
    }
}
