<?php
declare(strict_types=1);

/**
 * 2.9.7 Google Drive folder → TV slideshow (core/Apps/GoogleDriveApp.php, docs/modules/google_drive.md).
 *
 * The customer pastes the link of a Drive folder shared as "Anyone with the link". The server lists the photos
 * and videos in it (cached a few minutes, so new files appear on the TVs by themselves):
 *
 *  - with a Google API key (Super Admin → Platform settings → Data feeds field `google_api_key`, or the
 *    GOOGLE_API_KEY config): Drive API v3 files.list — reliable, every file type, videos streamed with range;
 *  - without a key: the public "embedded folder view" page of the folder (no key needed, photos and small
 *    videos; Google may change that page, so the key is recommended for large installs).
 *
 * Media links: photos use Google's image server (sized for 1080p TVs); videos use the Drive API (key) or the
 * direct download link (no key; Google may refuse very large videos without a key).
 */
final class GoogleDrive
{
    public const CACHE_MIN = 3;
    public const MAX_FILES = 500;
    /** 2.9.8: short — an admin / TV request never waits long for Google. */
    public const TIMEOUT = 8;

    /**
     * 2.9.8: no request to Google (admin draft preview, sent while typing): cached lists only. Without it every
     * key press waited for Google while holding the session lock, and the whole admin panel hung.
     */
    public static bool $readOnly = false;
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'heic', 'heif'];
    private const VIDEO_EXT = ['mp4', 'm4v', 'mov', 'webm', 'mkv', '3gp', 'avi'];

    /** Folder id from a Drive folder link (or a bare id); null when the link is not a folder. */
    public static function folderId(string $link): ?string
    {
        $link = trim($link);
        if (preg_match('~^[A-Za-z0-9_-]{10,100}$~', $link)) {
            return $link;
        }
        $p = parse_url($link);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (!in_array($host, ['drive.google.com', 'www.drive.google.com', 'docs.google.com'], true)) {
            return null;
        }
        if (preg_match('~/folders/([A-Za-z0-9_-]{10,100})~', (string) ($p['path'] ?? ''), $m)) {
            return $m[1];
        }
        parse_str((string) ($p['query'] ?? ''), $q);
        $id = is_string($q['id'] ?? null) ? $q['id'] : '';
        $path = (string) ($p['path'] ?? '');
        // drive.google.com/open?id=… and …/embeddedfolderview?id=… (a file link /file/d/… is not a folder).
        return preg_match('~^[A-Za-z0-9_-]{10,100}$~', $id) && ($path === '/open' || str_contains($path, 'folder')) ? $id : null;
    }

    /** resourcekey of an older shared link (needed by Google for some folders), '' when none. */
    public static function resourceKey(string $link): string
    {
        parse_str((string) parse_url(trim($link), PHP_URL_QUERY), $q);
        $k = is_string($q['resourcekey'] ?? null) ? $q['resourcekey'] : '';
        return preg_match('~^[A-Za-z0-9_-]{1,100}$~', $k) ? $k : '';
    }

    public static function apiKey(): string
    {
        $k = trim((string) (Settings::platform('google_api_key', '') ?: Config::get('google_api_key', '')));
        return preg_match('~^[A-Za-z0-9_-]{20,80}$~', $k) ? $k : '';
    }

    /** image | video | null (not shown) from a MIME type or a file name. */
    public static function kind(string $name, string $mime = ''): ?string
    {
        $mime = strtolower($mime);
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if ($mime !== '' && $mime !== 'application/octet-stream') {
            return null;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXT, true) ? 'image' : (in_array($ext, self::VIDEO_EXT, true) ? 'video' : null);
    }

    /** Link the TV loads for a file. */
    public static function mediaUrl(string $fileId, string $kind, string $resourceKey = ''): string
    {
        $key = self::apiKey();
        if ($kind === 'image') {
            return 'https://lh3.googleusercontent.com/d/' . rawurlencode($fileId) . '=w1920-h1080';
        }
        if ($key !== '') {
            return 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true&key=' . rawurlencode($key);
        }
        // 2.9.8: Google's current download host — streams with byte ranges (the video starts at once) and skips the
        // "cannot scan for viruses" page of large files (confirm=t); the old uc?export=download link did neither.
        return 'https://drive.usercontent.google.com/download?id=' . rawurlencode($fileId) . '&export=download&confirm=t'
            . ($resourceKey !== '' ? '&resourcekey=' . rawurlencode($resourceKey) : '');
    }

    /**
     * Photos / videos of a folder, sorted by name: list of {id, name, kind, url}. Cached CACHE_MIN minutes.
     * @return array{items: array, error: string} error '' = ok ('not_found', 'private', 'network', 'empty')
     */
    public static function folder(string $link, bool $fetch = true): array
    {
        $id = self::folderId($link);
        if ($id === null) {
            return ['items' => [], 'error' => 'not_found'];
        }
        $rk = self::resourceKey($link);
        $cacheKey = sha1($id . '|' . $rk . '|' . (self::apiKey() !== '' ? 'k' : 'n'));
        $cached = Cache::get('gdrive', $cacheKey, self::CACHE_MIN * 60);
        if (is_array($cached)) {
            return $cached;
        }
        if (!$fetch || self::$readOnly) {
            $stale = Cache::get('gdrive', $cacheKey . ':last', 86400 * 7);
            return is_array($stale) ? $stale : ['items' => [], 'error' => ''];
        }
        $res = self::apiKey() !== '' ? self::listApi($id, $rk) : self::listEmbedded($id, $rk);
        if ($res['error'] === '' && !$res['items']) {
            $res['error'] = 'empty';
        }
        // Keep the last good list when Google is briefly unreachable.
        if ($res['error'] === 'network') {
            $stale = Cache::get('gdrive', $cacheKey . ':last', 86400 * 7);
            if (is_array($stale) && $stale['items']) {
                return $stale;
            }
        }
        Cache::set('gdrive', $cacheKey, $res);
        if ($res['items']) {
            Cache::set('gdrive', $cacheKey . ':last', $res);
        }
        return $res;
    }

    private static function finish(array $files, string $rk): array
    {
        usort($files, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        $out = [];
        foreach (array_slice($files, 0, self::MAX_FILES) as $f) {
            $out[] = ['id' => $f['id'], 'name' => $f['name'], 'kind' => $f['kind'], 'url' => self::mediaUrl($f['id'], $f['kind'], $rk)];
        }
        return $out;
    }

    /** Drive API v3 (API key). */
    private static function listApi(string $id, string $rk): array
    {
        $files = [];
        $token = '';
        for ($page = 0; $page < 3; $page++) {
            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
                'q' => "'" . $id . "' in parents and trashed = false",
                'fields' => 'nextPageToken,files(id,name,mimeType)',
                'pageSize' => 1000, 'orderBy' => 'name', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
                'key' => self::apiKey(),
            ] + ($token !== '' ? ['pageToken' => $token] : []));
            $headers = $rk !== '' ? ['X-Goog-Drive-Resource-Keys: ' . $id . '/' . $rk] : [];
            $r = Http::get($url, $headers, self::TIMEOUT);
            if ($r['status'] === 404) {
                return ['items' => [], 'error' => 'not_found'];
            }
            if ($r['status'] === 403 || $r['status'] === 401) {
                return ['items' => [], 'error' => 'private'];
            }
            if ($r['status'] !== 200) {
                return ['items' => [], 'error' => 'network'];
            }
            $j = json_decode($r['body'], true);
            foreach ((array) ($j['files'] ?? []) as $f) {
                $kind = self::kind((string) ($f['name'] ?? ''), (string) ($f['mimeType'] ?? ''));
                if ($kind && preg_match('~^[A-Za-z0-9_-]{10,100}$~', (string) ($f['id'] ?? ''))) {
                    $files[] = ['id' => (string) $f['id'], 'name' => (string) $f['name'], 'kind' => $kind];
                }
            }
            $token = (string) ($j['nextPageToken'] ?? '');
            if ($token === '') {
                break;
            }
        }
        return ['items' => self::finish($files, $rk), 'error' => ''];
    }

    /** Public "embedded folder view" page (no key). */
    private static function listEmbedded(string $id, string $rk): array
    {
        $url = 'https://drive.google.com/embeddedfolderview?id=' . rawurlencode($id) . ($rk !== '' ? '&resourcekey=' . rawurlencode($rk) : '');
        $r = Http::get($url, ['Accept-Language: en'], self::TIMEOUT);
        if ($r['status'] === 404) {
            return ['items' => [], 'error' => 'not_found'];
        }
        if ($r['status'] === 403 || $r['status'] === 401 || ($r['status'] === 200 && str_contains($r['body'], 'accounts.google.com/ServiceLogin'))) {
            return ['items' => [], 'error' => 'private'];
        }
        if ($r['status'] !== 200) {
            return ['items' => [], 'error' => 'network'];
        }
        return ['items' => self::finish(self::parseEmbedded($r['body']), $rk), 'error' => ''];
    }

    /** Files of an embedded folder view page: [{id, name, kind}] (sub-folders and documents skipped). Public for tests. */
    public static function parseEmbedded(string $html): array
    {
        $files = [];
        $parts = preg_split('~<div[^>]+class="flip-entry"~i', $html) ?: [];
        array_shift($parts);
        foreach ($parts as $chunk) {
            if (!preg_match('~id="entry-([A-Za-z0-9_-]{10,100})"~', $chunk, $m)) {
                continue;
            }
            if (preg_match('~href="([^"]+)"~', $chunk, $h) && str_contains($h[1], '/folders/')) {
                continue; // sub-folder
            }
            $name = preg_match('~class="flip-entry-title"[^>]*>(.*?)</div>~is', $chunk, $t) ? trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $kind = self::kind($name);
            if ($kind) {
                $files[] = ['id' => $m[1], 'name' => $name, 'kind' => $kind];
            }
        }
        return $files;
    }

    /** User-facing text for an error code. */
    public static function errorText(string $code): string
    {
        return match ($code) {
            'not_found' => __('This Google Drive folder was not found. Copy the folder link again from Google Drive.'),
            'private' => __('The folder is private. In Google Drive choose Share → General access → "Anyone with the link".'),
            'network' => __('Google Drive could not be reached. The TVs keep the last photos and try again in a few minutes.'),
            'empty' => __('No photos or videos in this folder yet.'),
            default => '',
        };
    }
}
