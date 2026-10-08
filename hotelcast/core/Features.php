<?php
declare(strict_types=1);

/**
 * Plans & feature entitlements (docs/modules/plans_features.md) — the ONE place that answers
 * "is this customer entitled to X?". Code asks for capabilities (feature keys), never for plan names:
 * adding a plan is data (platform → Plans), not code.
 *
 * Entitlements are separate from RBAC: a request must pass both (Auth::can() for the user's role,
 * Features for the customer's plan; Auth::can() asks Features::permissionEnabled()).
 *
 * Effective features of a customer (hotel / tenant):
 *   plan.features      JSON array of feature keys; NULL or [] = EVERYTHING (back-compat). A list of only
 *                      the old 2.0 module names (guests, services, ads, analytics, templates, pwa, support)
 *                      or unknown names is a "legacy" list: everything except the modules it leaves out.
 *                      Lists written by the plan editor always contain the core keys (format marker).
 *   + hotels.feature_overrides {"add": [...], "remove": [...]} set by the Super Admin per customer
 *   → then `depends`: a feature is only on when every feature it depends on is on.
 *   Core features (dashboard, screens, users, settings …) are always on and cannot be removed.
 *
 * Limits (null = unlimited): max_screens (hotels.max_tvs ?? plans.max_tvs, and the license),
 * max_users (hotels.max_users ?? plans.max_users), storage_mb (hotels.storage_mb ?? plans.storage_mb).
 *
 * Enforcement (server side, central):
 *   - admin pages + admin/ajax.php actions: guardAdminRequest() (called by admin/partials/common.php);
 *   - REST API module routes: guardApiRoute() in api/index.php (checked when the route sets its tenant);
 *   - TV content: ContentResolver skips extensions of disabled features and filterContent() strips
 *     fields; ContentRules::playable() skips items of disabled display-app families / layouts;
 *   - display/ pages of a disabled app family: neutral "not available" page.
 * The platform admin always has everything (pages open, with a banner); the navigation inside an
 * entered customer shows that customer's view.
 */
final class Features
{
    /** Group key => English label (translated with __() when shown). */
    public const GROUPS = [
        'core' => 'Always included',
        'content' => 'Content',
        'display_apps' => 'Display apps',
        'scheduling' => 'Scheduling',
        'devices' => 'Devices',
        'reports' => 'Reports & marketing',
        'hospitality' => 'Hospitality',
        'advanced' => 'Advanced',
    ];

    /**
     * Admin pages of the platform / resellers / hotel chains (not customer modules): never gated by a plan.
     * Files matching this pattern need no registry entry (registry crawler test).
     */
    public const PLATFORM_PAGE_PATTERN = '/^(platform_[a-z0-9_]+|chain(_[a-z0-9_]+)?|reseller)\.php$/';

    /** Admin entry points that dispatch to registered actions (admin/ajax.php → ajax actions). */
    public const DISPATCHERS = ['ajax.php'];

    /**
     * Content extensions that are not skipped as a whole: their fields are stripped per feature by
     * filterContent() (usb_mode → usb_mode, cec_mode → cec).
     */
    public const FIELD_EXTENSIONS = ['DeviceFeaturesExtension'];

    /** Old 2.0 module names (plans.features before 2.5, Tenant::feature()) => new feature keys. */
    public const LEGACY_MODULES = [
        'guests' => ['guests', 'pms'],
        'services' => ['room_service', 'feedback'],
        'ads' => ['ads', 'marketplace'],
        'analytics' => ['analytics'],
        'templates' => ['templates', 'guide'],
        'pwa' => ['pwa_push'],
        'support' => ['support', 'live_view'],
    ];

    /** Tenant::feature($module) wrapper: old module name => feature key. */
    public const LEGACY_WRAPPER = [
        'guests' => 'guests', 'services' => 'room_service', 'ads' => 'ads', 'analytics' => 'analytics',
        'templates' => 'templates', 'pwa' => 'pwa_push', 'support' => 'support',
    ];

    /**
     * Ready-made plans (seeded by migrations/028_plans_features.php when no plan of that name exists, and
     * offered as presets in the plan editor). features null = everything.
     */
    public const PRESETS = [
        'Basic' => [
            'description' => 'Content, playlists, ticker, schedules, TV power, emergency',
            'price' => 99, 'max_users' => 1,
            'features' => ['content', 'playlists', 'tickers', 'schedule', 'power_schedules', 'broadcast_emergency', 'emergency_alarm'],
        ],
        'Business' => [
            'description' => 'Basic + display apps, split screen, slide designer, PDF import, device schedules, reports',
            'price' => 149, 'max_users' => 5,
            'features' => ['content', 'playlists', 'tickers', 'schedule', 'power_schedules', 'broadcast_emergency', 'emergency_alarm',
                'apps', 'app_menu_board', 'app_queue', 'app_notice_board', 'business_apps', 'content_apps', 'data_feeds', 'widgets_26_30',
                'layouts', 'designer', 'pdf_import', 'templates', 'device_schedules', 'play_report', 'analytics'],
        ],
        'Pro' => [
            'description' => 'Everything except hospitality and the ad marketplace',
            'price' => 199, 'max_users' => null,
            'except' => ['hospitality', 'marketplace'],
        ],
        'Hospitality' => [
            'description' => 'Everything, including guests, room service, feedback, PMS and the local guide',
            'price' => 249, 'max_users' => null,
            'features' => null,
        ],
    ];

