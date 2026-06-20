<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class LibraryController
{
    public static function hasTrack(): void
    {
        Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $title = trim($_GET['title'] ?? '');
        $artist = trim($_GET['artist'] ?? '');
        if ($title === '') { echo json_encode(['found' => false]); return; }

        $titleNorm = self::normalize($title);
        $artistNorm = self::normalize($artist);
        $titleKey = self::firstWord($titleNorm);

        if ($titleKey !== '') {
            $candidates = Database::fetchAll(
                'SELECT s.id, s.title, ar.name AS artist_name FROM songs s
                 JOIN artists ar ON ar.id = s.artist_id
                 WHERE LOWER(s.title) LIKE ?
                 ORDER BY s.id DESC LIMIT 30',
                ['%' . $titleKey . '%']
            );
            foreach ($candidates as $c) {
                $tn = self::normalize($c['title']);
                $an = self::normalize($c['artist_name']);
                if (self::similar($tn, $titleNorm) && ($artistNorm === '' || self::similar($an, $artistNorm))) {
                    echo json_encode(['found' => true, 'id' => (int)$c['id']]);
                    return;
                }
            }
        }

        echo json_encode(['found' => false]);
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', '', $s);
        $s = preg_replace('/\b(official|video|music|lyric|lyrics|audio|hd|hq|remix|feat\.?|ft\.?)\b/iu', '', $s);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private static function firstWord(string $s): string
    {
        $parts = explode(' ', $s);
        foreach ($parts as $p) {
            if (mb_strlen($p) >= 3) return $p;
        }
        return $parts[0] ?? '';
    }

    private static function similar(string $a, string $b): bool
    {
        if ($a === '' || $b === '') return false;
        if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) return true;
        $aw = array_filter(explode(' ', $a), fn($w) => mb_strlen($w) >= 3);
        $bw = array_filter(explode(' ', $b), fn($w) => mb_strlen($w) >= 3);
        if (empty($aw) || empty($bw)) return false;
        $common = array_intersect($aw, $bw);
        return count($common) / max(count($aw), count($bw)) >= 0.5;
    }

}
