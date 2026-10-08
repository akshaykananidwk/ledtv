<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.5 rename (docs/modules/terminology.md): "Krishna Cloud TV Management", Hotel → Customer / Business,
 * Room → Screen in every user-visible text outside the Hospitality module, Hindi as an admin language.
 * Crawls every admin page as platform admin (platform pages and inside a customer) and as a customer
 * admin without the Hospitality features, in English, Gujarati and Hindi: no PHP warnings, and no
 * "hotel" / "room" in the visible English text.
 */
final class TerminologyTest extends TestCase
{
    private static string $url;
    private static int $customer;
    private static array $id = [];
    /** @var array<string, AdminSession> */
    private static array $s = [];

    /** Visible English words that are still correct: names of Hospitality features (shown as plan features / upsell). */
    private const ALLOWED = [
        'Room service & requests', 'Room service', 'room service', 'Room-service', 'room-service',
        'property PMS', 'hotel PMS',
    ];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::setFor(1, 'hotel_name', 'Main Business'); // fresh test DB: customer #1 is named "My Hotel" by migration 002
        $pw = Auth::hash('Passw0rd!');
        $mk = static fn (string $name, string $role, ?int $hotel): int => DB::insert('users', ['hotel_id' => $hotel, 'username' => $name,
            'email' => $name . '@term.test', 'full_name' => $name, 'password_hash' => $pw, 'role' => $role, 'is_active' => 1, 'created_at' => now()]);
        $mk('termplat', 'platform_admin', null);
        $pro = (int) DB::value("SELECT id FROM plans WHERE name = 'Pro'");
        self::$customer = Hotels::create(['name' => 'Sagar Electronics', 'plan_id' => $pro ?: null]);
        $mk('termboss', 'super_admin', self::$customer);
        Tenant::run(self::$customer, static function (): void {
            Demo::sampleContent([1 => 4, 2 => 4]);
            self::$id['screen'] = (int) DB::value("SELECT id FROM rooms WHERE room_number = '101'");
            self::$id['group'] = (int) DB::value('SELECT id FROM room_groups ORDER BY id LIMIT 1');
            self::$id['dev'] = DB::insert('devices', ['device_uid' => 'tv-term-0001', 'room_id' => self::$id['screen'], 'token_hash' => hash('sha256', 'term'),
                'status' => 'online', 'last_ping' => now(), 'registered_at' => now()]);
        });
        Tenant::set(1);
        Cache::clear();
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        self::$s = [];
        Tenant::set(1);
    }

    private static function as(string $user, string $lang): AdminSession
    {
        if (!isset(self::$s[$user . $lang])) {
            DB::update('users', ['language' => $lang], 'username = :u', ['u' => $user]); // the UI language is taken at login
            self::$s[$user . $lang] = new AdminSession(self::$url, $user);
        }
        return self::$s[$user . $lang];
    }

    /** Every admin page (GET) plus the main sub-views. */
    private static function pages(): array
    {
        $pages = [];
        foreach (glob(HC_ROOT . '/admin/*.php') ?: [] as $f) {
            $b = basename($f);
            if (!in_array($b, ['logout.php', 'ajax.php', 'ajax_update.php', 'pwa_icon.php', 'manifest.php'], true)) {
                $pages[] = $b;
            }
        }
        return array_merge($pages, [
            'rooms.php?action=new', 'rooms.php?action=bulk', 'rooms.php?action=devices', 'rooms.php?action=edit&id=' . self::$id['screen'],
            'rooms.php?action=device&id=' . self::$id['dev'], 'groups.php?action=edit&id=' . self::$id['group'], 'users.php?action=new',
            'content.php?action=new', 'playlists.php?action=new', 'logs.php?tab=activity', 'settings.php?tab=devices', 'settings.php?tab=display',
        ]);
    }

    /** Visible text of a page: no scripts / styles / tags / attribute values. */
    private static function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#si', ' ', $html);
        $html = (string) preg_replace('#<[^>]+>#', ' ', $html);
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function hotelRoomWords(string $text): array
    {
        $text = str_replace(self::ALLOWED, ' ', $text);
        preg_match_all('/.{0,40}\b(hotels?|rooms?)\b.{0,40}/iu', $text, $m);
        return array_map('trim', $m[0]);
    }

    public function testCustomerAdminSeesScreensAndCustomersNeverHotelsOrRooms(): void
    {
        $found = [];
        foreach (['en', 'gu', 'hi'] as $lang) {
            $s = self::as('termboss', $lang);
            foreach (self::pages() as $p) {
                [$code, , $html] = $s->get($p);
                $this->assertContains($code, [200, 302, 403, 404], "$lang $p");
                $this->assertFalse(TestEnv::hasPhpError($html), "PHP error on $p ($lang)");
                if ($lang === 'en' && $code === 200) {
                    foreach (self::hotelRoomWords(self::visibleText($html)) as $hit) {
                        $found[] = "$p: $hit";
                    }
                }
            }
        }
        $this->assertSame([], $found, 'hotel / room wording outside the Hospitality module');
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    public function testPlatformAdminPagesInEveryLanguage(): void
    {
        $found = [];
        foreach (['en', 'gu', 'hi'] as $lang) {
            $s = self::as('termplat', $lang);
            // Platform pages (no customer entered), then the customer pages after "Enter customer".
            foreach ([false, true] as $entered) {
                if ($entered) {
                    [$code] = $s->post('platform_hotels.php', ['op' => 'enter', 'id' => self::$customer]);
                    $this->assertSame(302, $code);
                }
                foreach (self::pages() as $p) {
                    [$code, , $html] = $s->get($p);
                    $this->assertContains($code, [200, 302, 403, 404], "$lang $p" . ($entered ? ' (entered)' : ''));
                    $this->assertFalse(TestEnv::hasPhpError($html), "PHP error on $p ($lang)");
                    if ($lang === 'en' && $code === 200) {
                        foreach (self::hotelRoomWords(self::visibleText($html)) as $hit) {
                            $found[] = "$p: $hit";
                        }
                    }
                }
                if ($entered) {
                    [, , $html] = $s->get('index.php');
                    $this->assertStringContainsString(['en' => 'You are managing customer', 'gu' => 'તમે આ ગ્રાહક સંભાળી રહ્યા છો', 'hi' => 'आप यह ग्राहक संभाल रहे हैं'][$lang], $html);
                    $s->post('platform_hotels.php', ['op' => 'leave']);
                }
            }
        }
        $this->assertSame([], $found, 'hotel / room wording on platform pages');
        $this->assertSame('', TestEnv::phpErrors(), 'PHP warnings in logs/php_error.log');
    }

    public function testNewWordingAndProductName(): void
    {
        [, , $html] = self::as('termboss', 'en')->get('rooms.php');
        $this->assertStringContainsString('Screens &amp; TVs', $html);
        $this->assertStringContainsString('Add screen', $html);
        [, , $html] = self::as('termboss', 'en')->get('rooms.php?action=new');
        $this->assertStringContainsString('Screen name / ID', $html);
        [, , $html] = self::as('termboss', 'en')->get('settings.php');
        $this->assertStringContainsString('Business name', $html);
        [, , $html] = self::as('termplat', 'en')->get('platform_hotels.php');
        $this->assertStringContainsString('New customer', $html);
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertStringContainsString('Krishna Cloud TV Management', $html);
        $this->assertStringNotContainsString('LED TV', $html);
        $this->assertSame('My Business', Settings::DEFAULTS['hotel_name']);
    }

    public function testPublicPagesUseTheNewWording(): void
    {
        Settings::setPlatform('signup_enabled', '1');
        $found = [];
        foreach (['admin/login.php', 'admin/login.php?lang=gu', 'admin/login.php?lang=hi', 'player/', 'signup.php', 'advertise/', 'advertise/hotels.php', 'admin/offline.html'] as $p) {
            [$code, , $html] = TestEnv::http('GET', self::$url . $p);
            $this->assertContains($code, [200, 302, 404], $p);
            $this->assertFalse(TestEnv::hasPhpError($html), "PHP error on $p");
            if ($code === 200 && !str_contains($p, 'lang=')) {
                foreach (self::hotelRoomWords(self::visibleText($html)) as $hit) {
                    $found[] = "$p: $hit";
                }
            }
        }
        $this->assertSame([], $found);
    }

    public function testHindiIsAnAdminLanguage(): void
    {
        $this->assertArrayHasKey('hi', I18n::LANGUAGES);
        [, , $html] = TestEnv::http('GET', self::$url . 'admin/login.php');
        $this->assertStringContainsString('हिन्दी', $html, 'language menu on the login page');
        $s = self::as('termboss', 'hi');
        [, , $html] = $s->get('index.php');
        $this->assertStringContainsString('स्क्रीन और TV', $html, 'navigation in Hindi');
        [, , $html] = $s->get('rooms.php');
        $this->assertStringContainsString('स्क्रीन जोड़ें', $html);
        [, , $html] = $s->get('users.php');
        $this->assertStringContainsString('यूज़र जोड़ें', $html);
        // The main admin pages have a Hindi string for every key they use.
        $hi = I18n::table('hi');
        $missing = [];
        foreach (['admin/index.php', 'admin/rooms.php', 'admin/content.php', 'admin/playlists.php', 'admin/broadcast.php', 'admin/schedule.php',
                     'admin/users.php', 'admin/settings.php', 'admin/groups.php', 'admin/login.php', 'admin/profile.php', 'admin/partials/header.php'] as $f) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            foreach ($m[1] as $k) {
                $k = stripslashes($k);
                if (!isset($hi[$k]) || $hi[$k] === '') {
                    $missing[] = "$f: $k";
                }
            }
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function testTranslationsKeepPlaceholdersAndHindiKeysExistInGujarati(): void
    {
        $gu = I18n::table('gu');
        $hi = I18n::table('hi');
        // Keys change together: a Hindi key is an English source string that Gujarati translates as well.
        $this->assertSame([], array_values(array_diff(array_keys($hi), array_keys($gu))), 'Hindi keys without a Gujarati translation');
        $bad = [];
        foreach (['gu' => $gu, 'hi' => $hi] as $l => $table) {
            foreach ($table as $k => $v) {
                preg_match_all('/:[a-z]+\\b/', (string) $k, $m);
                foreach (array_unique($m[0]) as $ph) {
                    // ":nd" (ordinal) is ":n" + "d": a translation may keep only the shorter placeholder.
                    $kept = false;
                    for ($len = strlen($ph); $len >= 2 && !$kept; $len--) {
                        $kept = str_contains((string) $v, substr($ph, 0, $len));
                    }
                    if (!$kept) {
                        $bad[] = "$l: $k";
                    }
                }
            }
        }
        $this->assertSame([], $bad, 'placeholders lost in translation');
    }
}
