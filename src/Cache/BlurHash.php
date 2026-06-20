<?php

declare(strict_types=1);

namespace Doniixify\Cache;

/**
 * Minimal BlurHash encoder (pure PHP, no dependencies).
 * Reference: https://github.com/woltapp/blurhash
 *
 * Encodes downscaled cover image to ~30-byte string, decoded client-side
 * to a smooth gradient placeholder shown until real cover loads.
 */
final class BlurHash
{
    private const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

    public static function encodeCover(string $imagePath, int $xComp = 4, int $yComp = 3): ?string
    {
        if (!is_file($imagePath)) return null;
        if (!function_exists('imagecreatefromstring')) return null;
        $raw = @file_get_contents($imagePath);
        if ($raw === false) return null;
        $img = @imagecreatefromstring($raw);
        if (!$img) return null;
        $w = imagesx($img);
        $h = imagesy($img);
        $size = 32;
        $small = imagecreatetruecolor($size, $size);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $size, $size, $w, $h);
        imagedestroy($img);
        $pixels = [];
        for ($y = 0; $y < $size; $y++) {
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $c = imagecolorat($small, $x, $y);
                $row[] = [
                    self::srgbToLinear(($c >> 16) & 0xff),
                    self::srgbToLinear(($c >> 8) & 0xff),
                    self::srgbToLinear($c & 0xff),
                ];
            }
            $pixels[] = $row;
        }
        imagedestroy($small);
        $factors = [];
        for ($j = 0; $j < $yComp; $j++) {
            for ($i = 0; $i < $xComp; $i++) {
                $factors[] = self::multiplyBasisFunction($pixels, $size, $size, $i, $j);
            }
        }
        $dc = $factors[0];
        $ac = array_slice($factors, 1);
        $hash = '';
        $sizeFlag = $xComp - 1 + ($yComp - 1) * 9;
        $hash .= self::encode83($sizeFlag, 1);
        if (count($ac) > 0) {
            $maxVal = 0;
            foreach ($ac as $f) {
                foreach ($f as $v) {
                    $maxVal = max($maxVal, abs($v));
                }
            }
            $quant = max(0, min(82, (int)floor($maxVal * 166 - 0.5)));
            $maxAc = ($quant + 1) / 166;
            $hash .= self::encode83($quant, 1);
            $hash .= self::encode83(self::encodeDc($dc), 4);
            foreach ($ac as $f) {
                $hash .= self::encode83(self::encodeAc($f, $maxAc), 2);
            }
        } else {
            $hash .= self::encode83(0, 1);
            $hash .= self::encode83(self::encodeDc($dc), 4);
        }
        return $hash;
    }

    private static function multiplyBasisFunction(array $pixels, int $w, int $h, int $xi, int $yi): array
    {
        $r = 0.0; $g = 0.0; $b = 0.0;
        $norm = ($xi === 0 && $yi === 0) ? 1.0 : 2.0;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $basis = cos(M_PI * $xi * $x / $w) * cos(M_PI * $yi * $y / $h);
                $r += $basis * $pixels[$y][$x][0];
                $g += $basis * $pixels[$y][$x][1];
                $b += $basis * $pixels[$y][$x][2];
            }
        }
        $scale = $norm / ($w * $h);
        return [$r * $scale, $g * $scale, $b * $scale];
    }

    private static function srgbToLinear(int $v): float
    {
        $f = $v / 255.0;
        return $f <= 0.04045 ? $f / 12.92 : pow(($f + 0.055) / 1.055, 2.4);
    }

    private static function linearToSrgb(float $v): int
    {
        $v = max(0.0, min(1.0, $v));
        $f = $v <= 0.0031308 ? $v * 12.92 : 1.055 * pow($v, 1 / 2.4) - 0.055;
        return (int)round($f * 255);
    }

    private static function encodeDc(array $rgb): int
    {
        return (self::linearToSrgb($rgb[0]) << 16) | (self::linearToSrgb($rgb[1]) << 8) | self::linearToSrgb($rgb[2]);
    }

    private static function encodeAc(array $rgb, float $maxAc): int
    {
        $quant = function ($v) use ($maxAc) {
            $sign = $v < 0 ? -1 : 1;
            $abs = $sign * pow(abs($v) / $maxAc, 0.5);
            return max(0, min(18, (int)floor($abs * 9 + 9.5)));
        };
        return $quant($rgb[0]) * 19 * 19 + $quant($rgb[1]) * 19 + $quant($rgb[2]);
    }

    private static function encode83(int $value, int $length): string
    {
        $out = '';
        for ($i = 1; $i <= $length; $i++) {
            $digit = (int)(($value / pow(83, $length - $i)) % 83);
            $out .= self::CHARS[$digit];
        }
        return $out;
    }
}
