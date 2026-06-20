<?php

declare(strict_types=1);

namespace Doniixify;

use PDOException;

final class Migrator
{
    public static function runPending(): void
    {
        $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
        sort($files);
        $sig = md5(implode('|', array_map(fn($f) => $f . ':' . (filemtime($f) ?: 0), $files)));
        $cacheFile = __DIR__ . '/../storage/.migrations_hash';
        if (is_file($cacheFile) && @file_get_contents($cacheFile) === $sig) {
            return;
        }
        $report = self::run();
        if (!empty($report['ok']) && empty($report['errors'])) {
            $dir = dirname($cacheFile);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($cacheFile, $sig, LOCK_EX);
        }
    }

    public static function run(): array
    {
        $report = ['ok' => true, 'applied' => [], 'skipped' => [], 'errors' => []];
        try {
            $pdo = Database::pdo();
        } catch (PDOException $e) {
            $report['ok'] = false;
            $report['errors'][] = 'DB connect: ' . $e->getMessage();
            return $report;
        }

        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS _migrations (
                name VARCHAR(255) NOT NULL PRIMARY KEY,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        } catch (PDOException $e) {
            $report['ok'] = false;
            $report['errors'][] = '_migrations table: ' . $e->getMessage();
            return $report;
        }

        $files = glob(__DIR__ . '/../migrations/*.sql');
        sort($files);

        foreach ($files as $file) {
            $name = basename($file);
            $exists = Database::fetchOne('SELECT 1 FROM _migrations WHERE name = ?', [$name]);
            if ($exists !== null) {
                $report['skipped'][] = $name;
                continue;
            }
            $inTx = false;
            try {
                $pdo->beginTransaction();
                $inTx = true;
                $pdo->exec(file_get_contents($file));
                Database::execute('INSERT INTO _migrations (name) VALUES (?)', [$name]);
                $pdo->commit();
                $inTx = false;
                $report['applied'][] = $name;
            } catch (\Throwable $e) {
                if ($inTx) {
                    try { $pdo->rollBack(); } catch (\Throwable $rb) {}
                }
                $report['ok'] = false;
                $report['errors'][] = "{$name}: " . $e->getMessage();
            }
        }
        return $report;
    }

    public static function reset(): array
    {
        $report = ['ok' => true, 'dropped' => [], 'errors' => [], 'migration_report' => null];
        try {
            $pdo = Database::pdo();
        } catch (PDOException $e) {
            $report['ok'] = false;
            $report['errors'][] = 'DB connect: ' . $e->getMessage();
            return $report;
        }

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $tables = $pdo->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                    $report['dropped'][] = $table;
                } catch (\Throwable $e) {
                    $report['ok'] = false;
                    $report['errors'][] = "DROP {$table}: " . $e->getMessage();
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable $e) {
            $report['ok'] = false;
            $report['errors'][] = 'Reset error: ' . $e->getMessage();
            return $report;
        }

        $report['migration_report'] = self::run();
        return $report;
    }

    public static function status(): array
    {
        $report = ['files' => [], 'applied_in_db' => []];
        $files = glob(__DIR__ . '/../migrations/*.sql');
        sort($files);
        foreach ($files as $f) $report['files'][] = basename($f);
        try {
            $rows = Database::fetchAll('SELECT name, executed_at FROM _migrations ORDER BY name');
            $report['applied_in_db'] = $rows;
        } catch (\Throwable $e) {
            $report['applied_in_db_error'] = $e->getMessage();
        }
        return $report;
    }
}
