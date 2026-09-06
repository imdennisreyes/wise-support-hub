<?php

final class Taxonomy
{
    public static function all(string $table, bool $activeOnly = true): array
    {
        self::assertTable($table);
        $sql = "SELECT * FROM {$table}" . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name';
        return Database::connection()->query($sql)->fetchAll();
    }

    public static function findOrCreate(string $table, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;
        self::assertTable($table);
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id) return (int) $id;
        $stmt = $pdo->prepare("INSERT INTO {$table} (name, is_active, created_at, updated_at) VALUES (?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute([$name]);
        return (int) $pdo->lastInsertId();
    }

    private static function assertTable(string $table): void
    {
        if (!in_array($table, ['systems', 'modules', 'categories', 'carriers', 'tags'], true)) {
            throw new InvalidArgumentException('Invalid taxonomy.');
        }
    }
}