    /**
     * The registry. Every module of the product has a key:
     *   label, group, description   (English, translated with __())
     *   permissions  Auth permissions belonging to it (Auth::can() is false when no owning feature is on)
     *   pages        admin/*.php basenames            ajax  admin ajax actions ("prefix_*" = every action of a prefix)
     *   api          REST route prefixes ("pms" = pms and pms/…), 403 FEATURE_DISABLED centrally
 *   api_self     REST route prefixes whose handler checks the feature itself     apps  display-app keys
     *   extensions   core/Extensions classes (skipped on TVs when off)  widgets  admin/partials/dashboard.d files
     *   depends      feature keys that must be on as well        core  true = always on, cannot be removed
     */
    private const REGISTRY = [
        // ------------------------------------------------------------------ always included
        'dashboard' => ['label' => 'Dashboard', 'group' => 'core', 'core' => true, 'description' => 'Live overview of screens, activity and the getting-started checklist.',
            'permissions' => ['dashboard.view'], 'pages' => ['index.php', 'getting_started.php'], 'ajax' => ['dashboard_stats', 'signup_checklist'],
            'widgets' => ['50_plan_usage.php', '70_signups.php']],
        'screens' => ['label' => 'Screens & TVs', 'group' => 'core', 'core' => true, 'description' => 'Screens (rooms), TV registration, QR setup and remote commands.',
            'permissions' => ['rooms.view', 'rooms.manage', 'broadcast.device_commands', 'devices.setup'],
            'pages' => ['rooms.php', 'claim.php', 'setup_file.php'], 'ajax' => ['room_status', 'send_command', 'claim_*'],
            'api' => ['', 'health', 'device', 'content', 'provision', 'license']],
        'groups' => ['label' => 'Groups', 'group' => 'core', 'core' => true, 'description' => 'Floor / zone groups of screens.',
            'permissions' => ['groups.manage'], 'pages' => ['groups.php']],
        'broadcast' => ['label' => 'Push content now', 'group' => 'core', 'core' => true, 'description' => 'Send content to screens immediately.',
            'permissions' => ['broadcast.send'], 'pages' => ['broadcast.php']],
        'users' => ['label' => 'Users', 'group' => 'core', 'core' => true, 'description' => 'Users of the customer with the built-in roles.',
            'permissions' => ['users.manage'], 'pages' => ['users.php']],
        'profile' => ['label' => 'Profile & login', 'group' => 'core', 'core' => true, 'description' => 'Own profile, password, language and sign-in.',
            'pages' => ['profile.php', 'login.php', 'logout.php', 'manifest.php', 'pwa_icon.php'], 'ajax' => ['set_language']],
        'settings' => ['label' => 'Settings & billing', 'group' => 'core', 'core' => true, 'description' => 'Customer settings, your plan, invoices.',
            'permissions' => ['settings.manage', 'billing.view'], 'pages' => ['settings.php', 'plan.php', 'billing.php', 'invoice.php']],
        'logs' => ['label' => 'Logs & history', 'group' => 'core', 'core' => true, 'description' => 'Activity and broadcast history.',
            'permissions' => ['logs.view'], 'pages' => ['logs.php']],
        'update' => ['label' => 'Auto-update', 'group' => 'core', 'core' => true, 'description' => 'Server updates (platform only).',
            'permissions' => ['update.manage'], 'pages' => ['update.php', 'ajax_update.php'], 'ajax' => ['update_*', 'rollback']],
        'platform' => ['label' => 'Platform', 'group' => 'core', 'core' => true, 'description' => 'Platform, reseller and hotel-chain administration.',
            'permissions' => ['platform.manage', 'platform.hotels', 'reseller.panel', 'support.platform', 'signup.manage', 'demo.client', 'chains.manage', 'chain.view', 'platform.screens', 'platform.pool'],
            'ajax' => ['platform_*', 'chain_*', 'signup_stats']],

        // ------------------------------------------------------------------ content
        'content' => ['label' => 'Content library', 'group' => 'content', 'description' => 'Images, videos, streams, web pages, announcements, timetables and previews.',
            'permissions' => ['content.view', 'content.manage', 'content.submit'], 'pages' => ['content.php', 'preview.php'], 'ajax' => ['preview_content']],
        'playlists' => ['label' => 'Playlists', 'group' => 'content', 'description' => 'Drag-and-drop playlists with time windows (dayparting).',
            'permissions' => ['playlists.manage'], 'pages' => ['playlists.php'], 'depends' => ['content']],
        'designer' => ['label' => 'Slide designer', 'group' => 'content', 'description' => 'Drag-and-drop slide designer with ready-made templates.',
            'pages' => ['designer.php'], 'ajax' => ['designer_save', 'designer_image', 'designer_template', 'designer_tpldelete'], 'depends' => ['content']],
        'pdf_import' => ['label' => 'PDF import', 'group' => 'content', 'description' => 'Turn a PDF or presentation into slides and a playlist.',
            'pages' => ['pdf_import.php'], 'ajax' => ['designer_pdfpage', 'designer_pdfplaylist'], 'depends' => ['content']],
        'layouts' => ['label' => 'Split screen layouts', 'group' => 'content', 'description' => 'Divide the screen into up to 6 zones.',
            'depends' => ['content']],
        'tickers' => ['label' => 'Ticker bar', 'group' => 'content', 'description' => 'Scrolling text bar per screen, group or all.',
            'permissions' => ['tickers.manage'], 'pages' => ['tickers.php'], 'extensions' => ['TickerExtension']],
        'templates' => ['label' => 'Template library', 'group' => 'content', 'description' => 'Ready-made content templates.',
            'permissions' => ['templates.manage'], 'pages' => ['templates.php'], 'ajax' => ['tpl_*'], 'depends' => ['content']],

        // ------------------------------------------------------------------ display apps
        'apps' => ['label' => 'Display apps', 'group' => 'display_apps', 'description' => 'App gallery with themes; countdown and QR code apps.',
            'pages' => ['apps.php'], 'apps' => ['countdown', 'qr'], 'depends' => ['content']],
        'app_menu_board' => ['label' => 'Menu board', 'group' => 'display_apps', 'description' => 'Restaurant menu board with sold-out switch and dayparting.',
            'permissions' => ['menu_board.manage'], 'pages' => ['menu_board.php'], 'apps' => ['menu_board'], 'depends' => ['apps']],
        'app_queue' => ['label' => 'Token queue', 'group' => 'display_apps', 'description' => 'Token / queue display with calling, tickets and phone self-service.',
            'permissions' => ['queue.operate', 'queue.manage'], 'pages' => ['queue.php', 'queue_issue.php'], 'apps' => ['queue_display'], 'depends' => ['apps']],
        'app_notice_board' => ['label' => 'Notice board', 'group' => 'display_apps', 'description' => 'School / office notice board.',
            'permissions' => ['notices.manage'], 'pages' => ['notices.php'], 'apps' => ['notice_board'], 'depends' => ['apps']],
        'business_apps' => ['label' => 'Business apps', 'group' => 'display_apps', 'description' => 'Offers, class schedule, departures, KPI dashboard, showcase, event welcome.',
            'permissions' => ['offers.manage', 'class_schedule.manage', 'departures.manage', 'kpi.manage'],
            'pages' => ['offers.php', 'class_schedule.php', 'departures.php', 'kpi.php'],
            'apps' => ['offers', 'class_schedule', 'departures', 'kpi_dashboard', 'showcase', 'event_welcome'], 'depends' => ['apps']],
        'content_apps' => ['label' => 'Content apps', 'group' => 'display_apps', 'description' => 'Photo albums, Google Sheet table, social wall.',
            'permissions' => ['albums.manage'], 'pages' => ['album_upload.php'], 'apps' => ['photo_album', 'sheet_table', 'social_wall'], 'depends' => ['apps']],
        'data_feeds' => ['label' => 'Live data feeds', 'group' => 'display_apps', 'description' => 'Gold rates, market, cricket, currency, travel status and ticker placeholders.',
            'permissions' => ['rates.manage'], 'pages' => ['rates.php', 'data_feeds.php'],
            'apps' => ['gold_rates', 'market', 'cricket', 'currency', 'travel_status'], 'depends' => ['apps']],
        'widgets_26_30' => ['label' => 'Live widgets', 'group' => 'display_apps', 'description' => 'Air quality, panchang, festivals, birthdays and Google reviews.',
            'permissions' => ['festivals.manage', 'celebrations.manage'], 'pages' => ['festivals.php', 'celebrations.php'],
            'apps' => ['air_quality', 'panchang', 'festivals', 'celebrations', 'reviews'], 'depends' => ['apps']],

        // ------------------------------------------------------------------ scheduling & broadcast
        'schedule' => ['label' => 'Schedules & calendar', 'group' => 'scheduling', 'description' => 'Schedule content by date, time and weekday; drag-and-drop calendar.',
            'permissions' => ['schedule.manage'], 'pages' => ['schedule.php', 'calendar.php'], 'ajax' => ['schedule_events', 'calendar_*']],
        'approvals' => ['label' => 'Content approval', 'group' => 'scheduling', 'description' => 'Staff content waits for a manager\'s approval.',
            'permissions' => ['content.approve', 'content.submit'], 'pages' => ['approvals.php'], 'depends' => ['content']],
        'holidays' => ['label' => 'Holiday calendar', 'group' => 'scheduling', 'description' => 'Switch screens off or show special content on holidays.',
            'permissions' => ['holidays.manage'], 'pages' => ['holidays.php']],
        'broadcast_emergency' => ['label' => 'Emergency broadcast', 'group' => 'scheduling', 'description' => 'Full-screen emergency message on all or some screens.',
            'permissions' => ['broadcast.emergency'], 'ajax' => ['emergency_start', 'emergency_stop', 'emergency_silence']],
        'emergency_alarm' => ['label' => 'Emergency alarm sound', 'group' => 'scheduling', 'description' => 'Beep / siren / fire alarm with the emergency message.',
            'depends' => ['broadcast_emergency']],
        'power_schedules' => ['label' => 'TV power schedules', 'group' => 'scheduling', 'description' => 'Switch screens off and on at set times.',
            'permissions' => ['schedule.manage'], 'pages' => ['power.php']],

        // ------------------------------------------------------------------ devices
        'device_schedules' => ['label' => 'Device schedules', 'group' => 'devices', 'description' => 'Timed volume, input, restart, bell and spoken announcements.',
            'permissions' => ['device_schedules.manage', 'announce.send'], 'pages' => ['device_schedules.php']],
        'presence' => ['label' => 'Presence sensors', 'group' => 'devices', 'description' => 'Motion sensors switch screens on and off.',
            'api' => ['presence'], 'depends' => ['device_schedules', 'api_access']],
        'video_walls' => ['label' => 'Video walls', 'group' => 'devices', 'description' => 'Up to 4×4 screens as one picture.',
            'permissions' => ['video_walls.manage'], 'pages' => ['video_walls.php'], 'extensions' => ['VideoWallExtension']],
        'sync_playback' => ['label' => 'Synchronized playback', 'group' => 'devices', 'description' => 'Playlists play in sync on several screens.',
            'extensions' => ['SyncPlaybackExtension'], 'depends' => ['playlists']],
        'live_view' => ['label' => 'Live screen view', 'group' => 'devices', 'description' => 'See what a screen shows right now.',
            'permissions' => ['support.view'], 'pages' => ['live_view.php'], 'ajax' => ['live_*']],
        'tv_health' => ['label' => 'TV health', 'group' => 'devices', 'description' => 'Storage, memory, temperature and Wi-Fi warnings.',
            'permissions' => ['tv_health.view'], 'pages' => ['tv_health.php']],
        'usb_mode' => ['label' => 'USB / offline mode', 'group' => 'devices', 'description' => 'Play a USB drive folder on a screen.'],
        'cec' => ['label' => 'HDMI-CEC', 'group' => 'devices', 'description' => 'Android boxes switch the TV over HDMI-CEC.'],
        'web_player' => ['label' => 'Web player', 'group' => 'devices', 'description' => 'Smart-TV browsers, PCs, Fire TV and Raspberry Pi as screens.'],
        'tv_controls' => ['label' => 'TV controls', 'group' => 'devices', 'description' => 'Volume policy, inputs and guest TV menu.',
            'permissions' => ['devices.controls'], 'pages' => ['tv_controls.php'], 'extensions' => ['DeviceControlsExtension']],
        'support' => ['label' => 'TV support tools', 'group' => 'devices', 'description' => 'Screenshots and log upload from screens.',
            'permissions' => ['support.view'], 'pages' => ['support.php'], 'ajax' => ['support_*']],
        'apk_updates' => ['label' => 'TV app updates', 'group' => 'devices', 'description' => 'Upload and roll out TV app (APK) versions.',
            'permissions' => ['apk.manage'], 'pages' => ['apk.php']],

        // ------------------------------------------------------------------ reports & marketing
        'play_report' => ['label' => 'Proof of play', 'group' => 'reports', 'description' => 'What played where and how often (CSV, print).',
            'permissions' => ['play_report.view'], 'pages' => ['play_report.php']],
        'analytics' => ['label' => 'Analytics', 'group' => 'reports', 'description' => 'Screen time, content and audience statistics.',
            'permissions' => ['analytics.view'], 'pages' => ['analytics.php'], 'widgets' => ['60_analytics_today.php']],
        'ads' => ['label' => 'Ads & sponsors', 'group' => 'reports', 'description' => 'Sponsor campaigns inserted into playlists with reports.',
            'permissions' => ['ads.manage'], 'pages' => ['ads.php', 'sponsor_report.php'], 'extensions' => ['AdsExtension']],
        'marketplace' => ['label' => 'Ad marketplace', 'group' => 'reports', 'description' => 'Local businesses book ads on your screens.',
            'permissions' => ['marketplace.manage', 'marketplace.settings'], 'pages' => ['marketplace.php'], 'depends' => ['ads']],
        'pwa_push' => ['label' => 'Mobile app & push', 'group' => 'reports', 'description' => 'Install the admin panel as an app and get push alerts.',
            'permissions' => ['push.self'], 'pages' => ['push.php'], 'ajax' => ['push_*']],

        // ------------------------------------------------------------------ hospitality
        'guests' => ['label' => 'Guests / front desk', 'group' => 'hospitality', 'description' => 'Check-in / out, welcome screen, check-out reminder.',
            'permissions' => ['guests.manage', 'guests.setup'], 'pages' => ['guests.php', 'services_setup.php'],
            'extensions' => ['GuestExtension'], 'widgets' => ['40_guests.php']],
        'room_service' => ['label' => 'Room service & requests', 'group' => 'hospitality', 'description' => 'Guest orders and requests from the phone, live board.',
            'permissions' => ['services.manage', 'guests.setup'], 'pages' => ['orders.php', 'services_setup.php'], 'ajax' => ['guests_*'],
            'api_self' => ['guest'], 'extensions' => ['GuestExtension']], // guest app link: 404 from Guests::resolveToken, not 403
        'feedback' => ['label' => 'Guest feedback', 'group' => 'hospitality', 'description' => 'Ratings and comments from guests.',
            'permissions' => ['guests.feedback'], 'pages' => ['feedback.php'], 'depends' => ['room_service']],
        'pms' => ['label' => 'PMS integration', 'group' => 'hospitality', 'description' => 'Check-in / out from the hotel PMS over the API.',
            'api' => ['pms'], 'depends' => ['guests', 'api_access']],
        'guide' => ['label' => 'Local guide', 'group' => 'hospitality', 'description' => 'Local guide page in the TV guest menu.',
            'extensions' => ['GuideExtension'], 'depends' => ['content']],

        // ------------------------------------------------------------------ advanced
        'user_access' => ['label' => 'Per-user screen access', 'group' => 'advanced', 'description' => 'Limit a user to some screens or groups.'],
        'custom_roles' => ['label' => 'Custom roles', 'group' => 'advanced', 'description' => 'Own roles with chosen permissions.',
            'permissions' => ['roles.manage'], 'pages' => ['roles.php'], 'ajax' => ['roles_*']],
        'api_access' => ['label' => 'Integrations API', 'group' => 'advanced', 'description' => 'REST API for PMS, KPI push and presence sensors.',
            'api' => ['kpi']],
    ];

