<?php
declare(strict_types=1);

/**
 * Demo mode (#21) and the reusable sample content.
 *
 *  - Sample content (rooms, groups, timetable, announcements, playlist) for the current hotel:
 *    Demo::sampleContent() — used by the installer ("load demo data"), by the public sign-up (#17,
 *    rooms 101..N) and by the demo hotels.
 *  - Public demo: one shared hotel (hotels.demo_kind = 'public', platform setting demo_hotel_id) with
 *    rich sample data, a read-only demo user (every admin POST is rejected by Demo::guard(), a boot
 *    hook) and public pages demo.php / demo_tv.php. Reset nightly by DemoResetTask.
 *  - Client demo: a private copy named after a prospect (hotels.demo_kind = 'client'), valid N days,
 *    then expired and purged automatically (DemoResetTask → Demo::expireClientDemos()).
 *
 * Platform settings (hotel 0, always read with Settings::platform()): demo_public_enabled,
 * demo_hotel_id, demo_hotel_name, demo_last_reset.
 */
final class Demo
{
    public const KINDS = ['public', 'client'];
    /** Fake TVs of a demo hotel have device_uid "demo-<hotel>-<n>". */
    public const FAKE_TV_PREFIX = 'demo-';
    public const CLIENT_DAYS = 7;
    public const MAX_CLIENT_DEMOS_PER_RESELLER = 10;

    private static array $kindCache = [];
    private static array $tables = [];

    // ------------------------------------------------------------------ sample content

    /**
     * Demo rooms, groups, content and playlist (English + Gujarati) for the CURRENT hotel.
     * $floors = [floor => number of rooms] → rooms <floor>01 … (e.g. [1 => 10, 2 => 10] = 101–110, 201–210).
     * The last two rooms of a floor with 9+ rooms are suites (VIP group). Returns log lines.
     */
    public static function sampleContent(array $floors = [1 => 10, 2 => 10]): array
    {
        return self::seed($floors)['log'];
    }

