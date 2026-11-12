<?php
declare(strict_types=1);

/**
 * 2.9.3 Live darshan library (docs/modules/live_channels.md): live streams the Super Admin sets up once for the
 * whole platform — first of all "Shri Dwarkadhish Live Darshan". Stored as the platform setting
 * `platform_live_channels` (JSON list). Each channel becomes a normal content item in a customer's library
 * (type stream / youtube, settings.platform_live = channel id):
 *
 *  - "auto" channels are added to every customer (existing ones on save, new ones on creation);
 *  - customers can also add a channel themselves from Templates → Live darshan;
 *  - when the Super Admin changes the link, every linked item of every customer is updated, so all TVs switch
 *    to the new link on their next poll. Titles a customer changed are kept.
 *
 * Customers only ever see a channel's title and their own copy; the platform list is edited in the console.
 */
final class LiveChannels
{
    public const KEY = 'platform_live_channels';
    public const TYPES = ['stream', 'youtube'];
    public const MAX = 50;
    public const DWARKADHISH = 'dwarkadhish';

    /** The built-in first channel (link set by the Super Admin). */
    private static function seed(): array
    {
        return [self::DWARKADHISH => [
            'id' => self::DWARKADHISH,
            'title' => 'શ્રી દ્વારકાધીશ લાઇવ દર્શન',
            'subtitle' => 'Shri Dwarkadhish Live Darshan · Jagat Mandir, Dwarka',
            'type' => 'stream',
            'url' => '',
            'auto' => true,
            'mute' => false,
            'updated_at' => null,
        ]];
    }

    /** @return array<string, array> id => channel, in saved order (the Dwarkadhish channel until something is saved). */
    public static function all(): array
    {
        $raw = Settings::platform(self::KEY);
        if ($raw === null || $raw === '') {
            return self::seed();
        }
        $list = json_decode((string) $raw, true);
        $out = [];
        foreach (is_array($list) ? $list : [] as $c) {
            if (is_array($c) && isset($c['id']) && preg_match('/^[a-z0-9_]{2,40}$/', (string) $c['id'])) {
                $out[(string) $c['id']] = $c + ['title' => '', 'subtitle' => '', 'type' => 'stream', 'url' => '', 'auto' => false, 'mute' => false, 'updated_at' => null];
            }
        }
        return $out;
    }

    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** Channels with a link (the only ones a customer can add / TVs can play). */
    public static function ready(): array
    {
        return array_filter(self::all(), static fn (array $c): bool => trim((string) $c['url']) !== '');
    }

