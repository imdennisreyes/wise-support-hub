<?php

final class Database
{
    private static ?PDO $pdo = null;
    private static bool $sqlite = false;

    public static function connection(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $config = require base_path('config/database.php');
        $forceSqlite = filter_var(getenv('USE_SQLITE') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        if (!$forceSqlite) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s;connect_timeout=5', $config['host'], $config['port'], $config['database'], $config['charset']);
                self::$pdo = new PDO($dsn, $config['username'], $config['password'], self::options());
                return self::$pdo;
            } catch (PDOException $exception) {
                error_log('MySQL unavailable; using local SQLite demo: ' . $exception->getMessage());
            }
        }
        self::$sqlite = true;
        self::$pdo = new PDO('sqlite:' . $config['sqlite_path'], null, null, self::options());
        self::$pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo->exec('PRAGMA busy_timeout = 5000');
        self::bootstrapSqlite();
        return self::$pdo;
    }

    public static function isSqlite(): bool
    {
        self::connection();
        return self::$sqlite;
    }

    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    private static function bootstrapSqlite(): void
    {
        $hasUsers = self::$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if ($hasUsers) {
            return;
        }
        self::$pdo->exec(file_get_contents(base_path('database/sqlite_schema.sql')));
        require base_path('database/sqlite_seed.php');
        seed_sqlite(self::$pdo);
    }
}