    /** sampleContent() returning the created ids too: ['log', 'rooms' => [number => id], 'groups', 'content', 'playlist']. */
    public static function seed(array $floors = [1 => 10, 2 => 10]): array
    {
        Tenant::id(); // needs a hotel context
        $log = [];
        $groups = [];
        $rooms = [];
        $vip = null;
        foreach ($floors as $floor => $count) {
            $groups[(int) $floor] = DB::insert('room_groups', ['name' => 'Floor ' . $floor, 'type' => 'floor', 'description' => 'All rooms on floor ' . $floor, 'created_at' => now()]);
        }
        $n = 0;
        foreach ($floors as $floor => $count) {
            $count = max(0, min(99, (int) $count));
            for ($i = 1; $i <= $count; $i++) {
                $num = $floor . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                $suite = $count >= 9 && $i > $count - 2;
                $rid = DB::insert('rooms', ['room_number' => $num, 'name' => ($suite ? 'Suite ' : 'Deluxe ') . $num, 'floor' => (string) $floor, 'created_at' => now()]);
                $rooms[$num] = $rid;
                DB::insert('room_group_members', ['room_id' => $rid, 'group_id' => $groups[(int) $floor]]);
                if ($suite) {
                    $vip ??= DB::insert('room_groups', ['name' => 'Suites (VIP)', 'type' => 'zone', 'description' => 'Premium suites', 'created_at' => now()]);
                    DB::insert('room_group_members', ['room_id' => $rid, 'group_id' => $vip]);
                }
                $n++;
            }
        }
        if ($vip) {
            $groups['vip'] = $vip;
        }
        $log[] = "Created $n demo rooms in " . count($groups) . ' groups';

        $c = [];
        $c['timetable'] = DB::insert('content_items', [
            'title' => 'Dwarkadhish Darshan Timings / દ્વારકાધીશ દર્શન સમય',
            'type' => 'timetable',
            'duration' => 20,
            'body' => json_out([
                'heading' => 'શ્રી દ્વારકાધીશ મંદિર — દર્શન સમય',
                'subheading' => 'Shri Dwarkadhish Temple — Darshan Timings',
                'columns' => ['Time / સમય', 'Darshan / દર્શન'],
                'rows' => [
                    ['06:30 - 07:00', 'Mangla Aarti / મંગળા આરતી'],
                    ['07:00 - 08:00', 'Mangla Darshan / મંગળા દર્શન'],
                    ['08:00 - 09:00', 'Abhishek Puja (Snan) / અભિષેક'],
                    ['09:00 - 09:30', 'Shringar Darshan / શૃંગાર દર્શન'],
                    ['10:30 - 11:00', 'Gwal Bhog / ગ્વાલ ભોગ'],
                    ['11:30 - 12:00', 'Rajbhog / રાજભોગ'],
                    ['12:00 - 13:00', 'Anosar (Temple closed) / અનોસર'],
                    ['17:00 - 17:30', 'Uthappan Darshan / ઉત્થાપન'],
                    ['17:30 - 19:15', 'Darshan / દર્શન'],
                    ['19:30 - 19:45', 'Sandhya Aarti / સંધ્યા આરતી'],
                    ['20:00 - 20:30', 'Shayan Aarti / શયન આરતી'],
                    ['21:30', 'Darshan closes / દર્શન બંધ'],
                ],
                'footer' => 'Timings may change on festivals. / તહેવારોમાં સમય બદલાઈ શકે છે.',
                'bg_color' => '#4A0E0E', 'text_color' => '#FFF8E1', 'accent_color' => '#FFB300',
            ]),
            'settings' => json_out(['refresh_sec' => 60]),
            'created_at' => now(),
        ]);
        $c['welcome'] = DB::insert('content_items', [
            'title' => 'Welcome message / સ્વાગત',
            'type' => 'announcement',
            'duration' => 12,
            'body' => 'જય દ્વારકાધીશ! Welcome to our hotel',
            'settings' => json_out(['subtitle' => 'Breakfast 7:00–10:30 AM · Wi-Fi: Hotel-Guest', 'style' => 'fullscreen', 'bg_color' => '#0D47A1', 'text_color' => '#FFFFFF', 'font_size' => 56]),
            'created_at' => now(),
        ]);
        $c['offer'] = DB::insert('content_items', [
            'title' => 'Restaurant offer',
            'type' => 'announcement',
            'duration' => 10,
            'body' => 'Gujarati Thali — 20% off for in-house guests! ગુજરાતી થાળી પર 20% છૂટ',
            'settings' => json_out(['subtitle' => 'Ground floor restaurant · 12:00–3:00 PM, 7:00–10:30 PM', 'style' => 'fullscreen', 'bg_color' => '#1B5E20', 'text_color' => '#FFFFFF', 'font_size' => 52]),
            'created_at' => now(),
        ]);
        $c['clock'] = DB::insert('content_items', [
            'title' => 'Big clock', 'type' => 'clock', 'duration' => 8,
            'settings' => json_out(['style' => 'digital', 'bg_color' => '#000000', 'text_color' => '#FFD54F']),
            'created_at' => now(),
        ]);
        $c['stream'] = DB::insert('content_items', [
            'title' => 'Live Darshan (demo stream — replace URL)', 'type' => 'stream', 'duration' => 0,
            'url' => 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
            'settings' => json_out(['mute' => false]),
            'created_at' => now(),
        ]);
        $log[] = 'Created ' . count($c) . ' demo content items';

        $pl = DB::insert('content_playlists', ['name' => 'Welcome loop / સ્વાગત', 'description' => 'Default loop for all rooms', 'transition' => 'fade', 'created_at' => now()]);
        foreach (['welcome', 'timetable', 'offer', 'clock'] as $i => $k) {
            DB::insert('playlist_items', ['playlist_id' => $pl, 'content_id' => $c[$k], 'sort_order' => $i]);
        }
        Settings::set('default_playlist_id', (string) $pl);
        // Ticker bar (2.2): one hotel-wide ticker (admin → Ticker bar).
        DB::insert('tickers', [
            'name' => 'Aarti timings', 'message' => 'મંગળા આરતી સવારે 6:30 · Mangla Aarti 6:30 AM · Sandhya Aarti 7:30 PM · Checkout 10:00 AM',
            'target_type' => 'all', 'target_id' => null, 'text_color' => '#FFD700', 'bg_color' => '#000000', 'speed' => 5,
            'font_size' => 26, 'height' => 56, 'position' => 'bottom', 'reserve_space' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $log[] = 'Created demo playlist and set it as default content';
        return ['log' => $log, 'rooms' => $rooms, 'groups' => $groups, 'content' => $c, 'playlist' => $pl];
    }

    // ------------------------------------------------------------------ demo hotels

    public static function publicEnabled(): bool
    {
        return License::mode() === 'saas' && Settings::platform('demo_public_enabled', '0') === '1';
    }

    /** Id of the public demo hotel (null when it does not exist). */
    public static function publicHotelId(): ?int
    {
        $id = (int) Settings::platform('demo_hotel_id', '0');
        if ($id > 0 && self::kind($id) === 'public') {
            return $id;
        }
        $found = (int) DB::value("SELECT id FROM hotels WHERE demo_kind = 'public' ORDER BY id LIMIT 1");
        if ($found) {
            Settings::setPlatform('demo_hotel_id', (string) $found);
            return $found;
        }
        return null;
    }

    /** 'public' | 'client' | null for a hotel (cached per request). */
    public static function kind(?int $hotelId): ?string
    {
        if (!$hotelId) {
            return null;
        }
        if (!array_key_exists($hotelId, self::$kindCache)) {
            try {
                $k = DB::value('SELECT demo_kind FROM hotels WHERE id = :id', ['id' => $hotelId]);
            } catch (Throwable) {
                $k = null; // before migration 007
            }
            self::$kindCache[$hotelId] = in_array($k, self::KINDS, true) ? $k : null;
        }
        return self::$kindCache[$hotelId];
    }

    public static function forget(): void
    {
        self::$kindCache = [];
    }

    /** Is this hotel read-only for its own users (public demo, or a client demo created read-only)? */
    public static function readOnlyHotel(?int $hotelId): bool
    {
        $kind = self::kind($hotelId);
        return $kind === 'public' || ($kind === 'client' && Settings::getFor((int) $hotelId, 'demo_readonly', '0') === '1');
    }

    /** True for hotel users (not platform admins / resellers) of a read-only demo hotel. */
    public static function isReadOnlyUser(?array $user): bool
    {
        return $user !== null && in_array($user['role'] ?? '', Auth::HOTEL_ROLES, true)
            && !empty($user['hotel_id']) && self::readOnlyHotel((int) $user['hotel_id']);
    }

    /** The public demo hotel, created when missing. Returns its id. */
    public static function ensurePublic(): int
    {
        return self::publicHotelId() ?? self::resetPublic();
    }

    /**
     * Create (or wipe and re-create the data of) the public demo hotel. Returns the hotel id.
     * Everything of the hotel is purged — content changed by a platform admin too.
     */
    public static function resetPublic(): int
    {
        $name = trim((string) Settings::platform('demo_hotel_name', '')) ?: 'Hotel Dwarka Palace (Demo)';
        $hid = self::publicHotelId();
        if ($hid) {
            self::purge($hid);
            DB::update('hotels', ['status' => 'active', 'suspend_reason' => null, 'expires_at' => null, 'max_tvs' => 3, 'demo_purged_at' => null], 'id = :id', ['id' => $hid]);
            self::baseSettings($hid, $name);
            Hotels::update($hid, ['name' => $name]);
        } else {
            $hid = Hotels::create([
                'name' => $name, 'city' => 'Dwarka', 'max_tvs' => 3, 'demo_kind' => 'public',
                'notes' => 'Public demo hotel — data is reset every night.',
            ]);
        }
        self::forget();
        self::fill($hid, $name);
        self::createUser($hid, 'manager', 'demo-h' . $hid, null, 'Demo user');
        Settings::setPlatform('demo_hotel_id', (string) $hid);
        Settings::setPlatform('demo_last_reset', (string) time());
        Logger::write('platform', 'info', 'Public demo hotel reset', ['hotel' => $hid]);
        return $hid;
    }

    /** Nightly reset due? (once per calendar day after 03:00, or when the last reset is > 26 h old). */
    public static function resetDue(?int $now = null): bool
    {
        $now ??= time();
        $last = (int) Settings::platform('demo_last_reset', '0');
        if ($last <= 0 || $now - $last > 26 * 3600) {
            return true;
        }
        return date('Y-m-d', $last) !== date('Y-m-d', $now) && (int) date('G', $now) >= 3;
    }

    /**
     * Private demo copy for a prospect ("Demo for client"). Returns ['hotel_id', 'username', 'password', 'expires_at'].
     * $resellerId: the reseller who owns it (resellers see / enter their own demos).
     */
    public static function createClientDemo(string $prospect, ?int $resellerId, ?int $by, int $days = self::CLIENT_DAYS, string $email = '', bool $readOnly = false): array
    {
        $prospect = mb_substr(trim(preg_replace('/\s+/u', ' ', $prospect) ?? ''), 0, 100);
        if (mb_strlen($prospect) < 2) {
            throw new InvalidArgumentException(__('Enter the prospect\'s hotel name.'));
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(__('Enter a valid email address.'));
        }
        if ($resellerId !== null) {
            $active = (int) DB::value("SELECT COUNT(*) FROM hotels WHERE demo_kind = 'client' AND reseller_id = :r AND demo_purged_at IS NULL", ['r' => $resellerId]);
            if ($active >= self::MAX_CLIENT_DEMOS_PER_RESELLER) {
                throw new InvalidArgumentException(__('You already have :n active client demos. Delete one first.', ['n' => $active]));
            }
        }
        $days = max(1, min(30, $days));
        $expires = date('Y-m-d H:i:s', time() + $days * 86400);
        $name = $prospect . ' (Demo)';
        $hid = Hotels::create([
            'name' => $name, 'max_tvs' => 5, 'demo_kind' => 'client', 'reseller_id' => $resellerId,
            'expires_at' => $expires, 'contact_email' => $email !== '' ? $email : null,
            'notes' => 'Client demo for ' . $prospect . ' — expires and is deleted automatically.',
        ], null, $by);
        self::forget();
        self::fill($hid, $name);
        if ($readOnly) {
            Settings::setFor($hid, 'demo_readonly', '1');
        }
        $base = substr(slugify($prospect, 'client'), 0, 30) . '-demo';
        $username = $base;
        for ($i = 2; DB::value('SELECT id FROM users WHERE username = :u', ['u' => $username]); $i++) {
            $username = $base . $i;
        }
        $password = self::password();
        $useEmail = $email !== '' && !DB::value('SELECT id FROM users WHERE email = :e', ['e' => $email]) ? $email : null;
        self::createUser($hid, $readOnly ? 'manager' : 'super_admin', $username, $useEmail, $prospect, $password);
        Logger::write('platform', 'info', 'Client demo created', ['hotel' => $hid, 'by' => $by, 'reseller' => $resellerId]);
        return ['hotel_id' => $hid, 'username' => $username, 'password' => $password, 'expires_at' => $expires];
    }

    /** Client demos past their expiry → status expired + data purged (users too). Returns how many. */
    public static function expireClientDemos(?int $now = null): int
    {
        $now ??= time();
        $ids = DB::column(
            "SELECT id FROM hotels WHERE demo_kind = 'client' AND demo_purged_at IS NULL AND expires_at IS NOT NULL AND expires_at < :n",
            ['n' => date('Y-m-d H:i:s', $now)]
        );
        foreach ($ids as $id) {
            self::deleteClientDemo((int) $id);
        }
        return count($ids);
    }

    /** End a client demo now: expired + purged. */
    public static function deleteClientDemo(int $hotelId): void
    {
        if (self::kind($hotelId) !== 'client') {
            throw new InvalidArgumentException('Not a client demo');
        }
        Hotels::setStatus($hotelId, 'expired', 'demo');
        self::purge($hotelId);
        DB::query('UPDATE hotels SET demo_purged_at = :n, expires_at = IF(expires_at IS NULL OR expires_at > :n2, :n3, expires_at) WHERE id = :id',
            ['n' => now(), 'n2' => now(), 'n3' => now(), 'id' => $hotelId]);
        Tenant::forget($hotelId);
        Logger::write('platform', 'info', 'Client demo expired and purged', ['hotel' => $hotelId]);
    }

    /**
     * Delete every row of a DEMO hotel in every table with a hotel_id column (hotel row, invoices and
     * platform users are kept), plus its upload / storage folders. Child rows go with FK cascades.
     * Refuses non-demo hotels. Returns [table => deleted rows].
     */
    public static function purge(int $hotelId): array
    {
        if (!self::kind($hotelId)) {
            throw new InvalidArgumentException('Only demo hotels can be purged');
        }
        $tables = DB::column(
            "SELECT c.TABLE_NAME FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'hotel_id'
               AND c.TABLE_NAME NOT IN ('hotels', 'signups', 'invoices', 'licenses')"
        );
        $out = [];
        $pending = $tables;
        // FK order is resolved by retrying: a table that is still referenced is deleted in a later pass.
        for ($pass = 0; $pass < 8 && $pending; $pass++) {
            $next = [];
            foreach ($pending as $t) {
                $where = $t === 'users' ? "hotel_id = :h AND role NOT IN ('platform_admin','reseller')" : 'hotel_id = :h';
                try {
                    $out[$t] = DB::query('DELETE FROM `' . str_replace('`', '', $t) . '` WHERE ' . $where, ['h' => $hotelId])->rowCount();
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                        throw $e;
                    }
                    $next[] = $t;
                }
            }
            $pending = $next;
        }
        if ($pending) {
            throw new RuntimeException('Could not purge demo hotel tables: ' . implode(', ', $pending));
        }
        foreach (['uploads/h' . $hotelId, 'storage/apk/h' . $hotelId, 'storage/support/h' . $hotelId] as $dir) {
            if (is_dir(HC_ROOT . '/' . $dir)) {
                rrmdir(HC_ROOT . '/' . $dir);
            }
        }
        Settings::flush();
        Tenant::forget($hotelId);
        Cache::clear(Cache::hotelNs('content', $hotelId));
        return array_filter($out);
    }

    /** Keep the fake TVs of a demo hotel "online" (they never poll). Cheap; throttled to 30 s. */
    public static function keepAlive(int $hotelId): void
    {
        $limit = date('Y-m-d H:i:s', time() - 30);
        $stale = DB::all(
            'SELECT id, room_id, status FROM devices WHERE hotel_id = :h AND device_uid LIKE :p AND is_revoked = 0 AND (last_ping IS NULL OR last_ping < :t OR status <> \'online\')',
            ['h' => $hotelId, 'p' => self::FAKE_TV_PREFIX . $hotelId . '-%', 't' => $limit]
        );
        foreach ($stale as $d) {
            DB::query("UPDATE devices SET last_ping = :n, last_heartbeat = :n2, status = 'online', offline_notified = 0 WHERE id = :id AND hotel_id = :h",
                ['n' => now(), 'n2' => now(), 'id' => $d['id'], 'h' => $hotelId]);
            if ($d['status'] !== 'online') {
                DB::query("INSERT INTO device_status_logs (hotel_id, device_id, room_id, status, created_at) VALUES (:h, :d, :r, 'online', :n)",
                    ['h' => $hotelId, 'd' => $d['id'], 'r' => $d['room_id'], 'n' => now()]);
            }
        }
    }

    /**
     * Boot hook (core/boot.d/signup_demo.php): users of a read-only demo hotel may not change
     * anything. Every non-GET request to an admin script is refused except login, logout and the
     * language switch (AJAX: 403 JSON DEMO_READONLY; pages: flash + redirect back).
     */
    public static function guard(): void
    {
        if (empty($_COOKIE['HCSESSID'])) {
            return;
        }
        $file = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
        $adminDir = realpath(HC_ROOT . '/admin') ?: HC_ROOT . '/admin';
        $name = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $adminPath = (string) parse_url(admin_url(), PHP_URL_PATH);
        if ($file !== '' && str_starts_with($file, $adminDir . DIRECTORY_SEPARATOR)) {
            $script = basename($file);
        } elseif ($adminPath !== '' && str_starts_with($name, $adminPath)) {
            $script = basename($name) ?: 'index.php'; // directory index through a front router
        } else {
            return;
        }
        $user = Auth::user();
        if (!self::isReadOnlyUser($user)) {
            return;
        }
        self::keepAlive((int) $user['hotel_id']);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
        if (in_array($script, ['login.php', 'logout.php'], true) || ($script === 'ajax.php' && $action === 'set_language')) {
            return;
        }
        self::block($script);
    }

    public static function readOnlyMessage(): string
    {
        return __('Demo mode — changes are disabled.');
    }

    private static function block(string $script): never
    {
        Logger::write('security', 'info', 'Demo write blocked', ['script' => $script, 'action' => $_GET['action'] ?? null]);
        $ajax = Auth::isAjax() || $script === 'ajax.php' || $script === 'ajax_update.php';
        if ($ajax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_out(['ok' => false, 'error' => ['code' => 'DEMO_READONLY', 'message' => self::readOnlyMessage()]]);
            exit;
        }
        require_once HC_ROOT . '/admin/partials/flash.php';
        flash('warning', self::readOnlyMessage());
        $back = admin_url($script === 'index.php' ? 'index.php' : $script);
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $adminPath = (string) parse_url(admin_url(), PHP_URL_PATH);
        if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === parse_url(admin_url(), PHP_URL_HOST)
            && str_starts_with((string) parse_url($ref, PHP_URL_PATH), $adminPath) && !preg_match('/[\r\n]/', $ref)) {
            $back = $ref;
        }
        header('Location: ' . $back, true, 303);
        exit;
    }

