<?php
namespace Wisp;

use PDO;

class DB {
    private static ?PDO $instance = null;
    private static ?string $pluginClass = null;
    private static int $transactionDepth = 0;

    public static function registerPlugin(string $className): void {
        self::$pluginClass = $className;
    }

    public static function table(string $table) {
        if (self::$pluginClass && class_exists(self::$pluginClass)) {
            return new self::$pluginClass($table);
        }
        throw new \Exception("Query builder plugin is not registered.");
    }

    public static function connect(): PDO {
        if (self::$instance === null) {
            $driver = strtolower($_ENV['DB_CONNECTION'] ?? 'mysql');
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            switch ($driver) {
                case 'pgsql':
                case 'postgres':
                case 'postgresql':
                    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
                    $port = $_ENV['DB_PORT'] ?? '5432';
                    $db   = $_ENV['DB_DATABASE'] ?? '';
                    $user = $_ENV['DB_USERNAME'] ?? 'postgres';
                    $pass = $_ENV['DB_PASSWORD'] ?? '';

                    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
                    self::$instance = new PDO($dsn, $user, $pass, $options);
                    break;

                case 'sqlite':
                    $path = $_ENV['DB_DATABASE'] ?? '';
                    if ($path === '' || $path === ':memory:') {
                        $dsn = 'sqlite::memory:';
                    } else {
                        // A relative DB_DATABASE (e.g. "storage/database.sqlite") must not be resolved
                        // against the PHP process's cwd: that's the project root under the dev server
                        // and the CLI, but under Apache/mod_php it's typically public/ — silently pointing
                        // at a nonexistent file (SQLSTATE[HY000] [14] unable to open database file).
                        // Resolve against this file's own location instead, which is cwd-independent.
                        if (!preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $path)) {
                            $path = dirname(__DIR__) . '/' . $path;
                        }
                        $dsn = 'sqlite:' . $path;
                    }

                    self::$instance = new PDO($dsn, null, null, $options);
                    self::$instance->exec('PRAGMA foreign_keys = ON');
                    break;

                case 'mysql':
                default:
                    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
                    $port = $_ENV['DB_PORT'] ?? '3306';
                    $db   = $_ENV['DB_DATABASE'] ?? '';
                    $user = $_ENV['DB_USERNAME'] ?? 'root';
                    $pass = $_ENV['DB_PASSWORD'] ?? '';
                    $charset = 'utf8mb4';

                    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
                    self::$instance = new PDO($dsn, $user, $pass, $options);
                    break;
            }
        }
        return self::$instance;
    }

    public static function setConnection(?PDO $pdo): void {
        self::$instance = $pdo;
        self::$transactionDepth = 0;
    }

    public static function disconnect(): void {
        self::$instance = null;
        self::$transactionDepth = 0;
    }

    public static function query(string $sql, array $params = []): array {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function first(string $sql, array $params = []): ?array {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return is_array($result) ? $result : null;
    }

    public static function value(string $sql, array $params = [], int $column = 0) {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn($column);
        return ($val !== false) ? $val : null;
    }

    public static function execute(string $sql, array $params = []): int {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(?string $name = null): string {
        return self::connect()->lastInsertId($name);
    }

    // --- Transaction Management (with Nested Savepoint Support) ---

    public static function beginTransaction(): void {
        $pdo = self::connect();
        if (self::$transactionDepth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec("SAVEPOINT wisp_trans_" . self::$transactionDepth);
        }
        self::$transactionDepth++;
    }

    public static function commit(): void {
        if (self::$transactionDepth <= 0) {
            throw new \PDOException("There is no active transaction to commit.");
        }
        self::$transactionDepth--;
        $pdo = self::connect();
        if (self::$transactionDepth === 0) {
            $pdo->commit();
        } else {
            $pdo->exec("RELEASE SAVEPOINT wisp_trans_" . self::$transactionDepth);
        }
    }

    public static function rollBack(): void {
        if (self::$transactionDepth <= 0) {
            throw new \PDOException("There is no active transaction to roll back.");
        }
        self::$transactionDepth--;
        $pdo = self::connect();
        if (self::$transactionDepth === 0) {
            $pdo->rollBack();
        } else {
            $pdo->exec("ROLLBACK TO SAVEPOINT wisp_trans_" . self::$transactionDepth);
        }
    }

    public static function inTransaction(): bool {
        return self::$transactionDepth > 0;
    }

    public static function transaction(callable $callback) {
        self::beginTransaction();
        try {
            $result = $callback(self::connect());
            self::commit();
            return $result;
        } catch (\Throwable $e) {
            self::rollBack();
            throw $e;
        }
    }
}