    private static function store(array $channels): void
    {
        Settings::setPlatform(self::KEY, json_encode(array_values($channels), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Settings::flush();
    }

    /**
     * Validate a channel form. @return array{0: array, 1: string[]} [channel, errors]
     */
    public static function validate(array $in, ?array $old = null): array
    {
        $errors = [];
        $title = trim(mb_substr((string) ($in['title'] ?? ''), 0, 150));
        $type = in_array($in['type'] ?? '', self::TYPES, true) ? (string) $in['type'] : 'stream';
        $url = trim((string) ($in['url'] ?? ''));
        if ($title === '') {
            $errors[] = __('Enter a name for the live channel.');
        }
        if ($url !== '') {
            $ok = $type === 'youtube'
                ? ContentManager::validUrl($url, ['http', 'https']) && str_starts_with(ContentManager::youtubeEmbed($url), 'https://www.youtube.com/embed/')
                : ContentManager::validUrl($url, ['http', 'https', 'rtsp', 'rtmp']);
            if (!$ok) {
                $errors[] = $type === 'youtube' ? __('Enter a valid YouTube link.') : __('Enter a valid stream URL (HLS .m3u8 / RTSP / HTTP).');
            }
        }
        $id = $old['id'] ?? self::newId($title);
        return [[
            'id' => $id,
            'title' => $title,
            'subtitle' => trim(mb_substr((string) ($in['subtitle'] ?? ''), 0, 200)),
            'type' => $type,
            'url' => mb_substr($url, 0, 1000),
            'auto' => !empty($in['auto']),
            'mute' => !empty($in['mute']),
            'updated_at' => now(),
        ], $errors];
    }

    private static function newId(string $title): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: '')), '_');
        $base = substr($base !== '' ? $base : 'live', 0, 30);
        $all = self::all();
        $id = strlen($base) >= 2 ? $base : 'live_' . $base;
        for ($i = 2; isset($all[$id]); $i++) {
            $id = substr($base, 0, 30) . '_' . $i;
        }
        return $id;
    }

    /**
     * Save a channel (new when $id is null) and push it to the customers.
     * @return array{channel: array, added: int, updated: int}
     * @throws InvalidArgumentException with the validation messages
     */
    public static function save(?string $id, array $in): array
    {
        $all = self::all();
        $old = $id !== null ? ($all[$id] ?? null) : null;
        if ($id !== null && !$old) {
            throw new InvalidArgumentException(__('Live channel not found.'));
        }
        if (!$old && count($all) >= self::MAX) {
            throw new InvalidArgumentException(__('At most :n live channels.', ['n' => self::MAX]));
        }
        [$c, $errors] = self::validate($in, $old);
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
        $all[$c['id']] = $c;
        self::store($all);
        $res = self::sync($c);
        ActivityLog::add('live_channel_save', 'live_channel', null, $c['title'] . ' · ' . $c['type'] . ' · ' . ($c['auto'] ? 'all customers' : 'on request')
            . ' · added ' . $res['added'] . ', updated ' . $res['updated'], null);
        return ['channel' => $c] + $res;
    }

    /** Remove a channel from the library. Customers keep their copies (no longer linked). */
    public static function delete(string $id): ?array
    {
        $all = self::all();
        $c = $all[$id] ?? null;
        if (!$c) {
            return null;
        }
        unset($all[$id]);
        self::store($all);
        foreach (self::linkedItems($id) as $row) {
            $s = json_decode((string) ($row['settings'] ?? ''), true) ?: [];
            unset($s['platform_live']);
            DB::query('UPDATE content_items SET settings = :s WHERE id = :id', ['s' => json_encode($s ?: new stdClass()), 'id' => (int) $row['id']]);
        }
        ActivityLog::add('live_channel_delete', 'live_channel', null, $c['title'], null);
        return $c;
    }

    /** Content items of all customers linked to a channel (raw rows, any tenant). */
    public static function linkedItems(string $id): array
    {
        return DB::all("SELECT id, hotel_id, title, type, url, settings FROM content_items
            WHERE JSON_UNQUOTE(JSON_EXTRACT(settings, '$.platform_live')) = :id", ['id' => $id]);
    }

    /** Customers (not archived) that have a copy of the channel. @return array<int, int> hotel id => item id */
    public static function holders(string $id): array
    {
        $out = [];
        foreach (self::linkedItems($id) as $r) {
            $out[(int) $r['hotel_id']] = (int) $r['id'];
        }
        return $out;
    }

    /** Channel id => content item id of one customer's copies. */
    public static function holdersIn(int $hotelId): array
    {
        $out = [];
        foreach (DB::all("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(settings, '$.platform_live')) AS ch FROM content_items
            WHERE hotel_id = :h AND JSON_EXTRACT(settings, '$.platform_live') IS NOT NULL", ['h' => $hotelId]) as $r) {
            $out[(string) $r['ch']] ??= (int) $r['id'];
        }
        return $out;
    }

    /**
     * Push a channel to the customers: update every linked item's link, and for an "auto" channel with a link
     * add it to every customer that does not have it yet. Each changed customer's TVs reload their content.
     * @return array{added: int, updated: int}
     */
    public static function sync(array $c): array
    {
        $added = $updated = 0;
        if (trim((string) $c['url']) === '') {
            return ['added' => 0, 'updated' => 0];
        }
        $touched = [];
        foreach (self::linkedItems($c['id']) as $row) {
            $s = json_decode((string) ($row['settings'] ?? ''), true) ?: [];
            if ($row['url'] === $c['url'] && $row['type'] === $c['type'] && (bool) ($s['mute'] ?? false) === (bool) $c['mute']) {
                continue;
            }
            $s['mute'] = (bool) $c['mute'];
            DB::query('UPDATE content_items SET url = :u, type = :t, settings = :s, updated_at = :n WHERE id = :id',
                ['u' => $c['url'], 't' => $c['type'], 's' => json_encode($s), 'n' => now(), 'id' => (int) $row['id']]);
            $updated++;
            $touched[(int) $row['hotel_id']] = true;
        }
        if (!empty($c['auto'])) {
            $have = self::holders($c['id']);
            foreach (DB::all('SELECT id FROM hotels WHERE archived_at IS NULL ORDER BY id') as $h) {
                $hid = (int) $h['id'];
                if (!isset($have[$hid])) {
                    self::insertFor($hid, $c, null);
                    $added++;
                    $touched[$hid] = true;
                }
            }
        }
        foreach (array_keys($touched) as $hid) {
            Tenant::run($hid, static fn () => Settings::bumpContentVersion());
        }
        return ['added' => $added, 'updated' => $updated];
    }

    /** New customer (Hotels::create): every "auto" channel with a link. */
    public static function onNewCustomer(int $hotelId): void
    {
        try {
            foreach (self::ready() as $c) {
                if (!empty($c['auto'])) {
                    self::insertFor($hotelId, $c, null);
                }
            }
        } catch (Throwable $e) {
            Logger::error('Live channels for new customer failed: ' . $e->getMessage());
        }
    }

    /**
     * A customer adds a channel from Templates → Live darshan (current tenant). Returns the content item id
     * (the existing one when the customer already has it).
     */
    public static function addToCurrent(string $id, ?int $userId): int
    {
        $c = self::ready()[$id] ?? null;
        if (!$c) {
            throw new InvalidArgumentException(__('Live channel not found.'));
        }
        $hid = Tenant::id();
        $have = self::holders($id);
        if (isset($have[$hid])) {
            return $have[$hid];
        }
        $nid = self::insertFor($hid, $c, $userId);
        Settings::bumpContentVersion();
        ActivityLog::add('content_create', 'content', $nid, 'Live darshan: ' . $c['title']);
        return $nid;
    }

    private static function insertFor(int $hotelId, array $c, ?int $userId): int
    {
        return (int) DB::insert('content_items', [
            'hotel_id' => $hotelId,
            'title' => mb_substr((string) $c['title'], 0, 190),
            'type' => $c['type'],
            'url' => $c['url'],
            'settings' => json_encode(['mute' => (bool) $c['mute'], 'platform_live' => $c['id']]),
            'duration' => 0,
            'is_active' => 1,
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    /** Stream / YouTube items of all customers, to copy a link from (console). */
    public static function candidates(int $limit = 200): array
    {
        return DB::all("SELECT c.id, c.title, c.type, c.url, h.name AS customer FROM content_items c JOIN hotels h ON h.id = c.hotel_id
            WHERE c.type IN ('stream', 'youtube', 'url', 'video') AND c.url IS NOT NULL AND c.url <> ''
              AND (c.settings IS NULL OR JSON_EXTRACT(c.settings, '$.platform_live') IS NULL)
            ORDER BY (c.type = 'stream') DESC, (c.title LIKE '%arshan%' OR c.title LIKE '%દર્શન%' OR c.title LIKE '%Live%') DESC, c.updated_at DESC
            LIMIT " . max(1, min(500, $limit)));
    }

    /** Is a content item a copy of a platform channel? (content library badge) */
    public static function channelOf(array $item): ?string
    {
        $s = ContentManager::settings($item);
        $id = $s['platform_live'] ?? null;
        return is_string($id) && $id !== '' ? $id : null;
    }
}
