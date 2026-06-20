<?php

declare(strict_types=1);

namespace Doniixify\Web;

final class IconView
{
    public static function render(int $size, bool $maskable = false): void
    {
        $preBuiltCandidates = [];
        if ($maskable) {
            $preBuiltCandidates[] = __DIR__ . '/../../app/icons/icon-' . $size . '-maskable.png';
        }
        $preBuiltCandidates[] = __DIR__ . '/../../app/icons/icon-' . $size . '.png';
        foreach ($preBuiltCandidates as $pre) {
            if (is_file($pre) && filesize($pre) > 100) {
                header('Content-Type: image/png');
                header('Cache-Control: public, max-age=2592000');
                header('Content-Length: ' . filesize($pre));
                readfile($pre);
                return;
            }
        }

        $cacheDir = __DIR__ . '/../../storage/icons';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

        $sourceLogo = __DIR__ . '/../../assets/icons/31d87432-356b-4b04-adbf-95a0b03eb3be.png';
        $sourceMtime = is_file($sourceLogo) ? (int)filemtime($sourceLogo) : 0;
        $cacheFile = $cacheDir . '/icon-' . $size . ($maskable ? '-mask' : '') . '-' . $sourceMtime . '.png';

        if (is_file($cacheFile) && filesize($cacheFile) > 100) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=2592000');
            readfile($cacheFile);
            return;
        }

        if (is_file($sourceLogo) && extension_loaded('gd')) {
            self::renderFromSource($sourceLogo, $size, $maskable, $cacheFile);
            return;
        }

        if (!extension_loaded('gd')) {
            header('Content-Type: image/svg+xml');
            header('Cache-Control: public, max-age=2592000');
            echo self::svg($size, $maskable);
            return;
        }

        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        $bg = imagecolorallocate($img, 10, 10, 13);
        imagefilledrectangle($img, 0, 0, $size, $size, $bg);

        $cx = $size / 2;
        $cy = $size / 2;
        $r = $maskable ? $size * 0.38 : $size * 0.45;

        $purple = imagecolorallocate($img, 124, 58, 237);
        $blue = imagecolorallocate($img, 25, 113, 194);
        for ($i = 0; $i < $r; $i++) {
            $t = $i / $r;
            $rr = (int)(124 + ($t * (25 - 124)));
            $gg = (int)(58 + ($t * (113 - 58)));
            $bb = (int)(237 + ($t * (194 - 237)));
            $col = imagecolorallocate($img, $rr, $gg, $bb);
            imagefilledellipse($img, (int)$cx, (int)$cy, (int)(($r - $i) * 2), (int)(($r - $i) * 2), $col);
        }

        $white = imagecolorallocatealpha($img, 255, 255, 255, 0);
        $fontSize = (int)($size * 0.42);
        $text = 'D';
        $fontFile = self::findFont();
        if ($fontFile !== null && function_exists('imagettftext')) {
            $bbox = imagettfbbox($fontSize, 0, $fontFile, $text);
            $tw = abs($bbox[4] - $bbox[0]);
            $th = abs($bbox[5] - $bbox[1]);
            imagettftext($img, $fontSize, 0, (int)($cx - $tw / 2), (int)($cy + $th / 2), $white, $fontFile, $text);
        } else {
            $bw = imagefontwidth(5);
            $bh = imagefontheight(5);
            imagestring($img, 5, (int)($cx - $bw / 2), (int)($cy - $bh / 2), $text, $white);
        }

        imagepng($img, $cacheFile);
        imagedestroy($img);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=2592000');
        readfile($cacheFile);
    }

    private static function renderFromSource(string $sourcePath, int $size, bool $maskable, string $cacheFile): void
    {
        $src = @imagecreatefrompng($sourcePath);
        if (!$src) {
            header('Content-Type: image/png');
            http_response_code(500);
            return;
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $bg = imagecolorallocate($canvas, 10, 10, 13);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);
        imagealphablending($canvas, true);

        $ratio = $maskable ? 0.70 : ($size < 100 ? 1.0 : 0.90);
        $target = (int)($size * $ratio);
        $scale = min($target / $srcW, $target / $srcH);
        $newW = (int)($srcW * $scale);
        $newH = (int)($srcH * $scale);
        $offX = (int)(($size - $newW) / 2);
        $offY = (int)(($size - $newH) / 2);

        imagecopyresampled($canvas, $src, $offX, $offY, 0, 0, $newW, $newH, $srcW, $srcH);

        imagepng($canvas, $cacheFile, 6);
        imagedestroy($src);
        imagedestroy($canvas);

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=2592000');
        readfile($cacheFile);
    }

    private static function svg(int $size, bool $maskable): string
    {
        $r = $maskable ? 38 : 45;
        $cx = 50;
        $cy = 50;
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100">'
            . '<rect width="100" height="100" fill="#0a0a0d"/>'
            . '<defs><radialGradient id="g" cx="50%" cy="50%" r="50%"><stop offset="0%" stop-color="#7c3aed"/><stop offset="100%" stop-color="#1971c2"/></radialGradient></defs>'
            . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="url(#g)"/>'
            . '<text x="50" y="68" font-family="Arial, sans-serif" font-size="56" font-weight="900" fill="#fff" text-anchor="middle">D</text>'
            . '</svg>';
    }

    private static function findFont(): ?string
    {
        $candidates = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
            '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) return $c;
        }
        return null;
    }
}