    /** Fields of DeviceFeaturesExtension / emergencies that are stripped from TV content per feature. */
    private const CONTENT_FIELDS = ['usb_mode' => 'usb_mode', 'cec_mode' => 'cec'];

    /** @var array<string, array> features added by modules (register()) */
    private static array $extra = [];
    /** @var array<int, array<string, bool>> hotel id => effective map (per request) */
    private static array $effective = [];
    /** @var array<int, ?array> plan id => row (per request) */
    private static array $plans = [];
    /** Pending API guard: owners of the current REST route. */
    private static ?array $apiOwners = null;
    /** Test hook: storage usage in bytes per hotel instead of measuring the folder. */
    public static ?array $storageOverride = null;

    // ------------------------------------------------------------------ registry

    /** Module hook (core/boot.d): add a feature. Same fields as the registry. */
    public static function register(string $key, array $def): void
    {
        if (!preg_match('/^[a-z0-9_]{2,40}$/', $key)) {
            throw new InvalidArgumentException('Invalid feature key');
        }
        self::$extra[$key] = $def + ['label' => $key, 'group' => 'advanced', 'description' => ''];
        self::forget();
    }

    /** @return array<string, array> key => definition (every field present), registry order. */
    public static function all(): array
    {
        static $cache = null;
        static $extraCount = -1;
        if ($cache !== null && $extraCount === count(self::$extra)) {
            return $cache;
        }
        $out = [];
        foreach (self::REGISTRY + self::$extra as $k => $d) {
            $out[$k] = $d + [
                'label' => $k, 'group' => 'advanced', 'description' => '', 'core' => false, 'permissions' => [], 'pages' => [],
                'ajax' => [], 'api' => [], 'api_self' => [], 'apps' => [], 'nav' => [], 'depends' => [], 'extensions' => [], 'widgets' => [],
            ];
        }
        $extraCount = count(self::$extra);
        return $cache = $out;
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function isCore(string $key): bool
    {
        return !empty(self::all()[$key]['core']);
    }

    /** Non-core keys (the ones a plan can include or not). */
    public static function optionalKeys(): array
    {
        return array_keys(array_filter(self::all(), static fn ($d) => empty($d['core'])));
    }

    public static function coreKeys(): array
    {
        return array_keys(array_filter(self::all(), static fn ($d) => !empty($d['core'])));
    }

    /** @return array<string, array<string, array>> group => [key => def] (optional features only unless $withCore). */
    public static function grouped(bool $withCore = false): array
    {
        $out = [];
        foreach (self::GROUPS as $g => $_) {
            $out[$g] = [];
        }
        foreach (self::all() as $k => $d) {
            if (!$withCore && $d['core']) {
                continue;
            }
            $out[$d['group']][$k] = $d;
        }
        return array_filter($out);
    }

    public static function label(string $key): string
    {
        return __((string) (self::all()[$key]['label'] ?? $key));
    }

    // ------------------------------------------------------------------ lookups (owners)

    /** Every feature listing $value in $field. */
    private static function owners(string $field, string $value): array
    {
        $out = [];
        foreach (self::all() as $k => $d) {
            if (in_array($value, $d[$field], true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** Owners of an admin page (basename). [] = core / platform page (never gated). */
    public static function pageOwners(string $basename): array
    {
        if (preg_match(self::PLATFORM_PAGE_PATTERN, $basename)) {
            return [];
        }
        $o = self::owners('pages', $basename);
        return array_values(array_filter($o, static fn ($k) => !self::isCore($k))) ?: [];
    }

    /** Feature that owns an admin page (first owner), null for core / platform / unknown pages. */
    public static function forPage(string $basename): ?string
    {
        return self::pageOwners(basename($basename))[0] ?? null;
    }

    /** Is the page registered at all (some feature, core or platform)? */
    public static function pageKnown(string $basename): bool
    {
        return in_array($basename, self::DISPATCHERS, true) || (bool) preg_match(self::PLATFORM_PAGE_PATTERN, $basename) || self::owners('pages', $basename) !== [];
    }

    /** Features registering an ajax action: exact name, else the longest matching "prefix_*". */
    private static function ajaxMatch(string $action): array
    {
        $exact = self::owners('ajax', $action);
        if ($exact) {
            return $exact;
        }
        $best = [];
        $bestLen = -1;
        foreach (self::all() as $k => $d) {
            foreach ($d['ajax'] as $a) {
                if (str_ends_with($a, '*')) {
                    $p = substr($a, 0, -1);
                    if ($p !== '' && str_starts_with($action, $p)) {
                        if (strlen($p) > $bestLen) {
                            $best = [$k];
                            $bestLen = strlen($p);
                        } elseif (strlen($p) === $bestLen) {
                            $best[] = $k;
                        }
                    }
                }
            }
        }
        return $best;
    }

    public static function ajaxOwners(string $action): array
    {
        return array_values(array_filter(self::ajaxMatch($action), static fn ($k) => !self::isCore($k)));
    }

    public static function ajaxKnown(string $action): bool
    {
        return self::ajaxMatch($action) !== [];
    }

    /** Feature of an ajax action (null = core / unknown). */
    public static function forAjax(string $action): ?string
    {
        return self::ajaxOwners($action)[0] ?? null;
    }

    /** Features registering a REST route ($fields: api = gated centrally, api_self = the handler checks itself). */
    private static function apiMatch(string $route, array $fields = ['api', 'api_self']): array
    {
        $route = trim($route, '/');
        $best = [];
        $bestLen = -1;
        foreach (self::all() as $k => $d) {
            foreach (array_merge(...array_map(static fn ($f) => $d[$f], $fields)) as $p) {
                $hit = $p === '' ? $route === '' : ($route === $p || str_starts_with($route, $p . '/'));
                if ($hit && strlen($p) >= $bestLen) {
                    $best = strlen($p) > $bestLen ? [$k] : array_merge($best, [$k]);
                    $bestLen = strlen($p);
                }
            }
        }
        return $best;
    }

    public static function apiOwners(string $route): array
    {
        return array_values(array_filter(self::apiMatch($route), static fn ($k) => !self::isCore($k)));
    }

    public static function apiKnown(string $route): bool
    {
        return self::apiMatch($route) !== [];
    }

    /** Feature of a REST route (null = core device API / unknown). */
    public static function forApi(string $route): ?string
    {
        return self::apiOwners($route)[0] ?? null;
    }

    /** Feature of a display app key (unknown apps belong to the base "apps" feature). */
    public static function forApp(string $appKey): string
    {
        return self::owners('apps', $appKey)[0] ?? 'apps';
    }

    /** Features owning a permission (core ones included). [] = not tied to any feature. */
    public static function permissionOwners(string $permission): array
    {
        return self::owners('permissions', $permission);
    }

    // ------------------------------------------------------------------ effective entitlements

    /** Forget cached entitlements (after a plan / customer change; Tenant::forget() calls it). */
    public static function forget(): void
    {
        self::$effective = [];
        self::$plans = [];
        if (class_exists('Auth', false) && method_exists('Auth', 'forgetPermissions')) {
            Auth::forgetPermissions();
        }
    }

    /**
     * Keys of a plans.features value. null = all optional keys. Legacy lists (only old module names /
     * unknown names) mean "everything except the old modules left out".
     */
    public static function planKeys(mixed $features): array
    {
        $all = self::optionalKeys();
        if ($features === null || $features === '' || $features === []) {
            return $all;
        }
        $list = is_array($features) ? $features : json_decode((string) $features, true);
        if (!is_array($list) || !$list) {
            return $all;
        }
        // Old format {"ads": true, "guests": false}
        if (!array_is_list($list)) {
            $list = array_keys(array_filter($list));
        }
        $list = array_values(array_filter($list, 'is_string'));
        if (self::isLegacyList($list)) {
            $off = [];
            foreach (self::LEGACY_MODULES as $module => $keys) {
                if (!in_array($module, $list, true)) {
                    $off = array_merge($off, $keys);
                }
            }
            return array_values(array_diff($all, $off));
        }
        return array_values(array_intersect($all, $list));
    }

    /** True for lists written before 2.5: no key that only the new registry knows. */
    public static function isLegacyList(array $list): bool
    {
        foreach ($list as $k) {
            if (self::exists($k) && !isset(self::LEGACY_MODULES[$k])) {
                return false;
            }
        }
        return true;
    }

    /** Value to store in plans.features for the chosen keys: NULL when everything is chosen. */
    public static function encodePlanKeys(array $keys): ?string
    {
        $opt = self::optionalKeys();
        $keys = array_values(array_intersect($opt, $keys));
        if (count($keys) === count($opt)) {
            return null;
        }
        // Core keys first: marks the new format (a list of only old module names is read as legacy).
        return json_out(array_merge(self::coreKeys(), $keys));
    }

    /** {"add": [...], "remove": [...]} of a customer (unknown / core keys dropped). */
    public static function overrides(?int $hotelId = null): array
    {
        $h = self::hotelRow($hotelId);
        return self::parseOverrides($h['feature_overrides'] ?? null);
    }

    public static function parseOverrides(mixed $json): array
    {
        $o = is_array($json) ? $json : (is_string($json) && $json !== '' ? json_decode($json, true) : null);
        $opt = self::optionalKeys();
        $clean = static fn ($v) => array_values(array_intersect($opt, array_filter((array) $v, 'is_string')));
        return ['add' => $clean($o['add'] ?? []), 'remove' => $clean($o['remove'] ?? [])];
    }

    public static function encodeOverrides(array $add, array $remove): ?string
    {
        $o = self::parseOverrides(['add' => $add, 'remove' => array_diff($remove, $add)]);
        return $o['add'] || $o['remove'] ? json_out($o) : null;
    }

    private static function hotelRow(?int $hotelId): ?array
    {
        $hotelId ??= Tenant::current();
        return $hotelId === null ? null : Tenant::hotel($hotelId);
    }

    /** Plan row (per request), null when none. */
    public static function plan(?int $hotelId = null): ?array
    {
        $h = self::hotelRow($hotelId);
        $pid = $h && $h['plan_id'] ? (int) $h['plan_id'] : 0;
        if ($pid <= 0) {
            return null;
        }
        if (!array_key_exists($pid, self::$plans)) {
            self::$plans[$pid] = DB::one('SELECT * FROM plans WHERE id = :id', ['id' => $pid]);
        }
        return self::$plans[$pid];
    }

    /**
     * Effective map key => bool for a customer: plan + overrides, then depends. Core keys always true.
     * No customer (platform context) → everything on.
     */
    public static function effective(?int $hotelId = null): array
    {
        $hotelId ??= Tenant::current();
        $all = self::all();
        if ($hotelId === null) {
            return array_fill_keys(array_keys($all), true);
        }
        if (isset(self::$effective[$hotelId])) {
            return self::$effective[$hotelId];
        }
        $h = Tenant::hotel($hotelId);
        $on = array_fill_keys(self::planKeys($h['plan_features'] ?? null), true);
        $o = self::parseOverrides($h['feature_overrides'] ?? null);
        foreach ($o['add'] as $k) {
            $on[$k] = true;
        }
        foreach ($o['remove'] as $k) {
            unset($on[$k]);
        }
        $map = [];
        $resolve = static function (string $k, array $stack) use (&$resolve, &$map, $on, $all): bool {
            if (isset($map[$k])) {
                return $map[$k];
            }
            if (!isset($all[$k])) {
                return false;
            }
            if ($all[$k]['core']) {
                return $map[$k] = true;
            }
            if (!isset($on[$k]) || in_array($k, $stack, true)) {
                return $map[$k] = false;
            }
            foreach ($all[$k]['depends'] as $dep) {
                if (!$resolve($dep, array_merge($stack, [$k]))) {
                    return $map[$k] = false;
                }
            }
            return $map[$k] = true;
        };
        foreach (array_keys($all) as $k) {
            $resolve($k, []);
        }
        return self::$effective[$hotelId] = $map;
    }

    /** Is the customer entitled to $key? (Plan + overrides + depends; core = always; no customer = yes.) */
    public static function enabled(string $key, ?int $hotelId = null): bool
    {
        if (!self::exists($key)) {
            return false;
        }
        return self::effective($hotelId)[$key] ?? false;
    }

    /** Is at least one of $keys enabled ([] = yes: nothing to check)? */
    public static function anyEnabled(array $keys, ?int $hotelId = null): bool
    {
        if (!$keys) {
            return true;
        }
        foreach ($keys as $k) {
            if (self::enabled($k, $hotelId)) {
                return true;
            }
        }
        return false;
    }

    /** Enabled optional keys of a customer. */
    public static function enabledKeys(?int $hotelId = null): array
    {
        $map = self::effective($hotelId);
        return array_values(array_filter(self::optionalKeys(), static fn ($k) => !empty($map[$k])));
    }

    public static function disabledKeys(?int $hotelId = null): array
    {
        $map = self::effective($hotelId);
        return array_values(array_filter(self::optionalKeys(), static fn ($k) => empty($map[$k])));
    }

    /** The platform admin always has everything (pages stay open inside an entered customer). */
    public static function bypass(): bool
    {
        return PHP_SAPI !== 'cli' && class_exists('Auth', false) && Auth::role() === 'platform_admin';
    }

    /** enabled() for the current user: platform admins are never blocked. */
    public static function allows(string $key, ?int $hotelId = null): bool
    {
        return self::bypass() || self::enabled($key, $hotelId);
    }

    /**
     * For Auth::can() (RBAC developer): false when $permission belongs only to features the current
     * customer does not have. Permissions of no feature / core features, no customer context and
     * platform admins → true.
     */
    public static function permissionEnabled(string $permission): bool
    {
        if (!Tenant::has()) {
            return true;
        }
        $owners = self::permissionOwners($permission);
        if (!$owners) {
            return true;
        }
        if (self::bypass()) {
            return true;
        }
        return self::anyEnabled($owners);
    }

    // ------------------------------------------------------------------ enforcement

    /**
     * Stop the request with 403 "Not included in your plan" unless the current customer has $key
     * (platform admins pass). JSON for AJAX / API, a page otherwise. CLI: throws DomainException.
     */
    public static function require(string $key): void
    {
        if (self::allows($key)) {
            return;
        }
        self::deny($key);
    }

    /** Payload / message of a denial. */
    public static function denial(string $key): array
    {
        return [
            'code' => 'FEATURE_DISABLED',
            'message' => __('Not included in your plan') . ': ' . self::label($key) . '. ' . __('Contact your provider to upgrade your plan.'),
            'feature' => $key,
        ];
    }

    public static function deny(string $key): never
    {
        $d = self::denial($key);
        if (PHP_SAPI === 'cli') {
            throw new DomainException($d['message'], 403);
        }
        if (defined('HC_API')) {
            Api::error('FEATURE_DISABLED', $d['message'], 403);
        }
        if (!headers_sent()) {
            http_response_code(403);
        }
        if (class_exists('Auth', false) && Auth::isAjax()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
            }
            echo json_out(['ok' => false, 'error' => $d]);
            exit;
        }
        $GLOBALS['hcDeniedFeature'] = $key;
        require HC_ROOT . '/admin/partials/not_in_plan.php';
        exit;
    }

    /**
     * Central guard of admin requests (admin/partials/common.php): the page (or ajax.php action) must
     * belong to a feature of the customer's plan. Not logged in / no customer / core page → nothing.
     */
    public static function guardAdminRequest(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $script = (string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
        if (!preg_match('#/admin/([A-Za-z0-9_]+\.php)$#', $script, $m)) {
            return;
        }
        $owners = $m[1] === 'ajax.php'
            ? self::ajaxOwners(is_string($_GET['action'] ?? null) ? $_GET['action'] : '')
            : self::pageOwners($m[1]);
        if (!$owners || !Auth::user() || !Tenant::has() || self::bypass() || self::anyEnabled($owners)) {
            return;
        }
        self::deny($owners[0]);
    }

    /**
     * REST API (api/index.php, before the module routes): remember the route's features; they are
     * checked as soon as the route handler selects its customer (Tenant::set → tenantChanged()).
     */
    public static function guardApiRoute(string $route): void
    {
        $owners = array_values(array_filter(self::apiMatch($route, ['api']), static fn ($k) => !self::isCore($k)));
        self::$apiOwners = $owners ?: null;
        if ($owners && Tenant::has()) {
            self::tenantChanged(Tenant::id());
        }
    }

    /** Tenant::set() hook. */
    public static function tenantChanged(?int $hotelId): void
    {
        if ($hotelId === null || self::$apiOwners === null || !defined('HC_API')) {
            return;
        }
        $owners = self::$apiOwners;
        if (!self::anyEnabled($owners, $hotelId)) {
            self::$apiOwners = null;
            self::deny($owners[0]);
        }
    }

    // ------------------------------------------------------------------ admin UI helpers

    /** Should the hotel navigation show this page (customer's view, also for the platform admin)? */
    public static function pageVisible(string $file): bool
    {
        $base = basename((string) parse_url($file, PHP_URL_PATH));
        return !Tenant::has() || self::anyEnabled(self::pageOwners($base));
    }

    /** Dashboard widget file (admin/partials/dashboard.d) visible for the customer? */
    public static function widgetVisible(string $file): bool
    {
        return !Tenant::has() || self::anyEnabled(array_values(array_filter(self::owners('widgets', basename($file)), static fn ($k) => !self::isCore($k))));
    }

    // ------------------------------------------------------------------ TV content

    /** Content extension class allowed on TVs of the current customer? */
    public static function extensionEnabled(object|string $ext): bool
    {
        $class = is_object($ext) ? get_class($ext) : $ext;
        return self::anyEnabled(self::owners('extensions', $class));
    }

    /** May this content item play (display-app family / layout in the plan)? */
    public static function itemAllowed(array $item): bool
    {
        $hid = isset($item['hotel_id']) ? (int) $item['hotel_id'] : Tenant::current();
        $type = (string) ($item['type'] ?? '');
        if ($type === 'layout') {
            return self::enabled('layouts', $hid);
        }
        if ($type === 'app') {
            $s = $item['settings'] ?? null;
            $s = is_string($s) ? json_decode($s, true) : $s;
            $app = is_array($s) && is_string($s['app'] ?? null) ? $s['app'] : '';
            return self::enabled(self::forApp($app), $hid) && self::enabled('apps', $hid);
        }
        return true;
    }

    /** Strip fields of disabled features from a TV content object (after the extensions ran). */
    public static function filterContent(array &$content): void
    {
        if (!Tenant::has()) {
            return;
        }
        foreach (self::CONTENT_FIELDS as $field => $key) {
            if (array_key_exists($field, $content) && !self::enabled($key)) {
                unset($content[$field]);
            }
        }
        if (is_array($content['emergency'] ?? null) && !self::enabled('emergency_alarm')) {
            $content['emergency']['alarm'] = null;
            $content['emergency']['alarm_muted'] = false;
        }
    }

    // ------------------------------------------------------------------ limits

    /**
     * Limits of a customer (null = unlimited): max_screens (screens = rooms and TVs, Tenant::maxTvs()),
     * max_users, storage_mb. Customer overrides win over the plan.
     */
    public static function limits(?int $hotelId = null): array
    {
        $hotelId ??= Tenant::current();
        if ($hotelId === null) {
            return ['max_screens' => null, 'max_users' => null, 'storage_mb' => null];
        }
        $h = Tenant::hotel($hotelId) ?? [];
        $p = self::plan($hotelId) ?? [];
        $pick = static function (string $col) use ($h, $p): ?int {
            if (isset($h[$col]) && $h[$col] !== null && $h[$col] !== '') {
                return (int) $h[$col];
            }
            return isset($p[$col]) && $p[$col] !== null && $p[$col] !== '' ? (int) $p[$col] : null;
        };
        return ['max_screens' => Tenant::maxTvs($hotelId), 'max_users' => $pick('max_users'), 'storage_mb' => $pick('storage_mb')];
    }

    /** Users counted against max_users: the customer's own (built-in or custom role) users. */
    public static function userCount(?int $hotelId = null): int
    {
        $hotelId ??= Tenant::id();
        [$in, $p] = DB::in(Auth::HOTEL_ROLES, 'r');
        return (int) DB::value("SELECT COUNT(*) FROM users WHERE hotel_id = :h AND role IN $in", $p + ['h' => $hotelId]);
    }

    /**
     * Screens counted against max_screens: registered TVs / players (Tenant::tvCount()). Screen records
     * (rooms) without a TV are free, e.g. the 20 demo rooms of a 3-TV demo. The TV registration
     * (DeviceManager::register, LICENSE_LIMIT) enforces the limit.
     */
    public static function screenCount(?int $hotelId = null): int
    {
        return Tenant::tvCount($hotelId ?? Tenant::id());
    }

    /** Error message when the customer may not add $n more users, else null. */
    public static function userLimitError(int $n = 1, ?int $hotelId = null): ?string
    {
        $max = self::limits($hotelId)['max_users'];
        if ($max === null) {
            return null;
        }
        return self::userCount($hotelId) + $n > $max
            ? __('Your plan allows at most :n users. Remove a user or contact your provider to upgrade your plan.', ['n' => $max])
            : null;
    }

    /** Error message when the customer may not add $n more screens, else null. */
    public static function screenLimitError(int $n = 1, ?int $hotelId = null): ?string
    {
        $max = self::limits($hotelId)['max_screens'];
        if ($max === null) {
            return null;
        }
        return self::screenCount($hotelId) + $n > $max
            ? __('Your plan allows at most :n screens. Remove a screen or contact your provider to upgrade your plan.', ['n' => $max])
            : null;
    }

    /** Bytes used by the customer's uploads (uploads/h{id}), cached 5 minutes. */
    public static function storageUsed(?int $hotelId = null): int
    {
        $hotelId ??= Tenant::id();
        if (self::$storageOverride !== null && isset(self::$storageOverride[$hotelId])) {
            return (int) self::$storageOverride[$hotelId];
        }
        $v = Cache::get('storage', 'h' . $hotelId, 300);
        if (is_int($v)) {
            return $v;
        }
        $bytes = 0;
        $dir = HC_ROOT . '/uploads/h' . $hotelId;
        if (is_dir($dir)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) {
                    $bytes += (int) $f->getSize();
                }
            }
        }
        Cache::set('storage', 'h' . $hotelId, $bytes);
        return $bytes;
    }

    /**
     * Uploader: refuse a file of $bytes that would exceed storage_mb (RuntimeException with a clear
     * message). On success the cached usage grows by $bytes so quick uploads in a row are counted.
     */
    public static function checkStorage(int $bytes, ?int $hotelId = null): void
    {
        $hotelId ??= Tenant::current();
        if ($hotelId === null) {
            return;
        }
        $max = self::limits($hotelId)['storage_mb'];
        if ($max === null) {
            return;
        }
        $used = self::storageUsed($hotelId);
        if ($used + $bytes > $max * 1024 * 1024) {
            throw new RuntimeException(__('Storage full: your plan includes :m MB and :u MB are used. Delete old files or contact your provider to upgrade your plan.', [
                'm' => $max, 'u' => (int) ceil($used / 1048576),
            ]));
        }
        if (self::$storageOverride === null) {
            Cache::set('storage', 'h' . $hotelId, $used + $bytes);
        }
    }

    // ------------------------------------------------------------------ plan editor input

    /** Feature keys of a preset (Basic / Business / Pro / Hospitality). */
    public static function presetKeys(string $name): array
    {
        $p = self::PRESETS[$name] ?? null;
        if (!$p) {
            return [];
        }
        if (isset($p['except'])) {
            return array_values(array_filter(self::optionalKeys(), static function ($k) use ($p) {
                $d = self::all()[$k];
                return !in_array($k, $p['except'], true) && !in_array($d['group'], $p['except'], true);
            }));
        }
        return $p['features'] === null ? self::optionalKeys() : array_values(array_intersect(self::optionalKeys(), $p['features']));
    }

    /** Optional limit field: '' → null, else a non-negative int (errors added for junk). */
    public static function limitInput(mixed $v, string $label, array &$errors, int $max = 10000000): ?int
    {
        $v = is_string($v) || is_int($v) ? trim((string) $v) : '';
        if ($v === '') {
            return null;
        }
        if (!ctype_digit($v) || (int) $v > $max) {
            $errors[] = __(':f must be a whole number (or empty for unlimited).', ['f' => $label]);
            return null;
        }
        return (int) $v;
    }
}
