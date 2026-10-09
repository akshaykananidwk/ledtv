<?php
declare(strict_types=1);

/**
 * Public landing page at the site root (index.php, 2.6.1).
 *
 * Everything that changes per installation comes from the platform:
 *   - name / logo / colour / support phone + e-mail / footer: Branding::get(0) (white-label);
 *   - "Start free trial": only when Signup::enabled() (SaaS mode + Platform → Sign-ups → online sign-up on);
 *   - "Live demo": only when Demo::publicEnabled() (SaaS mode + Platform → Demo → public demo on);
 *   - plans: the ACTIVE rows of the plans table (name, description, price per screen / month, limits,
 *     included modules via Features::planKeys()) — never hard-coded; no active plan → "contact us" card;
 *   - live numbers: aggregate counts only (screens, businesses — demo and archived customers excluded),
 *     cached 10 minutes and shown only from STATS_MIN upwards. No customer name is ever read here.
 *
 * Texts are English keys translated with __() (lang/gu_landing.php, lang/hi_landing.php). Language:
 * ?lang= (remembered in the hc_lang cookie) → cookie → browser Accept-Language → platform default → en.
 */
final class Landing
{
    public const COOKIE = 'hc_lang';
    /** Live numbers are shown only when both counts reach this (a new platform shows no "2 screens"). */
    public const STATS_MIN = 10;
    public const STATS_TTL = 600;

    // ------------------------------------------------------------------ language