    // ------------------------------------------------------------------ internals

    /** Rich sample data for a demo hotel (runs in the hotel's context). */
    private static function fill(int $hotelId, string $name): void
    {
        Tenant::run($hotelId, static function () use ($hotelId, $name): void {
            Settings::set('hotel_name', $name);
            Settings::set('default_language', 'en');
            Settings::set('overlay_weather', '0');
            $s = self::seed([1 => 10, 2 => 10]);
            $rooms = $s['rooms'];
            $c = $s['content'];
            $c['pool'] = DB::insert('content_items', [
                'title' => 'Pool & spa timings', 'type' => 'announcement', 'duration' => 10,
                'body' => 'Swimming pool 7 AM – 9 PM · સ્વિમિંગ પૂલ સવારે 7 થી રાત્રે 9',
                'settings' => json_out(['subtitle' => 'Towels at the pool desk · Spa on request', 'style' => 'fullscreen', 'bg_color' => '#006064', 'text_color' => '#FFFFFF', 'font_size' => 50]),
                'created_at' => now(),
            ]);
            $c['checkout'] = DB::insert('content_items', [
                'title' => 'Checkout reminder (ticker)', 'type' => 'announcement', 'duration' => 15,
                'body' => 'Checkout time is 10:00 AM — late checkout on request at reception',
                'settings' => json_out(['style' => 'marquee', 'bg_color' => '#212121', 'text_color' => '#FFD54F', 'font_size' => 40]),
                'created_at' => now(),
            ]);
            $promo = DB::insert('content_playlists', ['name' => 'Restaurant & offers', 'description' => 'Suites loop', 'transition' => 'slide', 'created_at' => now()]);
            foreach (['offer', 'pool', 'timetable', 'clock'] as $i => $k) {
                DB::insert('playlist_items', ['playlist_id' => $promo, 'content_id' => $c[$k], 'sort_order' => $i]);
            }
            if (isset($s['groups']['vip'])) {
                DB::update('room_groups', ['playlist_id' => $promo], 'id = :id', ['id' => $s['groups']['vip']]);
            }
            Broadcaster::schedule([
                'title' => 'Breakfast offer (daily 07:00–10:30)', 'target_type' => 'all', 'target_ids' => [],
                'content_id' => $c['offer'], 'playlist_id' => null, 'mode' => 'window', 'start_at' => null, 'end_at' => null,
                'daily_start' => '07:00:00', 'daily_end' => '10:30:00', 'repeat_days' => null,
            ]);

            // Three simulated TVs, always online.
            $tvRooms = array_values(array_filter([$rooms['101'] ?? null, $rooms['102'] ?? null, $rooms['201'] ?? null]));
            $devices = [];
            foreach ($tvRooms as $i => $rid) {
                $devices[$rid] = DB::insert('devices', [
                    'device_uid' => self::FAKE_TV_PREFIX . $hotelId . '-' . ($i + 1),
                    'room_id' => $rid, 'token_hash' => hash('sha256', random_token(32)),
                    'app_version' => '2.0.0', 'app_version_code' => 200, 'android_version' => '11', 'model' => 'Demo TV (simulated)',
                    'ip_address' => '192.168.1.' . (21 + $i), 'network_type' => 'wifi', 'wifi_signal' => -52 - $i * 4,
                    'free_storage_mb' => 5120, 'screen_on' => 1, 'uptime_sec' => 36000, 'status' => 'online',
                    'last_ping' => now(), 'last_heartbeat' => now(), 'registered_at' => date('Y-m-d H:i:s', time() - 8 * 86400),
                ]);
            }
            self::analytics($devices, [$c['welcome'], $c['timetable'], $c['offer'], $c['clock'], $c['pool']]);
            self::modules($rooms, $c, array_keys($devices));
            Settings::bumpContentVersion();
        });
    }

