<?php

class Schema
{
    public static function ensureColumns(PDO $conn, string $table, array $columns): void
    {
        foreach ($columns as $column => $alterSql) {
            if (!self::columnExists($conn, $table, $column)) {
                $conn->exec($alterSql);
            }
        }
    }

    public static function ensureColumnType(PDO $conn, string $table, string $column, string $alterSql): void
    {
        if (self::columnExists($conn, $table, $column)) {
            try {
                $conn->exec($alterSql);
            } catch (PDOException $e) {
                // Silently ignore
            }
        }
    }
    public static function ensureIndex(PDO $conn, string $table, string $index, string $alterSql): void
    {
        if (self::indexExists($conn, $table, $index)) {
            return;
        }

        try {
            $conn->exec($alterSql);
        } catch (PDOException $e) {
            error_log("Failed to add {$index} index: " . $e->getMessage());
        }
    }

    public static function columnExists(PDO $conn, string $table, string $column): bool
    {
        $stmt = $conn->query(
            "SHOW COLUMNS FROM " . self::identifier($table) . " LIKE " . $conn->quote($column)
        );

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function indexExists(PDO $conn, string $table, string $index): bool
    {
        $stmt = $conn->query(
            "SHOW INDEX FROM " . self::identifier($table) . " WHERE Key_name = " . $conn->quote($index)
        );

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
