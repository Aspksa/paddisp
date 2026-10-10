<?php

declare(strict_types=1);

namespace App\Core;

/** Применяет SQL-файлы из database/migrations по порядку имён, один раз каждый. */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return list<string> применённые сейчас миграции */
    public function migrate(): array
    {
        $this->db->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                name VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $applied = [];
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            // DDL в MySQL не транзакционен: миграции пишутся так, чтобы каждая была одним логическим шагом.
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $this->db->pdo->exec($sql);
            }
            $this->db->insert('schema_migrations', ['name' => $name, 'applied_at' => Clock::nowSql()]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Удаляет все таблицы — только для тестовой базы. */
    public function dropAll(): void
    {
        $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all('SHOW TABLES') as $row) {
            $table = (string) array_values($row)[0];
            $this->db->pdo->exec('DROP TABLE `' . str_replace('`', '', $table) . '`');
        }
        $this->db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/u', $sql) ?: [],
            static fn (string $l): bool => !str_starts_with(ltrim($l), '--')
        );
        $parts = preg_split('/;\s*(?:\R|$)/u', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
