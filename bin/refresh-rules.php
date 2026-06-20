<?php

declare(strict_types=1);

/**
 * Cron worker for smart-rules auto-refresh.
 * Run via: php bin/refresh-rules.php
 * Cron: 0 4 * * * cd /path/to/doniixify-php && php bin/refresh-rules.php
 */

require_once __DIR__ . '/../src/Env.php';
\Doniixify\Env::load(__DIR__ . '/..');

require_once __DIR__ . '/../src/Database.php';

use Doniixify\Database;

$start = microtime(true);
$log = function (string $msg) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents(__DIR__ . '/../storage/logs/cron-rules.log', $line, FILE_APPEND);
    echo $line;
};

try {
    $log('Starting smart-rules refresh');
    $rules = Database::fetchAll(
        'SELECT id, user_id, name, rules_json, refresh_days, last_run, playlist_id FROM smart_rules
         WHERE (last_run IS NULL OR last_run < DATE_SUB(NOW(), INTERVAL refresh_days DAY))'
    ) ?: [];
    $log('Found ' . count($rules) . ' rules due for refresh');

    $refreshedCount = 0;
    foreach ($rules as $r) {
        $rules_arr = json_decode($r['rules_json'], true);
        if (!is_array($rules_arr)) continue;
        $uid = (int)$r['user_id'];
        $where = ['1=1'];
        $args = [];
        if (!empty($rules_arr['min_plays'])) { $where[] = 's.play_count >= ?'; $args[] = (int)$rules_arr['min_plays']; }
        if (!empty($rules_arr['min_duration'])) { $where[] = 's.duration >= ?'; $args[] = (int)$rules_arr['min_duration']; }
        if (!empty($rules_arr['liked_only'])) {
            $where[] = "s.id IN (SELECT item_id FROM stars WHERE user_id = ? AND item_type = 'song')";
            $args[] = $uid;
        }
        if (!empty($rules_arr['tag'])) {
            $where[] = 's.id IN (SELECT song_id FROM song_tags WHERE user_id = ? AND tag = ?)';
            $args[] = $uid;
            $args[] = strtolower(trim((string)$rules_arr['tag']));
        }
        $limit = max(1, min(100, (int)($rules_arr['limit'] ?? 30)));
        $songs = Database::fetchAll(
            'SELECT s.id FROM songs s WHERE ' . implode(' AND ', $where) . ' ORDER BY RAND() LIMIT ' . $limit,
            $args
        ) ?: [];
        if (count($songs) < 1) { $log("  Rule {$r['id']} ({$r['name']}): no matching songs, skipping"); continue; }

        $plId = (int)$r['playlist_id'];
        if (!$plId) {
            Database::execute('INSERT INTO playlists (owner_id, name, created_at) VALUES (?, ?, NOW())', [$uid, $r['name']]);
            $plId = (int)Database::lastInsertId();
            Database::execute('UPDATE smart_rules SET playlist_id = ? WHERE id = ?', [$plId, (int)$r['id']]);
        } else {
            Database::execute('DELETE FROM playlist_songs WHERE playlist_id = ?', [$plId]);
        }
        $pos = 1;
        foreach ($songs as $s) {
            Database::execute('INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)', [$plId, (int)$s['id'], $pos++]);
        }
        Database::execute('UPDATE smart_rules SET last_run = NOW() WHERE id = ?', [(int)$r['id']]);
        $refreshedCount++;
        $log("  Rule {$r['id']} ({$r['name']}): refreshed with " . count($songs) . ' tracks');
    }

    $elapsed = round(microtime(true) - $start, 2);
    $log("Done. Refreshed {$refreshedCount} playlists in {$elapsed}s");
} catch (\Throwable $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}
