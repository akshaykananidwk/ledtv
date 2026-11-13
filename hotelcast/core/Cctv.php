<?php
declare(strict_types=1);

/**
 * 2.10 CCTV cameras on the TVs (admin/cctv.php, docs/modules/cctv.md).
 *
 * The customer enters the NVR / DVR (brand, IP address or DDNS name, RTSP port, username, password) and the
 * camera channels. Every channel becomes a normal "stream" content item with the brand's RTSP address, and with
 * two or more cameras a split-screen layout item (1×2, 2×2, 2×3 grid) is created as well — assign it to any
 * screen like other content. The TV app plays RTSP natively (UDP, falls back to TCP).
 *
 * The TV must reach the recorder: same local network, or the recorder's RTSP port forwarded on the router
 * (static IP / DDNS). Brand clouds that work with the recorder's serial number only (Dahua / CP Plus P2P,
 * Hik-Connect) are closed vendor services without a public interface and cannot be used.
 *
 * Items carry settings.cctv = {group, nvr, channel, sub} so the page can list and delete a recorder's items.
 */
final class Cctv
{
    public const MAX_CHANNELS = 32;
    /** Largest grid (Layouts::MAX_ZONES). */
    public const MAX_GRID = 6;

    /** brand => [label, RTSP path template ({ch} channel, {s} 0 = main / 1 = sub stream)]. */
    public const BRANDS = [
        'dahua' => ['Dahua', '/cam/realmonitor?channel={ch}&subtype={s}'],
        'cpplus' => ['CP Plus', '/cam/realmonitor?channel={ch}&subtype={s}'],
        'hikvision' => ['Hikvision', '/Streaming/Channels/{ch}0{s1}'],
        'prama' => ['Prama (Hikvision)', '/Streaming/Channels/{ch}0{s1}'],
        'uniview' => ['Uniview (UNV)', '/unicast/c{ch}/s{s}/live'],
        'custom' => ['Other (own RTSP path)', ''],
    ];

    /** RTSP address of one channel. $sub: true = sub stream (smaller, for grids). */
    public static function url(string $brand, string $host, int $port, string $user, string $pass, int $channel, bool $sub, string $customPath = ''): string
    {
        $tpl = $brand === 'custom' ? $customPath : (self::BRANDS[$brand][1] ?? self::BRANDS['dahua'][1]);
        if ($tpl === '' || $tpl[0] !== '/') {
            $tpl = '/' . ltrim($tpl, '/');
        }
        $path = strtr($tpl, ['{ch}' => (string) $channel, '{s}' => $sub ? '1' : '0', '{s1}' => $sub ? '2' : '1']);
        $auth = $user !== '' ? rawurlencode($user) . ($pass !== '' ? ':' . rawurlencode($pass) : '') . '@' : '';
        $hostPart = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host; // IPv6
        return 'rtsp://' . $auth . $hostPart . ($port !== 554 ? ':' . $port : '') . $path;
    }

