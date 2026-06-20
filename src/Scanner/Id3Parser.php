<?php

declare(strict_types=1);

namespace Doniixify\Scanner;

final class Id3Parser
{
    public static function parse(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $tags = self::parseId3v2($path);
        if (empty($tags['title']) && empty($tags['artist'])) {
            $v1 = self::parseId3v1($path);
            $tags = array_merge($v1, array_filter($tags));
        }

        $tags['duration'] = $tags['duration'] ?? self::estimateDuration($path);
        return $tags;
    }

    private static function parseId3v2(string $path): array
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) return [];

        $header = fread($fp, 10);
        if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
            fclose($fp);
            return [];
        }

        $version = ord($header[3]);
        $tagSize = self::synchSafe(substr($header, 6, 4));
        if ($tagSize <= 0 || $tagSize > 10 * 1024 * 1024) {
            fclose($fp);
            return [];
        }

        $data = fread($fp, $tagSize);
        fclose($fp);

        $tags = [];
        $offset = 0;
        $frameHeaderSize = $version >= 3 ? 10 : 6;

        while ($offset + $frameHeaderSize < $tagSize) {
            $frameId = substr($data, $offset, $version >= 3 ? 4 : 3);
            if (!preg_match('/^[A-Z0-9]+$/', $frameId)) break;

            if ($version >= 3) {
                $frameSize = $version >= 4
                    ? self::synchSafe(substr($data, $offset + 4, 4))
                    : self::beInt32(substr($data, $offset + 4, 4));
            } else {
                $frameSize = self::beInt24(substr($data, $offset + 3, 3));
            }

            if ($frameSize <= 0 || $offset + $frameHeaderSize + $frameSize > $tagSize) break;

            $frameData = substr($data, $offset + $frameHeaderSize, $frameSize);
            $offset += $frameHeaderSize + $frameSize;

            switch ($frameId) {
                case 'APIC':
                case 'PIC':
                    $cover = self::parseApic($frameData, $frameId);
                    if ($cover !== null) $tags['cover'] = $cover;
                    break;
                case 'TIT2':
                case 'TT2':
                    $tags['title'] = self::decodeText($frameData);
                    break;
                case 'TPE1':
                case 'TP1':
                    $tags['artist'] = self::decodeText($frameData);
                    break;
                case 'TPE2':
                case 'TP2':
                    $tags['album_artist'] = self::decodeText($frameData);
                    break;
                case 'TALB':
                case 'TAL':
                    $tags['album'] = self::decodeText($frameData);
                    break;
                case 'TYER':
                case 'TYE':
                case 'TDRC':
                    $year = self::decodeText($frameData);
                    if (preg_match('/(\d{4})/', $year, $m)) {
                        $tags['year'] = (int)$m[1];
                    }
                    break;
                case 'TCON':
                case 'TCO':
                    $tags['genre'] = preg_replace('/\(\d+\)/', '', self::decodeText($frameData));
                    $tags['genre'] = trim($tags['genre']);
                    break;
                case 'TRCK':
                case 'TRK':
                    $track = self::decodeText($frameData);
                    $tags['track'] = (int)explode('/', $track)[0];
                    break;
                case 'TPOS':
                case 'TPA':
                    $disc = self::decodeText($frameData);
                    $tags['disc'] = (int)explode('/', $disc)[0];
                    break;
            }
        }

        return $tags;
    }

    private static function parseId3v1(string $path): array
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) return [];
        $size = filesize($path);
        if ($size < 128) {
            fclose($fp);
            return [];
        }
        fseek($fp, -128, SEEK_END);
        $tag = fread($fp, 128);
        fclose($fp);

        if (substr($tag, 0, 3) !== 'TAG') return [];

        return array_filter([
            'title' => self::cleanV1(substr($tag, 3, 30)),
            'artist' => self::cleanV1(substr($tag, 33, 30)),
            'album' => self::cleanV1(substr($tag, 63, 30)),
            'year' => (int)self::cleanV1(substr($tag, 93, 4)) ?: null,
        ]);
    }

    private static function cleanV1(string $s): string
    {
        $s = rtrim($s, "\x00 ");
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        return $s;
    }

    private static function parseApic(string $data, string $frameId): ?array
    {
        if ($data === '') return null;
        $encoding = ord($data[0]);
        $offset = 1;

        if ($frameId === 'APIC') {
            $end = strpos($data, "\x00", $offset);
            if ($end === false) return null;
            $mime = substr($data, $offset, $end - $offset);
            $offset = $end + 1;
        } else {
            $mime = 'image/' . strtolower(substr($data, $offset, 3));
            $offset += 3;
        }

        if (!isset($data[$offset])) return null;
        $offset++;

        $terminator = ($encoding === 1 || $encoding === 2) ? "\x00\x00" : "\x00";
        $end = strpos($data, $terminator, $offset);
        if ($end === false) return null;
        $offset = $end + strlen($terminator);

        $picture = substr($data, $offset);
        if (strlen($picture) < 100) return null;

        return ['mime' => $mime, 'data' => $picture];
    }

    private static function decodeText(string $data): string
    {
        if ($data === '') return '';
        $encoding = ord($data[0]);
        $text = substr($data, 1);

        switch ($encoding) {
            case 1: // UTF-16 z BOM
                $bom = substr($text, 0, 2);
                $body = $text;
                $enc = 'UTF-16LE';
                if ($bom === "\xFF\xFE") { $body = substr($text, 2); $enc = 'UTF-16LE'; }
                elseif ($bom === "\xFE\xFF") { $body = substr($text, 2); $enc = 'UTF-16BE'; }
                $body = self::trimUtf16($body);
                return rtrim((string)@mb_convert_encoding($body, 'UTF-8', $enc));
            case 2: // UTF-16BE bez BOM
                $body = self::trimUtf16($text);
                return rtrim((string)@mb_convert_encoding($body, 'UTF-8', 'UTF-16BE'));
            case 3: // UTF-8
                $text = rtrim($text, "\x00");
                return mb_check_encoding($text, 'UTF-8') ? $text : self::smartDecode($text);
            case 0: // ISO-8859-1 / lokalne
            default:
                $text = rtrim($text, "\x00");
                return self::smartDecode($text);
        }
    }

    private static function trimUtf16(string $s): string
    {
        // utnij parami terminatory 00 00, ale zachowaj parzystą długość
        while (strlen($s) >= 2 && substr($s, -2) === "\x00\x00") {
            $s = substr($s, 0, -2);
        }
        if (strlen($s) % 2 !== 0) {
            $s = substr($s, 0, -1);
        }
        return $s;
    }

    private static function smartDecode(string $text): string
    {
        if ($text === '') return '';

        // już poprawny UTF-8 (np. zapisany przez Doniixify) — zwróć bez zmian
        if (self::isValidUtf8($text)) {
            return $text;
        }

        // spróbuj kolejnych kodowań, wybierz to bez znaków zastępczych i z polskimi literami
        $candidates = ['Windows-1250', 'ISO-8859-2', 'Windows-1252', 'ISO-8859-1'];
        $best = null;
        $bestScore = PHP_INT_MIN;
        foreach ($candidates as $enc) {
            $converted = @iconv($enc, 'UTF-8//IGNORE', $text);
            if ($converted === false || $converted === '') {
                $converted = @mb_convert_encoding($text, 'UTF-8', $enc);
            }
            if ($converted === false || $converted === '') continue;
            $score = self::scoreText($converted);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $converted;
            }
        }
        return $best ?? $text;
    }

    private static function isValidUtf8(string $s): bool
    {
        return mb_check_encoding($s, 'UTF-8')
            && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s)
            && strpos($s, "\xEF\xBF\xBD") === false;
    }

    private static function scoreText(string $s): int
    {
        if (!mb_check_encoding($s, 'UTF-8')) return PHP_INT_MIN;
        $score = 0;
        $score += preg_match_all('/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u', $s) * 50;
        $score += preg_match_all('/[a-zA-Z0-9 ]/u', $s);
        $score -= preg_match_all('/[\x{FFFD}]/u', $s) * 100;
        $score -= preg_match_all('/[\x{0080}-\x{009F}]/u', $s) * 30;
        return $score;
    }

    private static function synchSafe(string $bytes): int
    {
        if (strlen($bytes) !== 4) return 0;
        $b = unpack('C4', $bytes);
        return (($b[1] & 0x7F) << 21) | (($b[2] & 0x7F) << 14) | (($b[3] & 0x7F) << 7) | ($b[4] & 0x7F);
    }

    private static function beInt32(string $bytes): int
    {
        if (strlen($bytes) !== 4) return 0;
        return unpack('N', $bytes)[1];
    }

    private static function beInt24(string $bytes): int
    {
        if (strlen($bytes) !== 3) return 0;
        $b = unpack('C3', $bytes);
        return ($b[1] << 16) | ($b[2] << 8) | $b[3];
    }

    private static function estimateDuration(string $path): int
    {
        $probe = self::probeFfprobe($path);
        if ($probe > 0) return $probe;

        $size = filesize($path);
        if ($size <= 0) return 0;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3'], true)) {
            return 0;
        }
        $bitrate = self::firstFrameBitrate($path);
        if ($bitrate <= 0) return 0;
        return (int)floor(($size * 8) / $bitrate);
    }

    private static function probeFfprobe(string $path): int
    {
        static $bin = null;
        if ($bin === null) {
            $bin = '';
            foreach (['/usr/bin/ffprobe', '/usr/local/bin/ffprobe', '/opt/ffmpeg/bin/ffprobe'] as $c) {
                if (@is_executable($c)) { $bin = $c; break; }
            }
            if ($bin === '') {
                $which = @shell_exec('command -v ffprobe 2>/dev/null');
                if (is_string($which)) {
                    $found = trim($which);
                    if ($found !== '' && @is_executable($found)) $bin = $found;
                }
            }
        }
        if ($bin === '') return 0;
        $cmd = escapeshellarg($bin)
            . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($path) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        if (!is_string($out)) return 0;
        $sec = (float)trim($out);
        if ($sec <= 0 || !is_finite($sec)) return 0;
        return (int)round($sec);
    }

    private static function firstFrameBitrate(string $path): int
    {
        static $bitrates = [
            [0, 32, 64, 96, 128, 160, 192, 224, 256, 288, 320, 352, 384, 416, 448, 0],
            [0, 32, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 384, 0],
            [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0],
            [0, 32, 48, 56, 64, 80, 96, 112, 128, 144, 160, 176, 192, 224, 256, 0],
            [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
        ];

        $fp = @fopen($path, 'rb');
        if ($fp === false) return 0;

        $header = fread($fp, 10);
        $skip = 0;
        if (strlen($header) >= 10 && substr($header, 0, 3) === 'ID3') {
            $skip = 10 + self::synchSafe(substr($header, 6, 4));
        }
        fseek($fp, $skip);

        $buf = fread($fp, 4096);
        fclose($fp);

        for ($i = 0; $i < strlen($buf) - 4; $i++) {
            $b1 = ord($buf[$i]);
            $b2 = ord($buf[$i + 1]);
            if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) continue;

            $b3 = ord($buf[$i + 2]);
            $versionId = ($b2 >> 3) & 0x03;
            $layer = ($b2 >> 1) & 0x03;
            $brIdx = ($b3 >> 4) & 0x0F;

            if ($versionId === 1 || $layer === 0 || $brIdx === 0 || $brIdx === 15) continue;

            $row = match (true) {
                $versionId === 3 && $layer === 3 => 0,
                $versionId === 3 && $layer === 2 => 1,
                $versionId === 3 && $layer === 1 => 2,
                $layer === 3 => 3,
                default => 4,
            };
            return $bitrates[$row][$brIdx] * 1000;
        }
        return 0;
    }
}
