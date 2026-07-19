<?php
namespace Wisp;

use PDO;

class DB {
    private static ?PDO $instance = null;
    private static ?string $pluginClass = null;

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
                    $dsn  = $path === '' || $path === ':memory:'
                        ? 'sqlite::memory:'
                        : 'sqlite:' . $path;

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

    public static function query(string $sql, array $params = []): array {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