    /** Channel list from "1,2,5-8". @return int[] */
    public static function channels(string $in): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($in)) ?: [] as $part) {
            if (preg_match('/^(\d{1,3})-(\d{1,3})$/', $part, $m)) {
                for ($c = (int) $m[1]; $c <= (int) $m[2] && count($out) <= self::MAX_CHANNELS; $c++) {
                    $out[$c] = $c;
                }
            } elseif (ctype_digit($part)) {
                $out[(int) $part] = (int) $part;
            }
        }
        $out = array_values(array_filter($out, static fn ($c) => $c >= 1 && $c <= 256));
        sort($out);
        return $out;
    }

    /**
     * Validate the recorder form. @return array{0: array, 1: string[]} [clean values, errors]
     */
    public static function validate(array $in): array
    {
        $e = [];
        $name = trim(mb_substr((string) ($in['name'] ?? ''), 0, 80));
        $brand = isset(self::BRANDS[$in['brand'] ?? '']) ? (string) $in['brand'] : 'dahua';
        $host = strtolower(trim((string) ($in['host'] ?? '')));
        $host = (string) preg_replace('~^(rtsp|https?)://~', '', $host);
        $host = rtrim($host, '/');
        $port = (int) ($in['port'] ?? 554) ?: 554;
        $user = trim((string) ($in['username'] ?? ''));
        $pass = (string) ($in['password'] ?? '');
        $chs = self::channels((string) ($in['channels'] ?? '1'));
        $quality = ($in['quality'] ?? 'auto') === 'main' ? 'main' : (($in['quality'] ?? 'auto') === 'sub' ? 'sub' : 'auto');
        $custom = trim((string) ($in['custom_path'] ?? ''));
        if ($name === '') {
            $e[] = __('Enter a name for the recorder, e.g. "Shop NVR".');
        }
        if ($host === '' || !preg_match('/^([a-z0-9-]+(\.[a-z0-9-]+)*|\d{1,3}(\.\d{1,3}){3}|\[?[0-9a-f:]+\]?)$/', $host)) {
            $e[] = __('Enter the recorder\'s IP address or DDNS name, e.g. 192.168.1.108 or myshop.dahuaddns.com.');
        }
        if ($port < 1 || $port > 65535) {
            $e[] = __('RTSP port must be between 1 and 65535 (usually 554).');
        }
        if (strlen($user) > 64 || strlen($pass) > 64 || preg_match('/[\x00-\x1f]/', $user . $pass)) {
            $e[] = __('Username and password may have at most 64 characters.');
        }
        if (!$chs) {
            $e[] = __('Enter the camera numbers, e.g. 1,2,3,4 or 1-8.');
        } elseif (count($chs) > self::MAX_CHANNELS) {
            $e[] = __('At most :n cameras at once.', ['n' => self::MAX_CHANNELS]);
        }
        if ($brand === 'custom' && ($custom === '' || !str_contains($custom, '{ch}') && count($chs) > 1 || strlen($custom) > 300 || preg_match('/\s/', $custom))) {
            $e[] = __('Enter the RTSP path of the recorder, with {ch} for the camera number when there are several cameras.');
        }
        return [['name' => $name, 'brand' => $brand, 'host' => $host, 'port' => $port, 'username' => $user, 'password' => $pass,
            'channels' => $chs, 'quality' => $quality, 'custom_path' => $custom, 'grid' => !empty($in['grid'])], $e];
    }

    /** Grid preset for n cameras: zones [x, y, w, h] in percent. */
    public static function gridZones(int $n): array
    {
        return match (true) {
            $n <= 1 => [[0, 0, 100, 100]],
            $n === 2 => [[0, 0, 50, 100], [50, 0, 50, 100]],
            $n <= 4 => [[0, 0, 50, 50], [50, 0, 50, 50], [0, 50, 50, 50], [50, 50, 50, 50]],
            default => [[0, 0, 33.33, 50], [33.33, 0, 33.34, 50], [66.67, 0, 33.33, 50], [0, 50, 33.33, 50], [33.33, 50, 33.34, 50], [66.67, 50, 33.33, 50]],
        };
    }

    /**
     * Create the recorder's content (current customer): one stream item per camera, plus grid layouts of at most
     * MAX_GRID cameras each when $v['grid'] and there are 2+ cameras. Returns ['cameras' => ids, 'grids' => ids].
     */
    public static function create(array $v, ?int $userId): array
    {
        $group = bin2hex(random_bytes(6));
        $cams = [];
        $gridOn = $v['grid'] && count($v['channels']) > 1 && Features::enabled('layouts');
        foreach ($v['channels'] as $ch) {
            // Grids use the sub stream (cheap TVs decode 4-6 small streams, not 4-6 full HD ones); single screens the
            // main stream — unless chosen otherwise.
            $sub = $v['quality'] === 'sub' || ($v['quality'] === 'auto' && $gridOn);
            $cams[$ch] = (int) DB::insert('content_items', [
                'title' => mb_substr($v['name'] . ' · ' . __('Camera :n', ['n' => $ch]), 0, 190),
                'type' => 'stream',
                'url' => self::url($v['brand'], $v['host'], $v['port'], $v['username'], $v['password'], $ch, $sub, $v['custom_path']),
                'settings' => json_out(['mute' => true, 'cctv' => ['group' => $group, 'nvr' => $v['name'], 'brand' => $v['brand'], 'channel' => $ch, 'sub' => $sub]]),
                'duration' => 0, 'is_active' => 1, 'created_by' => $userId, 'created_at' => now(),
            ]);
        }
        $grids = [];
        if ($gridOn) {
            $chunks = array_chunk(array_keys($cams), self::MAX_GRID);
            foreach ($chunks as $gi => $chunk) {
                $zones = [];
                foreach (self::gridZones(count($chunk)) as $zi => [$x, $y, $w, $h]) {
                    $zone = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'scale' => 'fill', 'transition' => 'none', 'loop' => true];
                    if (isset($chunk[$zi])) {
                        $zone['content_id'] = $cams[$chunk[$zi]];
                    }
                    if (isset($zone['content_id'])) {
                        $zones[] = $zone;
                    }
                }
                $label = count($chunks) > 1 ? ' (' . ($gi + 1) . '/' . count($chunks) . ')' : '';
                $grids[] = (int) DB::insert('content_items', [
                    'title' => mb_substr($v['name'] . ' · ' . __('All cameras') . $label, 0, 190),
                    'type' => 'layout',
                    'settings' => json_out(['bg_color' => '#000000', 'audio' => 'none', 'zones' => $zones, 'max_decoders' => count($zones),
                        'cctv' => ['group' => $group, 'nvr' => $v['name'], 'brand' => $v['brand'], 'grid' => true]]),
                    'duration' => 0, 'is_active' => 1, 'created_by' => $userId, 'created_at' => now(),
                ]);
            }
        }
        Settings::bumpContentVersion();
        ActivityLog::add('cctv_add', 'content', $grids[0] ?? (array_values($cams)[0] ?? null),
            $v['name'] . ' · ' . self::BRANDS[$v['brand']][0] . ' · ' . $v['host'] . ' · ' . count($cams) . ' camera(s)');
        return ['cameras' => array_values($cams), 'grids' => $grids];
    }

    /** Recorders of the current customer: group => {nvr, brand, items[]} (password never shown). */
    public static function recorders(): array
    {
        $out = [];
        foreach (DB::all("SELECT id, title, type, url, settings, is_active FROM content_items WHERE hotel_id = :h
            AND JSON_EXTRACT(settings, '$.cctv.group') IS NOT NULL ORDER BY id", ['h' => Tenant::id()]) as $r) {
            $c = (ContentManager::settings($r))['cctv'] ?? [];
            $g = (string) ($c['group'] ?? '');
            if ($g === '') {
                continue;
            }
            $out[$g] ??= ['group' => $g, 'nvr' => (string) ($c['nvr'] ?? ''), 'brand' => (string) ($c['brand'] ?? ''), 'host' => '', 'items' => []];
            if ($r['type'] === 'stream' && $out[$g]['host'] === '') {
                $out[$g]['host'] = (string) parse_url((string) $r['url'], PHP_URL_HOST);
            }
            $out[$g]['items'][] = ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'type' => (string) $r['type'], 'active' => (bool) (int) $r['is_active']];
        }
        return $out;
    }

    /** Delete all content items of a recorder (current customer). Returns the number deleted. */
    public static function deleteGroup(string $group): int
    {
        if (!preg_match('/^[0-9a-f]{12}$/', $group)) {
            return 0;
        }
        $n = 0;
        foreach (self::recorders()[$group]['items'] ?? [] as $it) {
            ContentManager::deleteItem($it['id']);
            $n++;
        }
        if ($n) {
            Settings::bumpContentVersion();
            ActivityLog::add('cctv_delete', 'content', null, 'Recorder removed (' . $n . ' item(s))');
        }
        return $n;
    }

    /** RTSP address with the password hidden (for display). */
    public static function masked(string $url): string
    {
        return (string) preg_replace('~^(rtsp://[^:@/]+):[^@/]*@~', '$1:•••@', $url);
    }
}
