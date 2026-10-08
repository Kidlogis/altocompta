<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Pièces justificatives. Les fichiers sont stockés hors docroot et servis
 * par PHP après contrôle de propriété : aucune URL n'y donne accès direct.
 */
final class Justificatif
{
    private const MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
    ];
    private const TAILLE_MAX = 10 * 1024 * 1024;

    public static function pourDepense(int $userId, int $depenseId): array
    {
        return Database::tous(
            'SELECT * FROM justificatifs WHERE user_id = :u AND depense_id = :d ORDER BY id',
            ['u' => $userId, 'd' => $depenseId]
        );
    }

    public static function pourImmobilisation(int $userId, int $immoId): array
    {
        return Database::tous(
            'SELECT * FROM justificatifs WHERE user_id = :u AND immobilisation_id = :i ORDER BY id',
            ['u' => $userId, 'i' => $immoId]
        );
    }

    public static function pourTransaction(int $userId, int $txId): array
    {
        return Database::tous(
            'SELECT * FROM justificatifs WHERE user_id = :u AND transaction_id = :t ORDER BY id',
            ['u' => $userId, 't' => $txId]
        );
    }

    /** Nombre de pièces jointes par opération, pour un lot d'opérations. */
    public static function comptesParTransaction(int $userId, array $txIds): array
    {
        if (!$txIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $txIds));
        $r = [];
        foreach (Database::tous(
            "SELECT transaction_id, COUNT(*) AS n FROM justificatifs
              WHERE user_id = :u AND transaction_id IN ($in) GROUP BY transaction_id",
            ['u' => $userId]) as $l) {
            $r[(int) $l['transaction_id']] = (int) $l['n'];
        }
        return $r;
    }

    /**
     * Rattache à l'écriture définitive les pièces déposées sur l'opération
     * bancaire. Sans cela, le justificatif resterait orphelin après affectation.
     */
    public static function transferer(int $userId, int $txId, string $type, int $cibleId): int
    {
        $colonne = match ($type) {
            'recette'        => 'recette_id',
            'immobilisation' => 'immobilisation_id',
            default          => 'depense_id',
        };
        return Database::modifier('justificatifs', [$colonne => $cibleId],
            'user_id = :u AND transaction_id = :t', ['u' => $userId, 't' => $txId]);
    }

    /**
     * Reporte les pièces d'une écriture annulée sur celle qui la remplace.
     * La pièce justifie l'opération économique, pas telle ligne du registre :
     * sans ce report, chaque correction ferait réapparaître « Manquant ».
     */
    public static function deplacer(int $userId, string $type, int $ancienId, int $nouveauId): int
    {
        $colonne = match ($type) {
            'recette'        => 'recette_id',
            'immobilisation' => 'immobilisation_id',
            default          => 'depense_id',
        };
        return Database::modifier('justificatifs', [$colonne => $nouveauId],
            'user_id = :u AND ' . $colonne . ' = :a', ['u' => $userId, 'a' => $ancienId]);
    }

    public static function trouver(int $userId, int $id): ?array
    {
        return Database::un('SELECT * FROM justificatifs WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);
    }

    /** @param array $fichier entrée de $_FILES */
    public static function ajouter(
        int $userId, array $fichier,
        ?int $depenseId = null, ?int $recetteId = null, ?int $transactionId = null,
        ?int $immobilisationId = null
    ): string
    {
        if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Aucun fichier reçu.';
        }
        if ($fichier['error'] !== UPLOAD_ERR_OK) {
            return match ($fichier['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux pour le serveur.',
                UPLOAD_ERR_PARTIAL                        => 'Envoi interrompu, recommencer.',
                default                                   => 'Envoi impossible (code ' . $fichier['error'] . ').',
            };
        }
        if ($fichier['size'] > self::TAILLE_MAX) {
            return 'Fichier trop volumineux : 10 Mo au maximum.';
        }

        // Le type déclaré par le navigateur n'est pas fiable : on lit le contenu.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($fichier['tmp_name']) ?: '';
        if (!isset(self::MIMES[$mime])) {
            return 'Format non accepté. Formats admis : PDF, JPEG, PNG, WebP, HEIC.';
        }

        $base = rtrim((string) Config::requis('stockage'), '/');
        $sousDossier = sprintf('%d/%s', $userId, date('Y-m'));
        $dossier = $base . '/' . $sousDossier;
        if (!is_dir($dossier) && !@mkdir($dossier, 0750, true) && !is_dir($dossier)) {
            return 'Création du dossier de stockage impossible.';
        }

        $sha = hash_file('sha256', $fichier['tmp_name']);
        $nom = $sha . '.' . self::MIMES[$mime];
        $cible = $dossier . '/' . $nom;

        if (!is_file($cible) && !@move_uploaded_file($fichier['tmp_name'], $cible)) {
            // move_uploaded_file échoue hors contexte HTTP : repli pour les tests.
            if (!@rename($fichier['tmp_name'], $cible)) {
                return 'Enregistrement du fichier impossible.';
            }
        }
        @chmod($cible, 0640);

        Database::inserer('justificatifs', [
            'user_id'     => $userId,
            'depense_id'     => $depenseId,
            'recette_id'     => $recetteId,
            'transaction_id' => $transactionId,
            'immobilisation_id' => $immobilisationId,
            'nom_origine' => mb_substr((string) $fichier['name'], 0, 255),
            'chemin'      => $sousDossier . '/' . $nom,
            'mime'        => $mime,
            'taille'      => (int) $fichier['size'],
            'sha256'      => $sha,
            'ajoute_le'   => date('Y-m-d H:i:s'),
        ]);

        return '';
    }

    public static function supprimer(int $userId, int $id): void
    {
        $j = self::trouver($userId, $id);
        if ($j === null) {
            return;
        }
        Database::executer('DELETE FROM justificatifs WHERE id = :i AND user_id = :u',
            ['i' => $id, 'u' => $userId]);

        // Le fichier est partagé si un autre justificatif porte la même empreinte.
        $encoreUtilise = (int) Database::valeur(
            'SELECT COUNT(*) FROM justificatifs WHERE user_id = :u AND sha256 = :s',
            ['u' => $userId, 's' => $j['sha256']], 0
        );
        if ($encoreUtilise === 0) {
            @unlink(rtrim((string) Config::requis('stockage'), '/') . '/' . $j['chemin']);
        }
    }

    /** Envoie le fichier au navigateur. Le contrôle de propriété a lieu avant. */
    public static function servir(array $j): void
    {
        $chemin = rtrim((string) Config::requis('stockage'), '/') . '/' . $j['chemin'];
        if (!is_file($chemin)) {
            http_response_code(404);
            exit('Fichier introuvable sur le serveur.');
        }
        header('Content-Type: ' . $j['mime']);
        header('Content-Length: ' . filesize($chemin));
        header('Content-Disposition: inline; filename="' . rawurlencode($j['nom_origine']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        readfile($chemin);
    }
}
