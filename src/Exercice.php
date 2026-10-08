<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Exercices comptables. Un exercice clos refuse toute écriture.
 */
final class Exercice
{
    public static function lister(int $userId): array
    {
        return Database::tous(
            'SELECT * FROM exercices WHERE user_id = :u ORDER BY annee DESC',
            ['u' => $userId]
        );
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un(
            'SELECT * FROM exercices WHERE id = :id AND user_id = :u',
            ['id' => $id, 'u' => $userId]
        );
    }

    /** Première année acceptée : avant, ce serait une faute de frappe. */
    public const ANNEE_MIN = 2000;

    /**
     * Une année est acceptable de 2000 à l'année en cours plus une : on prépare l'exercice qui
     * vient, pas celui de 2072 par un chiffre mal tapé.
     */
    public static function anneeValide(int $annee, int $anneeCourante): bool
    {
        return $annee >= self::ANNEE_MIN && $annee <= $anneeCourante + 1;
    }

    /**
     * Année à proposer pour un nouvel exercice : la suivante, tant qu'elle reste acceptable ;
     * sinon celle qui précède le plus ancien, pour saisir un exercice passé.
     *
     * @param array<int,array{annee:int|string}> $exercices exercices existants
     */
    public static function anneeProposee(array $exercices, int $anneeCourante): int
    {
        $annees = array_map(static fn($x) => (int) $x['annee'], $exercices);
        if (!$annees) {
            return $anneeCourante;
        }
        $suivante = max($annees) + 1;
        return self::anneeValide($suivante, $anneeCourante)
            ? $suivante
            : max(self::ANNEE_MIN, min($annees) - 1);
    }

    public static function creer(int $userId, int $annee): int
    {
        return Database::inserer('exercices', [
            'user_id'    => $userId,
            'annee'      => $annee,
            'date_debut' => sprintf('%d-01-01', $annee),
            'date_fin'   => sprintf('%d-12-31', $annee),
            'statut'     => 'ouvert',
            'cree_le'    => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Exercice actif : celui en session, sinon l'année courante,
     * sinon le plus récent. Créé à la volée si l'utilisateur n'en a aucun.
     */
    public static function actif(int $userId): array
    {
        $id = Auth::exerciceId();
        if ($id !== null) {
            $ex = self::trouver($userId, $id);
            if ($ex !== null) {
                return $ex;
            }
        }

        $annee = (int) date('Y');
        $ex = Database::un(
            'SELECT * FROM exercices WHERE user_id = :u AND annee = :a',
            ['u' => $userId, 'a' => $annee]
        );

        if ($ex === null) {
            $ex = Database::un(
                'SELECT * FROM exercices WHERE user_id = :u ORDER BY annee DESC LIMIT 1',
                ['u' => $userId]
            );
        }

        if ($ex === null) {
            $nouvelId = self::creer($userId, $annee);
            $ex = self::trouver($userId, $nouvelId);
        }

        Auth::definirExercice((int) $ex['id']);
        return $ex;
    }

    /** Exercice couvrant une date donnée — utilisé au rattachement des opérations importées. */
    public static function pourDate(int $userId, string $date): ?array
    {
        return Database::un(
            'SELECT * FROM exercices WHERE user_id = :u AND :d BETWEEN date_debut AND date_fin',
            ['u' => $userId, 'd' => $date]
        );
    }

    public static function ouvert(array $exercice): bool
    {
        return $exercice['statut'] === 'ouvert';
    }

    public static function exigerOuvert(array $exercice): void
    {
        if (!self::ouvert($exercice)) {
            http_response_code(403);
            exit("L'exercice {$exercice['annee']} est clos. Le rouvrir pour le modifier.");
        }
    }

    /**
     * Clôt l'exercice et en fige une archive empreinte.
     *
     * L'archive est créée avant le changement de statut, pour que son
     * instantané reflète l'exercice tel qu'il était au moment de la
     * décision de clore.
     *
     * @return array{0:?int, 1:string} identifiant de l'archive, ou motif d'échec
     */
    public static function clore(int $userId, int $id): array
    {
        $exercice = self::trouver($userId, $id);
        if ($exercice === null) {
            return [null, 'Exercice introuvable.'];
        }
        if ($exercice['statut'] !== 'ouvert') {
            return [null, 'Cet exercice est déjà clos.'];
        }

        return Database::transaction(static function () use ($userId, $id, $exercice): array {
            [$archiveId, $err] = Archive::creer($userId, $id);
            if ($err !== '') {
                return [null, $err];
            }

            Database::modifier('exercices',
                ['statut' => 'clos', 'clos_le' => date('Y-m-d H:i:s')],
                'id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]
            );

            Journal::consigner($userId, 'cloture', 'exercice', $id, $id, [
                'annee'      => (int) $exercice['annee'],
                'archive_id' => $archiveId,
            ]);

            return [$archiveId, ''];
        });
    }

    /**
     * Rouvre un exercice clos. L'acte est consigné : une réouverture après
     * déclaration est précisément le genre d'événement qu'un contrôle veut
     * pouvoir retracer. Les archives déjà figées sont conservées.
     */
    public static function rouvrir(int $userId, int $id): void
    {
        $exercice = self::trouver($userId, $id);
        if ($exercice === null) {
            return;
        }

        Database::transaction(static function () use ($userId, $id, $exercice): void {
            Database::modifier('exercices',
                ['statut' => 'ouvert', 'clos_le' => null],
                'id = :id AND user_id = :u', ['id' => $id, 'u' => $userId]
            );

            Journal::consigner($userId, 'reouverture', 'exercice', $id, $id, [
                'annee'              => (int) $exercice['annee'],
                'etait_clos_depuis'  => $exercice['clos_le'],
            ]);
        });
    }
}