    /** Pick the page language and remember an explicit ?lang= choice in a cookie. */
    public static function language(array $get, array $cookie, string $acceptLanguage): string
    {
        $langs = I18n::LANGUAGES;
        $q = $get['lang'] ?? null;
        if (is_string($q) && isset($langs[$q])) {
            if (PHP_SAPI !== 'cli' && !headers_sent() && ($cookie[self::COOKIE] ?? null) !== $q) {
                setcookie(self::COOKIE, $q, [
                    'expires' => time() + 365 * 86400,
                    'path' => parse_url(base_url(), PHP_URL_PATH) ?: '/',
                    'secure' => is_https(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            return $q;
        }
        $c = $cookie[self::COOKIE] ?? null;
        if (is_string($c) && isset($langs[$c])) {
            return $c;
        }
        $fromBrowser = self::fromAcceptLanguage($acceptLanguage);
        if ($fromBrowser !== null) {
            return $fromBrowser;
        }
        $d = (string) Settings::platform('default_language', 'en');
        return isset($langs[$d]) ? $d : 'en';
    }

    /** Best supported language of an Accept-Language header (q-values respected), null when none. */
    public static function fromAcceptLanguage(string $header): ?string
    {
        $best = null;
        $bestQ = 0.0;
        foreach (explode(',', substr($header, 0, 500)) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(substr(trim($bits[0]), 0, 2));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $p) {
                if (preg_match('/^\s*q=([0-9.]+)/', $p, $m)) {
                    $q = (float) $m[1];
                }
            }
            $q -= $i * 0.0001; // keep header order for equal q
            if (isset(I18n::LANGUAGES[$tag]) && $q > $bestQ) {
                $best = $tag;
                $bestQ = $q;
            }
        }
        return $best;
    }

    // ------------------------------------------------------------------ data from the platform

    /**
     * Active plans for the pricing cards: ['name', 'description', 'price', 'max_tvs', 'max_users',
     * 'storage_mb', 'all' (every module), 'features' / 'missing' (included / left-out module labels, translated)].
     */
    public static function plans(): array
    {
        try {
            $rows = DB::all('SELECT id, name, description, price_per_tv_month, max_tvs, max_users, storage_mb, features
                             FROM plans WHERE is_active = 1 ORDER BY price_per_tv_month, name LIMIT 12');
        } catch (Throwable) {
            return [];
        }
        $optional = Features::optionalKeys();
        $out = [];
        foreach ($rows as $r) {
            $keys = Features::planKeys($r['features']);
            $all = count(array_diff($optional, $keys)) === 0;
            $labels = [];
            foreach ($keys as $k) {
                $labels[] = Features::label($k);
            }
            $missing = [];
            foreach (array_diff($optional, $keys) as $k) {
                $missing[] = Features::label($k);
            }
            $out[] = [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'description' => trim((string) ($r['description'] ?? '')),
                'price' => (float) $r['price_per_tv_month'],
                'max_tvs' => $r['max_tvs'] !== null ? (int) $r['max_tvs'] : null,
                'max_users' => $r['max_users'] !== null ? (int) $r['max_users'] : null,
                'storage_mb' => $r['storage_mb'] !== null ? (int) $r['storage_mb'] : null,
                'all' => $all,
                'count' => count($keys),
                'features' => $labels,
                'missing' => $missing,
            ];
        }
        return $out;
    }

    /**
     * Aggregate live numbers ['screens' => int, 'customers' => int] or null (below STATS_MIN / error).
     * Only counts — no customer names, no per-customer numbers.
     */
    public static function stats(): ?array
    {
        $s = Cache::remember('landing', 'stats', self::STATS_TTL, static function (): ?array {
            try {
                $where = "h.demo_kind IS NULL AND h.archived_at IS NULL AND h.status = 'active'";
                return [
                    'screens' => (int) DB::value("SELECT COUNT(*) FROM devices d JOIN hotels h ON h.id = d.hotel_id WHERE $where"),
                    'customers' => (int) DB::value("SELECT COUNT(*) FROM hotels h WHERE $where"),
                ];
            } catch (Throwable) {
                return null;
            }
        });
        if (!is_array($s) || ($s['screens'] ?? 0) < self::STATS_MIN || ($s['customers'] ?? 0) < self::STATS_MIN) {
            return null;
        }
        return $s;
    }

    /** Number of ready-made display apps (from the feature registry, so the page never over-claims). */
    public static function appCount(): int
    {
        $n = 0;
        foreach (Features::all() as $d) {
            $n += count($d['apps']);
        }
        return $n;
    }

    /** Round a live number down for display ("120+"). */
    public static function roundDown(int $n): string
    {
        if ($n >= 1000) {
            return number_format((int) (floor($n / 100) * 100)) . '+';
        }
        if ($n >= 100) {
            return (string) ((int) (floor($n / 50) * 50)) . '+';
        }
        return (string) ((int) (floor($n / 10) * 10)) . '+';
    }

    /** tel: and wa.me links of the support phone. WhatsApp only for numbers in international format (+…). */
    public static function phoneLinks(string $phone): array
    {
        $phone = trim($phone);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($phone === '' || strlen($digits) < 6) {
            return ['tel' => null, 'whatsapp' => null];
        }
        $tel = 'tel:' . (str_starts_with($phone, '+') ? '+' : '') . $digits;
        $wa = str_starts_with($phone, '+') && strlen($digits) >= 10 ? 'https://wa.me/' . $digits : null;
        return ['tel' => $tel, 'whatsapp' => $wa];
    }

    // ------------------------------------------------------------------ static content (English keys)

    /** Who is it for: [icon, colour class, title, [examples]]. Every example maps to an existing module. */
    public const USE_CASES = [
        ['bi-brightness-alt-high', 'c-saffron', 'Temples & religious places', [
            'Live aarti and darshan stream on every screen',
            'Darshan and aarti timings with the current one highlighted',
            'Daily panchang and choghadiya (works offline)',
            'Festival countdown and a UPI QR code for donations',
        ]],
        ['bi-building', 'c-violet', 'Hotels', [
            'Welcome screen with the guest\'s name',
            'Room service and requests from the guest\'s phone',
            'Restaurant menu, local guide and hotel services',
            'Check-out reminder and guest feedback',
        ]],
        ['bi-hospital', 'c-teal', 'Hospitals & clinics', [
            'Token queue display with chime and voice call',
            'OPD and doctor timings',
            'Health awareness videos in the waiting area',
            'Emergency alarm with siren on all screens',
        ]],
        ['bi-mortarboard', 'c-blue', 'Schools & coaching classes', [
            'Class timetables and batch schedules',
            'Notice board with a chime for new notices',
            'Birthday wall and photo album of events',
            'School bell and spoken announcements at set times',
        ]],
        ['bi-cup-hot', 'c-red', 'Restaurants & cafés', [
            'Menu board with veg / non-veg marks and a sold-out switch',
            'Breakfast, lunch and dinner menus change by themselves',
            'Today\'s special and combo offers with a countdown',
            'Google reviews and a payment QR code',
        ]],
        ['bi-shop', 'c-pink', 'Shops & showrooms', [
            'Product videos and promotions',
            'Live gold and silver rates for jewellers',
            'Offers with a countdown timer',
            'Instagram / Facebook wall and property showcase',
        ]],
        ['bi-buildings', 'c-green', 'Offices & factories', [
            'Live KPI and production dashboard',
            'Safety notices and emergency alarm',
            'Google Sheet shown as a live table',
            'Birthdays, anniversaries and visitor welcome',
        ]],
        ['bi-tree', 'c-amber', 'Hostels & resorts', [
            'Mess menu and meal timings',
            'Notices and event announcements',
            'Photo album of events and celebrations',
            'Wi-Fi QR code and weather / air-quality alerts',
        ]],
    ];

    /** Feature sections: [id, icon, colour class, title, intro, [[icon, title, text], …]]. */
    public const FEATURE_GROUPS = [
        ['screens', 'bi-tv', 'c-blue', 'Screens & TVs', 'Every TV of every branch in one list — online status, what it shows, and full remote control.', [
            ['bi-qr-code-scan', 'QR pairing', 'The TV shows a QR code; scan it with your phone and choose the screen. No typing with the TV remote.'],
            ['bi-joystick', 'Remote control', 'Restart, clear cache, screen on / off, volume and input — from anywhere.'],
            ['bi-power', 'Power schedules', 'Screens switch off at night and on in the morning by themselves. Save electricity.'],
            ['bi-heart-pulse', 'TV health', 'Warnings for low storage, memory, temperature and weak Wi-Fi before something fails.'],
            ['bi-camera', 'Screenshots & live view', 'See exactly what a screen is showing right now.'],
            ['bi-cloud-arrow-down', 'Auto-update', 'New TV app versions are installed silently — no visit needed.'],
            ['bi-diagram-3', 'Groups & areas', 'Organise screens by floor, area or zone; send to one screen, a group or all.'],
        ]],
        ['content', 'bi-collection-play', 'c-pink', 'Content', 'Show anything — from a single photo to a live stream — and change it in seconds.', [
            ['bi-images', 'Images & videos', 'Upload photos and videos; they are resized and compressed for the TV automatically.'],
            ['bi-youtube', 'YouTube', 'YouTube videos, playlists and channels.'],
            ['bi-broadcast', 'Live streams', 'Live darshan, aarti or events (HLS / RTSP / DASH) with automatic reconnect.'],
            ['bi-globe2', 'Web pages', 'Any website or dashboard, full screen.'],
            ['bi-list-ol', 'Playlists', 'Drag-and-drop playlists that loop all day.'],
            ['bi-layout-split', 'Split screen', 'Divide the screen into up to 6 zones — video, menu, ticker and clock together.'],
            ['bi-palette', 'Slide designer', 'Design slides with ready-made templates, or turn a PDF into slides.'],
            ['bi-text-paragraph', 'Ticker bar', 'Scrolling text at the top or bottom — per screen, group or all.'],
        ]],
        ['schedule', 'bi-calendar-week', 'c-green', 'Scheduling', 'Set it once — the right content plays at the right time on the right screen.', [
            ['bi-clock-history', 'Dayparting', 'Morning, afternoon and evening content with time windows and weekdays.'],
            ['bi-calendar3', 'Drag-and-drop calendar', 'See and move everything that is scheduled.'],
            ['bi-hourglass-split', 'Start and expiry dates', 'Offers and notices appear and disappear on their own.'],
            ['bi-calendar-x', 'Holiday calendar', 'Switch screens off or show special content on holidays.'],
            ['bi-check2-square', 'Content approval', 'Staff content waits for a manager\'s approval.'],
            ['bi-sliders', 'Device schedules', 'Timed volume, input, restart and bell.'],
        ]],
        ['emergency', 'bi-megaphone', 'c-red', 'Emergency & announcements', 'Reach every screen in one click when it matters most.', [
            ['bi-exclamation-octagon', 'Emergency broadcast', 'A full-screen message on all or chosen screens instantly.'],
            ['bi-bell', 'Alarm sound', 'Emergency beep, siren or fire alarm that keeps ringing until you stop it — even on a muted TV.'],
            ['bi-music-note-beamed', 'Notice sound', 'A chime when a message or notice appears.'],
            ['bi-mic', 'Spoken announcements', 'Type a text — the TV speaks it (text-to-speech), now or at a set time.'],
            ['bi-send', 'Push now', 'Send any content to screens immediately.'],
        ]],
        ['walls', 'bi-grid-3x3', 'c-violet', 'Video walls & sync', 'Make a big impression with many screens.', [
            ['bi-grid-3x3-gap', 'Video wall', 'Up to 4×4 screens show one big picture.'],
            ['bi-arrow-repeat', 'Synchronized playback', 'Playlists play in sync on several screens.'],
        ]],
        ['reports', 'bi-graph-up-arrow', 'c-teal', 'Analytics & proof of play', 'Know what played, where and for how long.', [
            ['bi-clipboard-data', 'Proof of play', 'What played on which screen and how often — CSV and print.'],
            ['bi-bar-chart', 'Analytics', 'Screen hours, uptime, electricity use and content statistics.'],
            ['bi-badge-ad', 'Ads & sponsors', 'Sponsor campaigns inside playlists with impression reports.'],
            ['bi-shop-window', 'Ad marketplace', 'Local businesses book ads on your screens; you earn a share.'],
        ]],
        ['team', 'bi-people', 'c-amber', 'Teams & roles', 'Everyone gets exactly the access they need.', [
            ['bi-person-badge', 'Ready-made roles', 'Admin, Manager, Staff and Reception.'],
            ['bi-person-gear', 'Custom roles', 'Make your own roles with chosen permissions.'],
            ['bi-person-lock', 'Per-user screen access', 'Limit a user to some screens or groups.'],
            ['bi-phone', 'Mobile app & push alerts', 'Install the admin panel on your phone and get alerts.'],
        ]],
    ];

    /** Device / platform bullets ("Works on"). */
    public const WORKS_ON = [
        ['bi-android2', 'Android TV & boxes', 'Smart TVs with Android, Android boxes (Android 5.0 and newer). Starts by itself when the TV is switched on.'],
        ['bi-fire', 'Fire TV Stick', 'The TV app installs on Fire TV Stick and Cube — turns any HDMI TV into a smart screen.'],
        ['bi-display', 'Any browser', 'Web player for Smart-TV browsers, PCs and Raspberry Pi.'],
        ['bi-tv', 'Older TVs too', 'Built-in security certificates and clock fix, so QR setup works even on old TVs.'],
        ['bi-wifi-off', 'Keeps playing offline', 'Content is saved on the TV and keeps playing without internet; USB drive mode as well.'],
        ['bi-hdmi', 'HDMI-CEC', 'Android boxes switch the TV on and off over HDMI.'],
    ];

    /** How it works steps: [icon, title, text]. */
    public const STEPS = [
        ['bi-download', 'Install the TV app', 'Install the app on the Android TV or box — or open the web player on any screen.'],
        ['bi-qr-code-scan', 'Scan the QR code', 'The TV shows a QR code. Scan it with your phone and choose the screen.'],
        ['bi-cloud-upload', 'Add your content', 'Upload photos and videos, pick a display app or a ready-made template.'],
        ['bi-calendar-check', 'Schedule', 'Choose which screens show what, and when.'],
        ['bi-activity', 'Relax and monitor', 'See every screen\'s status, screenshots and reports from your phone.'],
    ];

    /** Display apps shown as chips: [icon, label]. Each one is an app in core/Apps. */
    public const APPS = [
        ['bi-journal-richtext', 'Menu board'], ['bi-123', 'Token queue'], ['bi-pin-angle', 'Notice board'],
        ['bi-tags', 'Offers'], ['bi-table', 'Class timetable'], ['bi-signpost-split', 'Bus / train departures'],
        ['bi-speedometer2', 'KPI dashboard'], ['bi-house-door', 'Property showcase'], ['bi-balloon-heart', 'Event / wedding welcome'],
        ['bi-images', 'Photo album'], ['bi-file-earmark-spreadsheet', 'Google Sheet table'], ['bi-instagram', 'Social wall'],
        ['bi-gem', 'Gold & silver rates'], ['bi-graph-up', 'Stock market'], ['bi-trophy', 'Cricket score'],
        ['bi-currency-exchange', 'Currency rates'], ['bi-airplane', 'Train / flight status'], ['bi-cloud-sun', 'Air quality & weather alerts'],
        ['bi-moon-stars', 'Panchang & choghadiya'], ['bi-stars', 'Festival countdown'], ['bi-cake2', 'Birthday wall'],
        ['bi-star-half', 'Google reviews'], ['bi-stopwatch', 'Countdown'], ['bi-qr-code', 'QR code (UPI / WhatsApp / Wi-Fi)'],
    ];
}
