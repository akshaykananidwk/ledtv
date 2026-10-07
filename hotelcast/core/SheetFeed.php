<?php
declare(strict_types=1);

/**
 * Google Sheet as CSV (display app "sheet_table", #14).
 *
 *  - normalizeUrl(): only https://docs.google.com/spreadsheets/… links are accepted and rebuilt from
 *    their parts (published "…/d/e/<id>/pub?output=csv[&gid=N]" or "…/d/<id>/export?format=csv[&gid=N]"),
 *    so the server never fetches any other host (no SSRF). Google itself may redirect the download to
 *    *.googleusercontent.com; that redirect is chosen by Google, not by the admin.
 *  - get(): cached per hotel + URL (Cache, storage/cache/sheet_h<id>/). Fetched again at most every
 *    refresh minutes; on errors the last good copy is kept and the error is reported.
 *  - parse(): fgetcsv on a memory stream, max MAX_ROWS × MAX_COLS, cells cut to MAX_CELL characters,
 *    UTF-8 checked (Windows-1252 converted), HTML answers (sheet not published) rejected.
 */
final class SheetFeed
{
    public const HOST = 'docs.google.com';
    public const MAX_ROWS = 500;
    public const MAX_COLS = 20;
    public const MAX_CELL = 300;
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const TIMEOUT = 15;

