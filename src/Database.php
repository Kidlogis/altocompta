<?php
declare(strict_types=1);

namespace Bnc;

use PDO;

/**
 * Connexion PDO unique + raccourcis de requêtage.
 * Toutes les requêtes passent par des paramètres liés, sans exception.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::requis('db.hote'),
            (int) Config::get('db.port', 3306),
            Config::requis('db.base')
        );

        self::$pdo = new PDO($dsn, Config::requis('db.user'), (string) Config::get('db.mdp', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        return self::$pdo;
    }

    public static function executer(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function tous(string $sql, array $params = []): array
    {
        return self::executer($sql, $params)->fetchAll();
    }

    public static function un(string $sql, array $params = []): ?array
    {
        $ligne = self::executer($sql, $params)->fetch();
        return $ligne === false ? null : $ligne;
    }

    /** @return mixed */
    public static function valeur(string $sql, array $params = [], $defaut = null)
    {
        $v = self::executer($sql, $params)->fetchColumn();
        return $v === false ? $defaut : $v;
    }

    public static function inserer(string $table, array $donnees): int
    {
        $colonnes = array_keys($donnees);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $colonnes),
            implode(', ', array_map(static fn($c) => ':' . $c, $colonnes))
        );
        self::executer($sql, $donnees);
        return (int) self::pdo()->lastInsertId();
    }

    public static function modifier(string $table, array $donnees, string $where, array $params = []): int
    {
        $sets = implode(', ', array_map(static fn($c) => "`$c` = :set_$c", array_keys($donnees)));
        $lies = [];
        foreach ($donnees as $c => $v) {
            $lies['set_' . $c] = $v;
        }
        $st = self::executer("UPDATE `$table` SET $sets WHERE $where", $lies + $params);
        return $st->rowCount();
    }

    /**
     * Exécute un travail dans une transaction.
     *
     * Réentrante : si une transaction est déjà ouverte, le travail y est
     * simplement joint. PDO ne gère pas les transactions imbriquées, et
     * un appel imbriqué lèverait sinon « There is already an active
     * transaction » — cas courant dès qu'un modèle transactionnel en
     * appelle un autre.
     */
    public static function transaction(callable $travail)
    {
        $pdo = self::pdo();

        if ($pdo->inTransaction()) {
            return $travail($pdo);
        }

        $pdo->beginTransaction();
        try {
            $resultat = $travail($pdo);
            $pdo->commit();
            return $resultat;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
