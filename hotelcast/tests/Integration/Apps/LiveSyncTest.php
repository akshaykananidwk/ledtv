<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Live video sync (2.6.1): the "Live video" settings (re-sync interval, maximum delay behind live) —
 * defaults, validation (only the listed values), saving through admin/settings.php (settings.manage,
 * CSRF), and `content.live_sync` in the TV poll so a change reaches the TVs. docs/modules/live_sync.md
 */
final class LiveSyncTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private static string $cred = '';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        self::$id['boss'] = DB::insert('users', ['hotel_id' => 1, 'username' => 'lsBoss', 'email' => 'lsboss@t.test', 'full_name' => 'Ls Boss', 'password_hash' => $pw, 'role' => 'super_admin']);
        self::$id['mgr'] = DB::insert('users', ['hotel_id' => 1, 'username' => 'lsMgr', 'email' => 'lsmgr@t.test', 'full_name' => 'Ls Mgr', 'password_hash' => $pw, 'role' => 'manager']);
        self::$id['room'] = DB::insert('rooms', ['room_number' => 'D1', 'name' => 'Darshan hall', 'floor' => '0']);
        self::$id['stream'] = DB::insert('content_items', ['title' => 'Temple darshan', 'type' => 'stream', 'url' => 'https://live.example.com/darshan/index.m3u8', 'duration' => 0, 'is_active' => 1, 'created_at' => now()]);
        DB::update('rooms', ['content_id' => self::$id['stream']], 'id = :id', ['id' => self::$id['room']]);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'lsBoss2', 'email' => 'ls2@t.test', 'password' => 'Passw0rd!']);
        Tenant::set(1);
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        $uid = 'tv-live-sync-d1-000';
        [$st, $j] = TestEnv::http('POST', self::$url . 'api/device/register', [
            'device_id' => $uid, 'room_number' => 'D1', 'registration_key' => (string) Settings::get('registration_key'),
            'app_version' => '2.6.1', 'app_version_code' => 15,
        ]);
        self::assertSame(200, $st, (string) json_encode($j));
        self::$cred = $uid . '|' . $j['data']['token'];
    }

    public static function tearDownAfterClass(): void
    {
        Tenant::forget();
        Settings::flush();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
    }

    /** `content` of a TV poll (no hash: always the full content). */
    private static function polledContent(): array
    {
        [$uid, $token] = explode('|', self::$cred);
        [$st, $j] = TestEnv::http('GET', self::$url . 'api/device/command?hash=', null, ['Authorization: Bearer ' . $token, 'X-Device-Id: ' . $uid]);
        self::assertSame(200, $st);
        self::assertIsArray($j['data']['content'] ?? null);
        return $j['data']['content'];
    }

    private static function stored(): array
    {
        Settings::flush();
        return [Settings::get(LiveSync::KEY_REFRESH), Settings::get(LiveSync::KEY_MAX_LAG)];
    }

    private static function reset(): void
    {
        DB::delete('system_settings', 'hotel_id = 1 AND setting_key IN (:a, :b)', ['a' => LiveSync::KEY_REFRESH, 'b' => LiveSync::KEY_MAX_LAG]);
        Settings::flush();
        Settings::bumpContentVersion();
    }

    public function testDefaultsWhenUnset(): void
    {
        self::reset();
        $this->assertSame(['refresh_min' => 5, 'max_lag_s' => 60], LiveSync::config());
        $c = ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => self::$id['room']]));
        $this->assertSame('assigned', $c['mode']);
        $this->assertSame('stream', $c['items'][0]['type']);
        $this->assertSame(['refresh_min' => 5, 'max_lag_s' => 60], $c['live_sync']);
        $this->assertSame(['refresh_min' => 5, 'max_lag_s' => 60], self::polledContent()['live_sync'] ?? null);

        // Invalid stored values (manual DB edit) fall back to the defaults.
        Settings::setMany([LiveSync::KEY_REFRESH => '7', LiveSync::KEY_MAX_LAG => '600']);
        Settings::flush();
        $this->assertSame(['refresh_min' => 5, 'max_lag_s' => 60], LiveSync::config());
        self::reset();
    }

    public function testValidationAcceptsOnlyTheListedValues(): void
    {
        foreach ([[0, 30], [2, 60], [5, 90], [10, 120], [15, 60], [30, 30], ['10', '90'], [' 2 ', '120']] as [$r, $l]) {
            [$data, $errors] = LiveSync::validate(['live_sync_refresh_min' => $r, 'live_sync_max_lag_s' => $l]);
            $this->assertSame([], $errors, "$r / $l");
            $this->assertSame([LiveSync::KEY_REFRESH => (string) (int) trim((string) $r), LiveSync::KEY_MAX_LAG => (string) (int) trim((string) $l)], $data);
        }
        foreach ([1, 3, 60, -5, '5.0', '5m', '', null, ['5'], '0x5'] as $bad) {
            [$data, $errors] = LiveSync::validate(['live_sync_refresh_min' => $bad, 'live_sync_max_lag_s' => 60]);
            $this->assertSame([], $data, 'refresh ' . json_encode($bad));
            $this->assertCount(1, $errors);
        }
        foreach ([0, 10, 45, 121, 180, 600, '60s', null] as $bad) {
            [$data, $errors] = LiveSync::validate(['live_sync_refresh_min' => 5, 'live_sync_max_lag_s' => $bad]);
            $this->assertSame([], $data, 'max lag ' . json_encode($bad));
            $this->assertCount(1, $errors);
        }
        $this->assertCount(2, LiveSync::validate([])[1]);
        $this->assertLessThanOrEqual(120, max(LiveSync::MAX_LAG_OPTIONS), 'never more than 2 minutes behind');
    }

    public function testSaveFromSettingsPageReachesTheTv(): void
    {
        self::reset();
        $boss = new AdminSession(self::$url, 'lsBoss');
        [$st, , $html] = $boss->get('settings.php?tab=display');
        $this->assertSame(200, $st);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('name="live_sync_refresh_min"', $html);
        $this->assertStringContainsString('name="live_sync_max_lag_s"', $html);
        $this->assertMatchesRegularExpression('/<option value="5" selected>/', $html, 'default 5 minutes preselected');
        $this->assertMatchesRegularExpression('/<option value="60" selected>/', $html, 'default 60 s preselected');

        $before = (string) self::polledContent()['hash'];
        $version = (string) Settings::get('content_version');
        [$st] = $boss->post('settings.php', ['op' => 'save_live_sync', 'tab' => 'display', 'live_sync_refresh_min' => '10', 'live_sync_max_lag_s' => '90']);
        $this->assertSame(302, $st);
        $this->assertSame(['10', '90'], self::stored());
        $this->assertNotSame($version, (string) Settings::get('content_version'), 'TVs get the change on the next poll');
        $c = self::polledContent();
        $this->assertSame(['refresh_min' => 10, 'max_lag_s' => 90], $c['live_sync']);
        $this->assertNotSame($before, (string) $c['hash'], 'content hash changes');

        [, , $html] = $boss->get('settings.php?tab=display');
        $this->assertMatchesRegularExpression('/<option value="10" selected>/', $html);
        $this->assertMatchesRegularExpression('/<option value="90" selected>/', $html);

        // Off
        $boss->post('settings.php', ['op' => 'save_live_sync', 'tab' => 'display', 'live_sync_refresh_min' => '0', 'live_sync_max_lag_s' => '120']);
        $this->assertSame(['0', '120'], self::stored());
        $this->assertSame(['refresh_min' => 0, 'max_lag_s' => 120], self::polledContent()['live_sync']);

        // Values outside the list are refused and nothing changes.
        foreach ([['3', '60'], ['5', '300'], ['5', '15'], ['abc', '60']] as [$r, $l]) {
            [$st] = $boss->post('settings.php', ['op' => 'save_live_sync', 'tab' => 'display', 'live_sync_refresh_min' => $r, 'live_sync_max_lag_s' => $l]);
            $this->assertSame(302, $st);
            $this->assertSame(['0', '120'], self::stored(), "$r / $l refused");
        }
        [, , $html] = $boss->get('settings.php?tab=display');
        $this->assertStringContainsString('Choose how often live videos are re-synced', $html, 'error message shown');

        // Other customers keep their own (default) values.
        Tenant::run(2, function (): void {
            Settings::flush();
            $this->assertSame(['refresh_min' => 5, 'max_lag_s' => 60], LiveSync::config());
        });
        Tenant::set(1);
        Settings::flush();
        self::reset();
    }

    public function testNeedsSettingsPermission(): void
    {
        self::reset();
        $mgr = new AdminSession(self::$url, 'lsMgr');
        [$st] = $mgr->get('settings.php?tab=display');
        $this->assertSame(403, $st);
        [$st] = $mgr->post('settings.php', ['op' => 'save_live_sync', 'tab' => 'display', 'live_sync_refresh_min' => '2', 'live_sync_max_lag_s' => '30']);
        $this->assertSame(403, $st);
        $this->assertSame([null, null], self::stored());
    }

    public function testCsrfIsRequired(): void
    {
        self::reset();
        $boss = new AdminSession(self::$url, 'lsBoss');
        $boss->csrf = 'forged-token';
        [$st] = $boss->post('settings.php', ['op' => 'save_live_sync', 'tab' => 'display', 'live_sync_refresh_min' => '2', 'live_sync_max_lag_s' => '30']);
        $this->assertSame(419, $st);
        $this->assertSame([null, null], self::stored());
    }

    public function testTranslations(): void
    {
        $keys = [];
        foreach (['admin/settings.php', 'core/LiveSync.php'] as $f) {
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/' . $f), $m);
            array_push($keys, ...array_map('stripslashes', $m[1]));
        }
        $new = array_keys(require HC_ROOT . '/lang/gu_live_sync.php');
        $this->assertSame($new, array_keys(require HC_ROOT . '/lang/hi_live_sync.php'), 'same strings in Gujarati and Hindi');
        foreach ($new as $k) {
            $this->assertContains($k, $keys, "$k is used");
        }
        $missing = [];
        foreach (['gu' => I18n::table('gu'), 'hi' => I18n::table('hi')] as $l => $t) {
            foreach ($new as $k) {
                if (($t[$k] ?? '') === '') {
                    $missing[] = "$l: $k";
                }
            }
        }
        $this->assertSame([], $missing);
        I18n::setLang('gu');
        $this->assertSame('લાઇવ વિડિયો', __('Live video'));
        $this->assertSame('5 મિનિટ', __(':n minutes', ['n' => 5]));
        I18n::setLang('en');
    }
}