    /** Canonical CSV URL of a Google Sheets link, or null when it is not an accepted Google Sheets link. */
    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000) {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $p = parse_url($url);
        if (!is_array($p) || strtolower((string) ($p['host'] ?? '')) !== self::HOST || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return null;
        }
        $path = (string) ($p['path'] ?? '');
        parse_str((string) ($p['query'] ?? ''), $q);
        $gid = null;
        foreach ([is_string($q['gid'] ?? null) ? $q['gid'] : '', preg_match('/(?:^|[&#])gid=(\d{1,12})/', (string) ($p['fragment'] ?? ''), $m) ? $m[1] : ''] as $g) {
            if ($g !== '' && ctype_digit($g) && strlen($g) <= 12) {
                $gid = $g;
                break;
            }
        }
        if (preg_match('#^/spreadsheets/d/e/([A-Za-z0-9_-]{20,200})/pub(?:html)?(?:/.*)?$#', $path, $m)) {
            return 'https://' . self::HOST . '/spreadsheets/d/e/' . $m[1] . '/pub?' . ($gid !== null ? 'gid=' . $gid . '&single=true&' : '') . 'output=csv';
        }
        if (preg_match('#^/spreadsheets/(?:u/\d/)?d/([A-Za-z0-9_-]{20,100})(?:/.*)?$#', $path, $m) && $m[1] !== 'e') {
            return 'https://' . self::HOST . '/spreadsheets/d/' . $m[1] . '/export?format=csv' . ($gid !== null ? '&gid=' . $gid : '');
        }
        return null;
    }

    /**
     * Parse CSV text. @return array{0: ?array, 1: ?array} [rows (list of equal-length lists), error [code, params]]
     */
    public static function parse(string $body): array
    {
        if (strlen($body) > self::MAX_BYTES) {
            return [null, ['too_large', ['n' => (int) (self::MAX_BYTES / 1048576)]]];
        }
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        $trim = ltrim($body);
        if ($trim === '') {
            return [null, ['empty', []]];
        }
        if ($trim[0] === '<' && preg_match('/^<(!doctype|html|head|body|\?xml)/i', $trim)) {
            return [null, ['html', []]];
        }
        if (str_contains($body, "\0")) {
            return [null, ['binary', []]];
        }
        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = (string) mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
        }
        $h = fopen('php://memory', 'r+');
        if ($h === false) {
            return [null, ['binary', []]];
        }
        fwrite($h, $body);
        rewind($h);
        $rows = [];
        $width = 0;
        while (count($rows) < self::MAX_ROWS && ($r = fgetcsv($h, 0, ',', '"', '')) !== false) {
            if ($r === [null]) {
                continue; // blank line
            }
            $r = array_slice($r, 0, self::MAX_COLS);
            $cells = [];
            foreach ($r as $c) {
                $c = trim(str_replace(["\r\n", "\r"], "\n", (string) $c));
                $cells[] = mb_substr($c, 0, self::MAX_CELL);
            }
            if (implode('', $cells) === '') {
                continue;
            }
            $width = max($width, count($cells));
            $rows[] = $cells;
        }
        fclose($h);
        if (!$rows) {
            return [null, ['empty', []]];
        }
        // Trailing empty columns (Google exports the whole grid) are dropped.
        while ($width > 1) {
            $empty = true;
            foreach ($rows as $r) {
                if (($r[$width - 1] ?? '') !== '') {
                    $empty = false;
                    break;
                }
            }
            if (!$empty) {
                break;
            }
            $width--;
        }
        foreach ($rows as &$r) {
            $r = array_slice(array_pad($r, $width, ''), 0, $width);
        }
        unset($r);
        return [$rows, null];
    }

    /** Error code => English text (translated by errorText() in the TV language). */
    public const ERRORS = [
        'too_large' => 'The sheet is too large (more than :n MB).',
        'empty' => 'The sheet is empty.',
        'html' => 'Google did not send CSV. Publish the sheet: File → Share → Publish to web → CSV.',
        'binary' => 'This is not a CSV file.',
        'network' => 'Could not reach Google Sheets (:e).',
        'http' => 'Google Sheets answered with error :c. Is the sheet still published?',
    ];

    /** Translated text of a stored error ([code, params]), '' for none. */
    public static function errorText(mixed $err): string
    {
        if (!is_array($err) || !isset(self::ERRORS[$err[0] ?? ''])) {
            return '';
        }
        return __(self::ERRORS[$err[0]], is_array($err[1] ?? null) ? $err[1] : []);
    }

    private static function ns(): string
    {
        return Cache::hotelNs('sheet');
    }

    /**
     * Cached sheet rows. Fetches again when the last check is older than $refreshMin minutes.
     * @return array{rows: array, ok_at: ?int, checked_at: int, error: ?array} error = [code, params] (errorText())
     */
    public static function get(string $url, int $refreshMin, bool $fetch = true): array
    {
        $empty = ['rows' => [], 'ok_at' => null, 'checked_at' => 0, 'error' => null];
        $entry = Cache::get(self::ns(), $url, 90 * 86400);
        $entry = is_array($entry) ? $entry + $empty : $empty;
        if (!$fetch || time() - (int) $entry['checked_at'] < max(1, $refreshMin) * 60) {
            return $entry;
        }
        // Mark as checked first so parallel TV polls do not all fetch at once.
        $entry['checked_at'] = time();
        Cache::set(self::ns(), $url, $entry);
        $resp = Http::request('GET', $url, ['Accept: text/csv,text/plain;q=0.9,*/*;q=0.1'], null, self::TIMEOUT);
        $err = null;
        if ($resp['status'] !== 200) {
            $err = $resp['status'] === 0
                ? ['network', ['e' => mb_substr((string) ($resp['error'] ?? 'network'), 0, 120)]]
                : ['http', ['c' => $resp['status']]];
        } else {
            [$rows, $err] = self::parse($resp['body']);
            if ($err === null) {
                $entry['rows'] = $rows;
                $entry['ok_at'] = time();
            }
        }
        $entry['error'] = $err;
        Cache::set(self::ns(), $url, $entry);
        if ($err !== null) {
            Logger::write('app', 'warning', 'Sheet table fetch failed', ['hotel' => Tenant::current(), 'url' => $url, 'error' => $err[0]]);
        }
        return $entry;
    }

    /** Forget the cached copy of a sheet (admin "refresh now", tests). */
    public static function forget(string $url): void
    {
        Cache::set(self::ns(), $url, null);
    }
}