    /** Sample analytics: 7 days of play logs, status transitions, usage samples. */
    private static function analytics(array $devices, array $contentIds): void
    {
        $hid = Tenant::id();
        $plays = [];
        $status = [];
        mt_srand($hid * 7919);
        foreach ($devices as $roomId => $devId) {
            for ($d = 7; $d >= 0; $d--) {
                $day = strtotime(date('Y-m-d', time() - $d * 86400));
                $on = $day + 7 * 3600 + mt_rand(0, 3600);
                $off = $day + 22 * 3600 + mt_rand(0, 5400);
                if ($d === 0) {
                    $off = null;
                    $on = min($on, time() - 600);
                }
                $status[] = [$devId, $roomId, 'online', date('Y-m-d H:i:s', $on)];
                if ($off !== null) {
                    $status[] = [$devId, $roomId, 'offline', date('Y-m-d H:i:s', $off)];
                }
                $end = $off ?? time();
                for ($t = $on; $t < $end && count($plays) < 5000; $t += mt_rand(900, 2400)) {
                    $plays[] = [$devId, $roomId, $contentIds[mt_rand(0, count($contentIds) - 1)], mt_rand(8, 20), date('Y-m-d H:i:s', $t)];
                }
            }
        }
        foreach (array_chunk($plays, 200) as $chunk) {
            $vals = [];
            $p = [];
            foreach ($chunk as $i => [$dev, $room, $cid, $dur, $at]) {
                $vals[] = '(' . (int) $hid . ", :d$i, :r$i, :c$i, 'played', :s$i, :t$i)";
                $p += ["d$i" => $dev, "r$i" => $room, "c$i" => $cid, "s$i" => $dur, "t$i" => $at];
            }
            DB::query('INSERT INTO broadcast_logs (hotel_id, device_id, room_id, content_id, event, duration_sec, created_at) VALUES ' . implode(',', $vals), $p);
        }
        foreach ($status as [$dev, $room, $st, $at]) {
            DB::insert('device_status_logs', ['device_id' => $dev, 'room_id' => $room, 'status' => $st, 'created_at' => $at]);
        }
        if (self::tableExists('tv_usage_daily')) {
            foreach ($devices as $devId) {
                for ($d = 7; $d >= 1; $d--) {
                    DB::insert('tv_usage_daily', [
                        'device_id' => $devId, 'day' => date('Y-m-d', time() - $d * 86400),
                        'online_min' => 840 + mt_rand(0, 120), 'screen_on_min' => 420 + mt_rand(0, 240), 'samples' => 180,
                        'last_sample_at' => date('Y-m-d 23:55:00', time() - $d * 86400),
                    ]);
                }
            }
        }
    }

