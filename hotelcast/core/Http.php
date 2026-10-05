<?php
declare(strict_types=1);

/** Small cURL wrapper. */
final class Http
{
    /** @return array{status:int, body:string, headers:array, error:?string} */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20, ?string $saveTo = null): array
    {
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

    public static function get(string $url, array $headers = [], int $timeout = 20): array
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }
}
