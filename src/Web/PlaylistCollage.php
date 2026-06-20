<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class PlaylistCollage
{
    public static function render(int $playlistId): void
    {
        Session::requireLogin();
        if ($playlistId <= 0) {
            http_response_code(400);
            return;
        }
        $cacheDir = __DIR__ . '/../../storage/cache/playlist-covers';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . '/' . $playlistId . '.jpg';
        $mtime = is_file($cacheFile) ? filemtime($cacheFile) : 0;
        $pl = Database::fetchOne('SELECT updated_at FROM playlists WHERE id = ?', [$playlistId]);
        $plMtime = $pl ? strtotime($pl['updated_at'] ?? 'now') : 0;
        if ($mtime > 0 && $mtime >= $plMtime) {
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=3600');
            readfile($cacheFile);
            return;
        }

        $songs = Database::fetchAll(
            'SELECT s.id FROM playlist_songs ps
             JOIN songs s ON s.id = ps.song_id
             WHERE ps.playlist_id = ?
             ORDER BY ps.position ASC
             LIMIT 4',
            [$playlistId]
        ) ?: [];
        if (count($songs) < 1 || !function_exists('imagecreatetruecolor')) {
            http_response_code(404);
            return;
        }

        $coverDir = __DIR__ . '/../../storage/covers';
        $coverPaths = [];
        foreach ($songs as $s) {
            foreach (['jpg', 'png', 'webp'] as $ext) {
                $p = $coverDir . '/' . (int)$s['id'] . '.' . $ext;
                if (is_file($p)) { $coverPaths[] = $p; break; }
            }
        }
        if (empty($coverPaths)) { http_response_code(404); return; }
        while (count($coverPaths) < 4) $coverPaths[] = $coverPaths[0];

        $size = 600;
        $half = $size / 2;
        $canvas = imagecreatetruecolor($size, $size);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 26, 26, 28));

        $positions = [
            [0, 0], [$half, 0],
            [0, $half], [$half, $half],
        ];
        foreach ($coverPaths as $i => $path) {
            if ($i >= 4) break;
            $raw = @file_get_contents($path);
            $im = @imagecreatefromstring($raw ?: '');
            if (!$im) continue;
            $w = imagesx($im);
            $h = imagesy($im);
            imagecopyresampled($canvas, $im, (int)$positions[$i][0], (int)$positions[$i][1], 0, 0, (int)$half, (int)$half, $w, $h);
            imagedestroy($im);
        }
        imagejpeg($canvas, $cacheFile, 85);
        imagedestroy($canvas);
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600');
        readfile($cacheFile);
    }
}