    /** Guests, room service, ads — only when those modules' tables exist. Failures are logged, not fatal. */
    private static function modules(array $rooms, array $c, array $tvRooms): void
    {
        $hid = Tenant::id();
        if (self::tableExists('guest_stays') && class_exists('Guests')) {
            try {
                foreach ([['101', 'Mr.', 'Rajesh Shah', 'gu', 2], ['102', 'Mrs.', 'Priya Patel', 'en', 1], ['201', 'Mr.', 'Amit Mehta', 'hi', 3], ['205', 'Ms.', 'Neha Sharma', 'en', 2]] as [$num, $sal, $name, $lang, $nights]) {
                    if (isset($rooms[$num])) {
                        Guests::checkIn((int) $rooms[$num], [
                            'guest_name' => $name, 'salutation' => $sal, 'language' => $lang, 'phone' => '+91 98250 ' . sprintf('%05d', mt_rand(10000, 99999)),
                            'checkout_at' => date('Y-m-d', time() + $nights * 86400), 'wifi_password' => 'welcome' . $num,
                        ]);
                    }
                }
            } catch (Throwable $e) {
                Logger::error('Demo guests failed: ' . $e->getMessage());
            }
        }
        if (self::tableExists('guest_menu_categories') && class_exists('GuestServices')) {
            try {
                $menu = [
                    ['Breakfast', 'નાસ્તો', 'नाश्ता', [['Poha', 'પૌંઆ', 80, 'veg'], ['Masala dosa', 'મસાલા ઢોસા', 140, 'veg'], ['Omelette & toast', 'ઑમલેટ અને ટોસ્ટ', 120, 'egg']]],
                    ['Main course', 'ભોજન', 'भोजन', [['Gujarati thali', 'ગુજરાતી થાળી', 280, 'veg'], ['Paneer butter masala', 'પનીર બટર મસાલા', 240, 'veg'], ['Dal khichdi', 'દાળ ખીચડી', 180, 'veg']]],
                    ['Beverages', 'પીણાં', 'पेय', [['Masala chai', 'મસાલા ચા', 40, 'veg'], ['Fresh lime soda', 'ફ્રેશ લાઇમ સોડા', 70, 'veg'], ['Cold coffee', 'કોલ્ડ કૉફી', 110, 'veg']]],
                ];
                $firstItem = null;
                foreach ($menu as $ci => [$en, $gu, $hi, $items]) {
                    [$catId] = GuestServices::saveCategory(['name_en' => $en, 'name_gu' => $gu, 'name_hi' => $hi, 'sort_order' => ($ci + 1) * 10, 'is_active' => 1]);
                    foreach ($items as $ii => [$ien, $igu, $price, $food]) {
                        [$itemId] = GuestServices::saveItem(['category_id' => $catId, 'name_en' => $ien, 'name_gu' => $igu, 'price' => $price, 'food_type' => $food, 'sort_order' => $ii * 10, 'is_active' => 1]);
                        $firstItem ??= ['id' => $itemId, 'name' => $ien, 'price' => $price];
                    }
                }
                GuestServices::ensureRequestTypes();
                if ($firstItem && isset($rooms['101'])) {
                    foreach ([['101', 'new', 2], ['201', 'delivered', 1]] as [$num, $st, $qty]) {
                        $oid = DB::insert('guest_orders', ['room_id' => $rooms[$num], 'status' => $st, 'item_count' => $qty, 'total' => $qty * $firstItem['price'], 'created_at' => date('Y-m-d H:i:s', time() - ($st === 'new' ? 300 : 7200))]);
                        DB::insert('guest_order_items', ['order_id' => $oid, 'item_id' => $firstItem['id'], 'name' => $firstItem['name'], 'price' => $firstItem['price'], 'qty' => $qty, 'line_total' => $qty * $firstItem['price']]);
                    }
                    DB::insert('guest_requests', ['room_id' => $rooms['102'], 'type_name' => 'Extra towels', 'status' => 'open', 'created_at' => date('Y-m-d H:i:s', time() - 600)]);
                    DB::insert('guest_feedback', ['room_id' => $rooms['201'], 'rating' => 5, 'rating_cleanliness' => 5, 'rating_staff' => 5, 'rating_food' => 4, 'comment' => 'Lovely stay, great thali!', 'token_hash' => hash('sha256', random_token(8)), 'created_at' => date('Y-m-d H:i:s', time() - 86400)]);
                }
            } catch (Throwable $e) {
                Logger::error('Demo services failed: ' . $e->getMessage());
            }
        }
        if (self::tableExists('sponsors') && self::tableExists('ad_campaigns')) {
            try {
                $adContent = DB::insert('content_items', [
                    'title' => 'Ad: Dwarka Sweets', 'type' => 'announcement', 'duration' => 8,
                    'body' => 'Dwarka Sweets — fresh pedas & farsan · 10% off with your room key',
                    'settings' => json_out(['subtitle' => 'Near Jagat Mandir · Open 8 AM – 10 PM', 'style' => 'fullscreen', 'bg_color' => '#E65100', 'text_color' => '#FFFFFF', 'font_size' => 50]),
                    'created_at' => now(),
                ]);
                $sid = DB::insert('sponsors', ['name' => 'Dwarka Sweets', 'contact_name' => 'Mr. Joshi', 'phone' => '+91 98790 00000', 'contract_start' => date('Y-m-d', time() - 30 * 86400), 'contract_end' => date('Y-m-d', time() + 60 * 86400), 'created_at' => now()]);
                $camp = DB::insert('ad_campaigns', [
                    'sponsor_id' => $sid, 'name' => 'Sweets — every 3rd item', 'content_id' => $adContent,
                    'start_date' => date('Y-m-d', time() - 14 * 86400), 'end_date' => null, 'target_type' => 'all', 'target_ids' => '[]',
                    'freq_items' => 3, 'freq_minutes' => 10, 'priority' => 0, 'status' => 'active', 'created_at' => now(),
                ]);
                if (self::tableExists('ad_stats_daily')) {
                    foreach ($tvRooms as $roomId) {
                        for ($d = 7; $d >= 1; $d--) {
                            DB::insert('ad_stats_daily', ['campaign_id' => $camp, 'day' => date('Y-m-d', time() - $d * 86400), 'room_id' => $roomId, 'impressions' => 20 + mt_rand(0, 25), 'seconds' => 200 + mt_rand(0, 200)]);
                        }
                    }
                }
            } catch (Throwable $e) {
                Logger::error('Demo ads failed: ' . $e->getMessage());
            }
        }
    }

