<?php
declare(strict_types=1);

namespace Bnc;

/**
 * Sessions, connexion, jeton anti-CSRF.
 */
final class Auth
{
    private const CLE_USER = 'bnc_user_id';
    private const CLE_CSRF = 'bnc_csrf';
    private const CLE_EX   = 'bnc_exercice_id';

    public static function demarrer(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name((string) Config::get('app.session_nom', 'altocompta'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function tenterConnexion(string $email, string $motDePasse): bool
    {
        $u = Database::un(
            'SELECT id, mot_de_passe, actif FROM users WHERE email = :e',
            ['e' => mb_strtolower(trim($email))]
        );

        // Hachage systématique même sans compte : évite de révéler l'existence d'un email par le temps de réponse.
        $hash = $u['mot_de_passe'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';

        if (!password_verify($motDePasse, $hash) || empty($u) || (int) $u['actif'] !== 1) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::modifier('users',
                ['mot_de_passe' => password_hash($motDePasse, PASSWORD_DEFAULT)],
                'id = :id', ['id' => $u['id']]
            );
        }

        session_regenerate_id(true);
        $_SESSION[self::CLE_USER] = (int) $u['id'];
        unset($_SESSION[self::CLE_EX]);

        Database::modifier('users', ['derniere_connexion' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $u['id']]);
        return true;
    }

    public static function deconnecter(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function connecte(): bool
    {
        return isset($_SESSION[self::CLE_USER]);
    }

    public static function userId(): int
    {
        if (!self::connecte()) {
            throw new \RuntimeException('Aucun utilisateur connecté.');
        }
        return (int) $_SESSION[self::CLE_USER];
    }

    /**
     * Chemin de retour accepté après une action : une adresse interne, rien d'autre.
     * Sans cela, un formulaire pourrait renvoyer vers un autre site (« //autre.site »,
     * « https://… ») ou injecter un saut de ligne dans l'en-tête de redirection.
     */
    public static function cheminLocal(string $chemin): string
    {
        return preg_match('#^/(?![/\\\\])[^\r\n]*$#', $chemin) === 1 ? $chemin : '/';
    }

    public static function utilisateur(): ?array
    {
        if (!self::connecte()) {
            return null;
        }
        static $cache = null;
        // Jamais « SELECT * » : le hachage du mot de passe n'a rien à faire dans
        // une ligne qui circule jusqu'aux gabarits.
        return $cache ??= Database::un(
            'SELECT id, email, nom, role, ' . implode(', ', Utilisateur::COLONNES_PROFIL)
            . ' FROM users WHERE id = :id',
            ['id' => self::userId()]
        );
    }

    public static function exiger(): void
    {
        if (!self::connecte()) {
            header('Location: /connexion');
            exit;
        }
    }

    // --- CSRF ---------------------------------------------------------

    public static function jeton(): string
    {
        return $_SESSION[self::CLE_CSRF] ??= bin2hex(random_bytes(32));
    }

    public static function verifierJeton(?string $recu): bool
    {
        return is_string($recu)
            && isset($_SESSION[self::CLE_CSRF])
            && hash_equals($_SESSION[self::CLE_CSRF], $recu);
    }

    public static function exigerJeton(): void
    {
        if (!self::verifierJeton($_POST['_jeton'] ?? null)) {
            http_response_code(419);
            exit('Session expirée. Recharger la page et recommencer.');
        }
    }

    // --- Exercice actif ----------------------------------------------

    public static function exerciceId(): ?int
    {
        return isset($_SESSION[self::CLE_EX]) ? (int) $_SESSION[self::CLE_EX] : null;
    }

    public static function definirExercice(int $id): void
    {
        $_SESSION[self::CLE_EX] = $id;
    }
}
