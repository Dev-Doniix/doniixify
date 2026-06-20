<?php

declare(strict_types=1);

namespace Doniixify\Cache;

use Doniixify\Database;

/**
 * Item-based collaborative filtering recommendation engine.
 *
 * Core idea: songs co-occurring in user listening histories are similar.
 * If user U played both A and B, that adds a "vote" to (A↔B) similarity.
 *
 * Build: precompute co-occurrence matrix as song_similarity table.
 * Query: top-N similar songs to seed song(s), excluding heard ones.
 *
 * Run periodically via: php bin/build-recommendations.php
 */
final class RecommendationEngine
{
    public static function buildSimilarityMatrix(int $maxPairsPerSong = 50, int $minCoListens = 2): array
    {
        Database::execute(
            "CREATE TABLE IF NOT EXISTS song_similarity (
                song_a INT NOT NULL,
                song_b INT NOT NULL,
                score FLOAT NOT NULL,
                co_listens INT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (song_a, song_b),
                INDEX idx_a_score (song_a, score DESC)
            )"
        );

        $stats = ['users_processed' => 0, 'pairs_inserted' => 0, 'started_at' => microtime(true)];
        Database::execute('TRUNCATE TABLE song_similarity');

        $users = Database::fetchAll('SELECT DISTINCT user_id FROM user_song_plays') ?: [];
        $coOccur = [];
        $songPlayCount = [];

        foreach ($users as $u) {
            $uid = (int)$u['user_id'];
            $plays = Database::fetchAll(
                'SELECT song_id FROM user_song_plays WHERE user_id = ? AND play_count >= 1',
                [$uid]
            ) ?: [];
            $songIds = array_map(fn($r) => (int)$r['song_id'], $plays);
            $stats['users_processed']++;
            foreach ($songIds as $sid) {
                $songPlayCount[$sid] = ($songPlayCount[$sid] ?? 0) + 1;
            }
            $count = count($songIds);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $songIds[$i];
                    $b = $songIds[$j];
                    $key = $a < $b ? "$a,$b" : "$b,$a";
                    $coOccur[$key] = ($coOccur[$key] ?? 0) + 1;
                }
            }
        }

        $batchInserts = [];
        foreach ($coOccur as $key => $coCount) {
            if ($coCount < $minCoListens) continue;
            [$a, $b] = array_map('intval', explode(',', $key));
            $popA = $songPlayCount[$a] ?? 1;
            $popB = $songPlayCount[$b] ?? 1;
            $score = $coCount / sqrt($popA * $popB);
            $batchInserts[] = [$a, $b, $score, $coCount];
            $batchInserts[] = [$b, $a, $score, $coCount];
        }

        usort($batchInserts, fn($x, $y) => $y[2] <=> $x[2]);

        $perSongCount = [];
        $kept = [];
        foreach ($batchInserts as $row) {
            $a = $row[0];
            if (($perSongCount[$a] ?? 0) >= $maxPairsPerSong) continue;
            $perSongCount[$a] = ($perSongCount[$a] ?? 0) + 1;
            $kept[] = $row;
        }

        foreach (array_chunk($kept, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $params = [];
            foreach ($chunk as $row) {
                array_push($params, $row[0], $row[1], $row[2], $row[3]);
            }
            Database::execute("INSERT INTO song_similarity (song_a, song_b, score, co_listens) VALUES $placeholders", $params);
            $stats['pairs_inserted'] += count($chunk);
        }

        $stats['elapsed_sec'] = round(microtime(true) - $stats['started_at'], 2);
        return $stats;
    }

    public static function recommendForSong(int $songId, int $limit = 20, ?int $excludeUserId = null): array
    {
        $excludeSql = '';
        $params = [$songId];
        if ($excludeUserId !== null) {
            $excludeSql = ' AND ss.song_b NOT IN (SELECT song_id FROM user_song_plays WHERE user_id = ?)';
            $params[] = $excludeUserId;
        }
        return Database::fetchAll(
            'SELECT s.id, s.title, ar.id AS artist_id, ar.name AS artist_name, ss.score, ss.co_listens
             FROM song_similarity ss
             JOIN songs s ON s.id = ss.song_b
             JOIN artists ar ON ar.id = s.artist_id
             WHERE ss.song_a = ?' . $excludeSql . '
             ORDER BY ss.score DESC LIMIT ' . max(1, min(100, $limit)),
            $params
        ) ?: [];
    }

    public static function recommendForUser(int $userId, int $limit = 25): array
    {
        $topSeeds = Database::fetchAll(
            'SELECT song_id FROM user_song_plays WHERE user_id = ? ORDER BY play_count DESC, last_played_at DESC LIMIT 20',
            [$userId]
        ) ?: [];
        if (empty($topSeeds)) return [];
        $seedIds = array_map(fn($r) => (int)$r['song_id'], $topSeeds);
        $heard = Database::fetchAll('SELECT song_id FROM user_song_plays WHERE user_id = ?', [$userId]) ?: [];
        $heardIds = array_map(fn($r) => (int)$r['song_id'], $heard);

        $scoreAccumulator = [];
        foreach ($seedIds as $seedId) {
            $similar = Database::fetchAll(
                'SELECT song_b, score FROM song_similarity WHERE song_a = ? ORDER BY score DESC LIMIT 50',
                [$seedId]
            ) ?: [];
            foreach ($similar as $row) {
                $sid = (int)$row['song_b'];
                if (in_array($sid, $heardIds, true)) continue;
                $scoreAccumulator[$sid] = ($scoreAccumulator[$sid] ?? 0) + (float)$row['score'];
            }
        }
        arsort($scoreAccumulator);
        $topIds = array_slice(array_keys($scoreAccumulator), 0, $limit);
        if (empty($topIds)) return [];

        $placeholders = implode(',', array_fill(0, count($topIds), '?'));
        $songs = Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.id AS artist_id, ar.name AS artist_name
             FROM songs s JOIN artists ar ON ar.id = s.artist_id
             WHERE s.id IN (' . $placeholders . ')',
            $topIds
        ) ?: [];
        $byId = [];
        foreach ($songs as $s) $byId[(int)$s['id']] = $s;
        $out = [];
        foreach ($topIds as $sid) {
            if (isset($byId[$sid])) {
                $song = $byId[$sid];
                $song['recommendation_score'] = round($scoreAccumulator[$sid], 3);
                $out[] = $song;
            }
        }
        return $out;
    }
}
