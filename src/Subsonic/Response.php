<?php

declare(strict_types=1);

namespace Doniixify\Subsonic;

use Doniixify\Env;

final class Response
{
    public const ERR_GENERIC = 0;
    public const ERR_REQUIRED_PARAM_MISSING = 10;
    public const ERR_INCOMPATIBLE_CLIENT = 20;
    public const ERR_INCOMPATIBLE_SERVER = 30;
    public const ERR_WRONG_CREDENTIALS = 40;
    public const ERR_TOKEN_AUTH_NOT_SUPPORTED = 41;
    public const ERR_USER_NOT_AUTHORIZED = 50;
    public const ERR_TRIAL_EXPIRED = 60;
    public const ERR_NOT_FOUND = 70;

    public static function ok(array $payload = []): array
    {
        return self::build('ok', $payload);
    }

    public static function error(int $code, string $message): array
    {
        return self::build('failed', [
            'error' => [
                '@code' => $code,
                '@message' => $message,
            ],
        ]);
    }

    private static function build(string $status, array $payload): array
    {
        return array_merge([
            '@status' => $status,
            '@version' => Env::get('SUBSONIC_API_VERSION', '1.16.1'),
            '@type' => 'doniixify',
            '@serverVersion' => Env::get('APP_VERSION', '0.1.0'),
            '@openSubsonic' => true,
        ], $payload);
    }

    public static function send(array $data, string $format = 'xml', ?string $callback = null): void
    {
        $format = strtolower($format);
        if ($format === 'json' || $format === 'jsonp') {
            self::sendJson(['subsonic-response' => self::stripAtPrefix($data)], $format, $callback);
        } else {
            self::sendXml($data);
        }
    }

    private static function sendXml(array $data): void
    {
        header('Content-Type: application/xml; charset=UTF-8');
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = false;
        $root = $xml->createElementNS('http://subsonic.org/restapi', 'subsonic-response');
        self::arrayToXml($data, $root, $xml);
        $xml->appendChild($root);
        echo $xml->saveXML();
    }

    private static function arrayToXml(array $data, \DOMElement $parent, \DOMDocument $doc): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, '@')) {
                $parent->setAttribute(substr($key, 1), self::scalarToString($value));
                continue;
            }
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        $el = $doc->createElement($key);
                        if (is_array($item)) {
                            self::arrayToXml($item, $el, $doc);
                        } else {
                            $el->appendChild($doc->createTextNode(self::scalarToString($item)));
                        }
                        $parent->appendChild($el);
                    }
                } else {
                    $el = $doc->createElement($key);
                    self::arrayToXml($value, $el, $doc);
                    $parent->appendChild($el);
                }
            } else {
                $el = $doc->createElement($key);
                $el->appendChild($doc->createTextNode(self::scalarToString($value)));
                $parent->appendChild($el);
            }
        }
    }

    private static function sendJson(array $data, string $format, ?string $callback): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($format === 'jsonp' && $callback !== null) {
            header('Content-Type: application/javascript; charset=UTF-8');
            echo $callback . '(' . $json . ');';
        } else {
            header('Content-Type: application/json; charset=UTF-8');
            echo $json;
        }
    }

    private static function stripAtPrefix(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $newKey = is_string($key) && str_starts_with($key, '@') ? substr($key, 1) : $key;
            $result[$newKey] = is_array($value) ? self::stripAtPrefix($value) : $value;
        }
        return $result;
    }

    private static function scalarToString(mixed $value): string
    {
        if (is_bool($value)) return $value ? 'true' : 'false';
        if ($value === null) return '';
        return (string)$value;
    }
}
