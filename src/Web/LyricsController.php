<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class LyricsController
{
    public static function prefetch(string $artist, string $title, int $duration = 0): bool
    {
        $artist = trim($artist);
        $title = trim($title);
        if ($artist === '' || $title === '') return false;
        $key = md5(mb_strtolower($artist) . '|' . mb_strtolower($title));
        try {
            $cached = Database::fetchOne('SELECT 1 FROM lyrics_cache WHERE key_hash = ?', [$key]);
            if ($cached !== null) return true;
        } catch (\Throwable $e) { return false; }
        $result = self::fetchLrclib($artist, $title, $duration);
        try {
            if ($result === null) {
                Database::execute(
                    'INSERT IGNORE INTO lyrics_cache (key_hash, artist, title, not_found) VALUES (?, ?, ?, 1)',
                    [$key, $artist, $title]
                );
                return false;
            }
            Database::execute(
                'INSERT IGNORE INTO lyrics_cache (key_hash, artist, title, plain_lyrics, synced_lyrics) VALUES (?, ?, ?, ?, ?)',
                [$key, $artist, $title, $result['plain'], $result['synced']]
            );
            return true;
        } catch (\Throwable $e) { return false; }
    }

    public static function get(): void
    {
        Session::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        $artist = trim($_GET['artist'] ?? '');
        $title = trim($_GET['title'] ?? '');
        $duration = (int)($_GET['duration'] ?? 0);
        $force = !empty($_GET['force']);

        if ($artist === '' || $title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing artist or title']);
            return;
        }

        $key = md5(mb_strtolower($artist) . '|' . mb_strtolower($title));
        $cached = Database::fetchOne(
            'SELECT plain_lyrics, synced_lyrics, not_found, fetched_at,
                    TIMESTAMPDIFF(MINUTE, fetched_at, NOW()) AS age_min
             FROM lyrics_cache WHERE key_hash = ?',
            [$key]
        );
        if ($cached !== null) {
            $isFail = (int)$cached['not_found'] === 1;
            $ageMin = (int)($cached['age_min'] ?? 0);
            if ($isFail && $ageMin < 1440 && !$force) {
                echo json_encode(['found' => false]);
                return;
            }
            if (!$isFail) {
                echo json_encode(self::buildPayload($cached['plain_lyrics'], $cached['synced_lyrics']));
                return;
            }
        }

        $result = self::fetchLrclib($artist, $title, $duration);
        if ($result === null) {
            Database::execute(
                'INSERT INTO lyrics_cache (key_hash, artist, title, not_found, fetched_at) VALUES (?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE not_found = 1, plain_lyrics = NULL, synced_lyrics = NULL, fetched_at = NOW()',
                [$key, $artist, $title]
            );
            echo json_encode(['found' => false]);
            return;
        }

        Database::execute(
            'INSERT IGNORE INTO lyrics_cache (key_hash, artist, title, plain_lyrics, synced_lyrics) VALUES (?, ?, ?, ?, ?)',
            [$key, $artist, $title, $result['plain'], $result['synced']]
        );
        echo json_encode(self::buildPayload($result['plain'], $result['synced']));
    }

    public static function clearCache(): void
    {
        Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $artist = trim($_GET['artist'] ?? $_POST['artist'] ?? '');
        $title = trim($_GET['title'] ?? $_POST['title'] ?? '');
        if ($artist === '' || $title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing artist or title']);
            return;
        }
        $key = md5(mb_strtolower($artist) . '|' . mb_strtolower($title));
        Database::execute('DELETE FROM lyrics_cache WHERE key_hash = ?', [$key]);
        echo json_encode(['cleared' => true]);
    }

    private static function buildPayload(?string $plain, ?string $synced): array
    {
        $payload = [
            'found' => true,
            'plain' => $plain,
            'synced' => $synced,
            'lines' => null,
            'has_synced' => false,
        ];
        if ($synced) {
            $lines = self::parseLrcLines($synced);
            if (!empty($lines)) {
                $payload['lines'] = $lines;
                $payload['has_synced'] = true;
            }
        }
        return $payload;
    }

    public static function parseLrcLines(string $synced): array
    {
        $out = [];
        $linesRaw = preg_split('/\r?\n/', $synced) ?: [];
        $re = '/\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/';
        foreach ($linesRaw as $ln) {
            if (!preg_match_all($re, $ln, $matches, PREG_OFFSET_CAPTURE)) continue;
            $stamps = [];
            $lastEnd = 0;
            foreach ($matches[0] as $i => $whole) {
                $min = (int)$matches[1][$i][0];
                $sec = (int)$matches[2][$i][0];
                $ms = isset($matches[3][$i]) ? (int)str_pad((string)$matches[3][$i][0], 3, '0') : 0;
                $stamps[] = $min * 60 + $sec + $ms / 1000;
                $lastEnd = max($lastEnd, $whole[1] + strlen($whole[0]));
            }
            $text = trim(substr($ln, $lastEnd));
            foreach ($stamps as $t) {
                $out[] = ['time' => round($t, 3), 'text' => $text];
            }
        }
        usort($out, fn($a, $b) => $a['time'] <=> $b['time']);
        $n = count($out);
        for ($i = 0; $i < $n; $i++) {
            $next = $out[$i + 1] ?? null;
            $out[$i]['end'] = $next ? $next['time'] : $out[$i]['time'] + 3.5;
        }
        return $out;
    }

    private static function normalizeArtist(string $artist): string
    {
        $a = preg_replace('/\s*\b(feat\.?|ft\.?|featuring|with|x|vs\.?|and|&)\s+.+$/iu', '', $artist);
        $a = preg_replace('/\s*[\(\[][^)\]]*[\)\]]\s*/u', ' ', $a ?? $artist);
        $a = preg_replace('/\s+/', ' ', $a ?? '');
        $a = trim((string)$a, " \t\n\r\0\x0B-,;:&");
        return $a !== '' ? $a : $artist;
    }

    private static function normalizeTitle(string $title): string
    {
        $t = preg_replace('/\s*[\(\[][^)\]]*\b(remix|edit|version|mix|remaster(ed)?|live|acoustic|radio|extended|instrumental|feat\.?|ft\.?)\b[^)\]]*[\)\]]\s*/iu', ' ', $title);
        $t = preg_replace('/\s*-\s*(remaster(ed)?|remix|edit|live|acoustic|radio edit|extended|instrumental).*$/iu', '', $t ?? $title);
        $t = preg_replace('/\s+/', ' ', $t ?? '');
        return trim((string)$t) ?: $title;
    }

    private static function fetchLrclib(string $artist, string $title, int $duration): ?array
    {
        $url = 'https://lrclib.net/api/get?'
            . 'artist_name=' . urlencode($artist)
            . '&track_name=' . urlencode($title);
        if ($duration > 0) {
            $url .= '&duration=' . $duration;
        }

        $body = self::httpGet($url);
        if ($body !== null) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $plain = $data['plainLyrics'] ?? null;
                $synced = $data['syncedLyrics'] ?? null;
                if ($plain || $synced) {
                    return ['plain' => $plain, 'synced' => $synced];
                }
            }
        }
        $search = self::searchLrclib($artist, $title);
        if ($search !== null) return $search;

        $normArtist = self::normalizeArtist($artist);
        $normTitle = self::normalizeTitle($title);
        if ($normArtist !== $artist || $normTitle !== $title) {
            $retryUrl = 'https://lrclib.net/api/get?artist_name=' . urlencode($normArtist) . '&track_name=' . urlencode($normTitle);
            if ($duration > 0) $retryUrl .= '&duration=' . $duration;
            $retryBody = self::httpGet($retryUrl);
            if ($retryBody !== null) {
                $retryData = json_decode($retryBody, true);
                if (is_array($retryData) && (($retryData['plainLyrics'] ?? null) || ($retryData['syncedLyrics'] ?? null))) {
                    return ['plain' => $retryData['plainLyrics'] ?? null, 'synced' => $retryData['syncedLyrics'] ?? null];
                }
            }
            $retrySearch = self::searchLrclib($normArtist, $normTitle);
            if ($retrySearch !== null) return $retrySearch;
        }

        $ovh = self::fetchLyricsOvh($artist, $title);
        if ($ovh !== null) return $ovh;

        $netease = self::fetchNetEase($artist, $title);
        if ($netease !== null) return $netease;

        $genius = self::fetchGenius($artist, $title);
        if ($genius !== null) return $genius;

        return null;
    }

    private static function fetchGenius(string $artist, string $title): ?array
    {
        $q = trim(preg_replace('/\([^)]*\)|\[[^\]]*\]|feat\..*|ft\..*|-\s*remix.*|-\s*radio.*/i', '', $title) . ' ' . preg_replace('/,.*$/', '', $artist));
        $searchUrl = 'https://genius.com/api/search/multi?per_page=5&q=' . rawurlencode($q);
        $body = self::httpGet($searchUrl);
        if ($body === null) return null;
        $data = json_decode($body, true);
        $sections = $data['response']['sections'] ?? null;
        if (!is_array($sections)) return null;
        $songUrl = null;
        foreach ($sections as $sec) {
            foreach ($sec['hits'] ?? [] as $hit) {
                $r = $hit['result'] ?? null;
                if (is_array($r) && !empty($r['url']) && !empty($r['type']) && $r['type'] === 'song') {
                    $songUrl = (string)$r['url'];
                    break 2;
                }
            }
        }
        if ($songUrl === null) return null;
        $page = self::httpGet($songUrl);
        if ($page === null) return null;
        if (preg_match_all('#<div[^>]+data-lyrics-container="true"[^>]*>(.+?)</div>#is', $page, $matches)) {
            $raw = implode("\n", $matches[1]);
            $raw = preg_replace('#<br\s*/?>#i', "\n", $raw);
            $raw = preg_replace('#</?[a-z][^>]*>#i', '', $raw);
            $plain = html_entity_decode(trim($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = preg_replace("/\n{3,}/", "\n\n", $plain);
            if ($plain !== '') return ['plain' => $plain, 'synced' => null];
        }
        return null;
    }

    private static function fetchLyricsOvh(string $artist, string $title): ?array
    {
        $url = 'https://api.lyrics.ovh/v1/'
            . rawurlencode($artist) . '/'
            . rawurlencode($title);
        $body = self::httpGet($url);
        if ($body === null) return null;
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['lyrics'])) return null;
        $plain = trim((string)$data['lyrics']);
        return $plain !== '' ? ['plain' => $plain, 'synced' => null] : null;
    }

    private static function fetchNetEase(string $artist, string $title): ?array
    {
        $q = trim($artist . ' ' . $title);
        if ($q === '') return null;
        $searchUrl = 'https://music.163.com/api/search/get/web?csrf_token=&type=1&offset=0&total=true&limit=1&s='
            . rawurlencode($q);
        $body = self::httpGet($searchUrl);
        if ($body === null) return null;
        $data = json_decode($body, true);
        $songs = $data['result']['songs'] ?? null;
        if (!is_array($songs) || empty($songs[0]['id'])) return null;
        $songId = (int)$songs[0]['id'];

        $lyricUrl = 'https://music.163.com/api/song/lyric?os=pc&id=' . $songId . '&lv=-1&kv=-1&tv=-1';
        $body2 = self::httpGet($lyricUrl);
        if ($body2 === null) return null;
        $d2 = json_decode($body2, true);
        $synced = $d2['lrc']['lyric'] ?? null;
        $plain = $d2['nolyric'] === true ? null : ($synced !== null ? preg_replace('/\[\d{2}:\d{2}\.\d{1,3}\]/', '', $synced) : null);
        if (!$plain && !$synced) return null;
        return [
            'plain' => $plain ? trim($plain) : null,
            'synced' => $synced ?: null,
        ];
    }

    private static function searchLrclib(string $artist, string $title): ?array
    {
        $url = 'https://lrclib.net/api/search?'
            . 'artist_name=' . urlencode($artist)
            . '&track_name=' . urlencode($title);
        $body = self::httpGet($url);
        if ($body === null) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data)) {
            return null;
        }
        $first = $data[0];
        $plain = $first['plainLyrics'] ?? null;
        $synced = $first['syncedLyrics'] ?? null;
        if (!$plain && !$synced) {
            return null;
        }
        return ['plain' => $plain, 'synced' => $synced];
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Doniixify/0.1 (lyrics fetcher)',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 200 && is_string($body)) {
                return $body;
            }
            return null;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 3, 'ignore_errors' => true, 'header' => "User-Agent: Doniixify/0.1\r\n"],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
