<?php
declare(strict_types=1);

/**
 * Small cURL wrapper.
 *
 * Tests never hit the network:
 *  - in-process: set Http::$mock = fn(string $method, string $url, array $headers, ?string $body): ?array
 *    returning ['status' => 200, 'body' => '...', 'headers' => []] (null = not mocked → error);
 *  - over HTTP (sandbox server): config 'http_mock_file' = path to a JSON file
 *    {"<url prefix>": {"status": 200, "body": "...", "headers": {}}, ...}; the longest matching prefix wins,
 *    an unmatched URL fails with status 0 (no real request is made while the file is configured).
 */
final class Http
{
    /** @var null|callable(string, string, array, ?string): ?array */
    public static $mock = null;

    /** @return array{status:int, body:string, headers:array, error:?string} */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20, ?string $saveTo = null): array
    {
        $mocked = self::mocked($method, $url, $headers, $body);
        if ($mocked !== null) {
            if ($saveTo !== null && $mocked['status'] >= 200 && $mocked['status'] < 300) {
                file_put_contents($saveTo, $mocked['body']);
            }
            return $mocked;
        }
        $ch = curl_init($url);
        $respHeaders = [];
        $fh = null;
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'HotelCast/' . Version::current()['version'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if ($saveTo !== null) {
            $fh = fopen($saveTo, 'wb');
            $opts[CURLOPT_FILE] = $fh;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        return [
            'status' => $status,
            'body' => is_string($result) ? $result : '',
            'headers' => $respHeaders,
            'error' => $error,
        ];
    }

    /** Canned response when a mock is active, else null (real request). */
    private static function mocked(string $method, string $url, array $headers, ?string $body): ?array
    {
        $norm = static fn (?array $r): array => [
            'status' => (int) ($r['status'] ?? 0),
            'body' => (string) ($r['body'] ?? ''),
            'headers' => array_change_key_case((array) ($r['headers'] ?? []), CASE_LOWER),
            'error' => $r === null ? 'mocked: no response for ' . $url : null,
        ];
        if (self::$mock !== null) {
            return $norm((self::$mock)($method, $url, $headers, $body));
        }
        $file = class_exists('Config') ? (string) Config::get('http_mock_file', '') : '';
        if ($file === '') {
            return null;
        }
        $map = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $best = null;
        $bestLen = -1;
        foreach (is_array($map) ? $map : [] as $prefix => $resp) {
            if (str_starts_with($url, (string) $prefix) && strlen((string) $prefix) > $bestLen) {
                $best = is_array($resp) ? $resp : null;
                $bestLen = strlen((string) $prefix);
            }
        }
        return $norm($best);
    }

    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }
}