    /** Base settings of a hotel after a purge (same as Hotels::create). */
    private static function baseSettings(int $hotelId, string $name): void
    {
        $key = (string) DB::value('SELECT registration_key FROM hotels WHERE id = :id', ['id' => $hotelId]) ?: Hotels::newRegistrationKey();
        DB::query('UPDATE hotels SET registration_key = :k WHERE id = :id', ['k' => $key, 'id' => $hotelId]);
        $tz = (string) Config::get('timezone', 'Asia/Kolkata');
        foreach (['hotel_name' => $name, 'registration_key' => $key, 'timezone' => $tz, 'content_version' => (string) (time() % 1000000)] as $k => $v) {
            DB::query('INSERT INTO system_settings (hotel_id, setting_key, setting_value) VALUES (:h, :k, :v) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', ['h' => $hotelId, 'k' => $k, 'v' => $v]);
        }
        Settings::flush();
    }

    private static function createUser(int $hotelId, string $role, string $username, ?string $email, string $fullName, ?string $password = null): int
    {
        return DB::insert('users', [
            'hotel_id' => $hotelId, 'username' => $username, 'email' => $email ?? ($username . '@demo.invalid'),
            'full_name' => $fullName, 'password_hash' => Auth::hash($password ?? random_token(24)),
            'role' => $role, 'language' => 'en', 'is_active' => 1, 'created_at' => now(),
        ]);
    }

    /** The read-only login of the public demo hotel. */
    public static function demoUser(int $hotelId): ?array
    {
        return DB::one("SELECT * FROM users WHERE hotel_id = :h AND role IN ('manager','staff','reception') AND is_active = 1 ORDER BY id LIMIT 1", ['h' => $hotelId]);
    }

    /** Readable random password (12 chars, letters + digits). */
    public static function password(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $p = '';
        for ($i = 0; $i < 9; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)];
        }
        for ($i = 0; $i < 3; $i++) {
            $p .= $digits[random_int(0, strlen($digits) - 1)];
        }
        return $p;
    }

    public static function tableExists(string $table): bool
    {
        if (!array_key_exists($table, self::$tables)) {
            self::$tables[$table] = (bool) DB::value(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                ['t' => $table]
            );
        }
        return self::$tables[$table];
    }

    /** Room list of the public demo hotel for demo.php (number, name, has TV). */
    public static function publicRooms(int $hotelId): array
    {
        return DB::all(
            'SELECT r.id, r.room_number, r.name, r.floor,
                    (SELECT COUNT(*) FROM devices d WHERE d.room_id = r.id AND d.hotel_id = r.hotel_id AND d.is_revoked = 0) AS tvs
             FROM rooms r WHERE r.hotel_id = :h AND r.is_enabled = 1 ORDER BY r.floor, r.room_number',
            ['h' => $hotelId]
        );
    }
}
