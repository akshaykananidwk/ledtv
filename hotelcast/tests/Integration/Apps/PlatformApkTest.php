<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.7 Super Admin → APK Manager (docs/modules/apk_manager.md): platform-wide TV app releases.
 *
 * Covers: APK parsing (package / versionCode / versionName / signing certificate, also the real 2.5.0 build),
 * upload validation (not an APK, wrong package, unsigned, wrong signer, not newer), access (Super Admin only;
 * customer admin / reseller 403; CSRF), latest / required logic and the precedence with customer releases
 * (highest versionCode wins among {platform, own customer's}; selected roll-out), the device endpoint
 * (GET api/device/app-version, poll field app_update, APK download with resume, download counter) only for
 * authenticated devices, no updates for web players, "Update all TVs now" (online Android TVs only), delete
 * rules, the nav item only in the Super Admin console, translations.
 */
final class PlatformApkTest extends TestCase
{
    private const FIX = __DIR__ . '/../../fixtures/apk/';
    /** SHA-256 of the throwaway test key the fixture APKs are signed with (tests/fixtures/apk). */
    private const TEST_SIGNER = '7526eb47188b8f50f0d028b84d8a513365591022d890c4c2f5628c27064ee63e';

    private static string $url;
    private static array $h = [];
    /** @var array<string, array{uid:string, token:string, id:int, hotel:int}> */
    private static array $tv = [];
    private static array $sessions = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        AppReleases::forget();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        $r1 = DB::insert('resellers', ['name' => 'Reseller One', 'status' => 'active']);
        DB::insert('users', ['hotel_id' => null, 'username' => 'apkroot', 'email' => 'apkroot@platform.test', 'full_name' => 'Root', 'password_hash' => $pw, 'role' => 'platform_admin']);
        DB::insert('users', ['hotel_id' => null, 'reseller_id' => $r1, 'username' => 'apkres', 'email' => 'apkres@res.test', 'full_name' => 'Res', 'password_hash' => $pw, 'role' => 'reseller']);
        self::$h['my'] = 1;
        Settings::set('hotel_name', 'My Hotel');
        self::$h['alpha'] = Hotels::create(['name' => 'Alpha Inn', 'reseller_id' => $r1]);
        self::$h['beta'] = Hotels::create(['name' => 'Beta Suites']);
        foreach (['my' => ['101', '102'], 'alpha' => ['A1', 'A2'], 'beta' => ['B1']] as $k => $nums) {
            Tenant::run(self::$h[$k], static function () use ($nums): void {
                foreach ($nums as $n) {
                    DB::insert('rooms', ['room_number' => $n, 'name' => 'Screen ' . $n, 'floor' => '1']);
                }
            });
        }
        Hotels::createHotelUser(self::$h['alpha'], ['username' => 'alphaboss', 'email' => 'boss@alpha.test', 'password' => 'Passw0rd!'], 'super_admin');
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        Settings::setPlatform('platform_apk_signer', self::TEST_SIGNER);
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        self::register('tv101', '101', 'my');
        self::register('tv102', '102', 'my');
        self::register('tvA1', 'A1', 'alpha');
        self::register('tvA2', 'A2', 'alpha');
        self::register('tvB1', 'B1', 'beta');
        self::register('tvW', '101', 'my', ['platform' => 'web', 'app_version' => 'web-2.7', 'app_version_code' => 1]);
    }

    public static function tearDownAfterClass(): void
    {
        Tenant::set(1);
        AppReleases::forget();
        PlatformScreens::forget();
    }

    protected function setUp(): void
    {
        DB::query('DELETE FROM rate_limits');
        Tenant::set(1);
        Settings::flush();
        PlatformScreens::forget();
    }

    private static function register(string $name, string $room, string $hotelKey, array $extra = []): void
    {
        $key = (string) Settings::getFor(self::$h[$hotelKey], 'registration_key');
        $uid = 'tv-apk-' . strtolower($name) . '-0001';
        [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', $extra + ['device_id' => $uid, 'room_number' => $room, 'registration_key' => $key, 'app_version' => '2.5.0', 'app_version_code' => 13, 'model' => 'Model ' . $name]);
        self::assertSame(200, $s, (string) json_encode($j));
        $hid = self::$h[$hotelKey];
        self::$tv[$name] = ['uid' => $uid, 'token' => $j['data']['token'], 'hotel' => $hid,
            'id' => (int) DB::value('SELECT id FROM devices WHERE device_uid = :u AND hotel_id = :h', ['u' => $uid, 'h' => $hid])];
    }

    private static function as(string $user): AdminSession
    {
        return self::$sessions[$user] ??= new AdminSession(self::$url, $user);
    }

    private static function dev(string $name): array
    {
        $t = self::$tv[$name];
        return ['Authorization: Bearer ' . $t['token'], 'X-Device-Id: ' . $t['uid']];
    }

    /** GET api/device/app-version as a TV: [status, data]. */
    private static function appVersion(string $name): array
    {
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/app-version', null, self::dev($name));
        return [$s, $j['data'] ?? $j];
    }

    /** Multipart upload of a fixture as the given user (fields: required, rollout, hotels[], notes). */
    private static function upload(string $user, string $fixture, array $fields = [], ?string $csrf = null, string $name = ''): array
    {
        $s = self::as($user);
        $tmp = tempnam(sys_get_temp_dir(), 'apk');
        copy($fixture, $tmp);
        $form = ['_csrf' => $csrf ?? $s->csrf, 'op' => 'upload', 'apk' => new CURLFile($tmp, 'application/vnd.android.package-archive', $name !== '' ? $name : basename($fixture))];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                foreach (array_values($v) as $i => $x) {
                    $form[$k . '[' . $i . ']'] = (string) $x;
                }
            } else {
                $form[$k] = (string) $v;
            }
        }
        $res = TestEnv::http('POST', self::$url . 'admin/platform_apk.php', null, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], $s->jar, $form);
        @unlink($tmp);
        return $res;
    }

    private static function platformCount(): int
    {
        return (int) DB::value('SELECT COUNT(*) FROM apk_releases WHERE hotel_id IS NULL');
    }

    // ------------------------------------------------------------------ parsing

    public function testApkParserReadsManifestAndSigner(): void
    {
        $i = ApkInfo::read(self::FIX . 'tv_v90.apk');
        $this->assertSame(['package' => 'com.hotelcast.tv', 'version_code' => 90, 'version_name' => '9.0.0', 'signer_sha256' => self::TEST_SIGNER], $i);
        $this->assertSame('com.example.other', ApkInfo::read(self::FIX . 'other_pkg.apk')['package']);
        $this->assertNull(ApkInfo::read(self::FIX . 'unsigned_tv.apk')['signer_sha256']);
        // The real release build (UTF-16 string pool, v1+v2 signed with the release key).
        $real = dirname(HC_ROOT) . '/android/release';
        $real = is_dir($real) ? $real : dirname(TestEnv::$appSrc) . '/android/release';
        $files = glob($real . '/KrishnaCloud-TV-*.apk') ?: [];
        if ($files) {
            $r = ApkInfo::read($files[0]);
            $this->assertSame('com.hotelcast.tv', $r['package']);
            $this->assertGreaterThanOrEqual(13, $r['version_code']);
            $this->assertSame(AppReleases::DEFAULT_SIGNER, $r['signer_sha256']);
        }
        $txt = tempnam(sys_get_temp_dir(), 'na');
        file_put_contents($txt, 'not a zip');
        try {
            ApkInfo::read($txt);
            $this->fail('non-APK accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a valid Android APK', $e->getMessage());
        } finally {
            @unlink($txt);
        }
    }

    // ------------------------------------------------------------------ access

    public function testOnlySuperAdminAndCsrf(): void
    {
        [$s, , $html] = self::as('apkroot')->get('platform_apk.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html), 'PHP error on APK Manager');
        $this->assertStringContainsString('id="papkUpload"', $html);
        $this->assertStringContainsString('Required update (force)', $html);
        foreach (['alphaboss', 'apkres'] as $u) {
            [$s] = self::as($u)->get('platform_apk.php');
            $this->assertSame(403, $s, "$u GET");
            [$s] = self::upload($u, self::FIX . 'tv_v90.apk', ['required' => 1]);
            $this->assertSame(403, $s, "$u upload");
            [$s] = self::as($u)->post('platform_apk.php', ['op' => 'push_all']);
            $this->assertSame(403, $s, "$u push");
        }
        // CSRF: a wrong token is refused.
        [$s] = self::upload('apkroot', self::FIX . 'tv_v90.apk', ['required' => 1], 'bad-token');
        $this->assertContains($s, [400, 403, 419]);
        $this->assertSame(0, self::platformCount());
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'UPDATE_APP'"));
    }

    public function testNavItemOnlyInSuperAdminConsole(): void
    {
        [, , $html] = self::as('apkroot')->get('platform_overview.php');
        $this->assertMatchesRegularExpression('#href="[^"]*platform_apk\.php"#', $html);
        $this->assertStringContainsString('APK Manager', $html);
        [, , $html] = self::as('apkres')->get('reseller.php');
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*platform_apk\.php"#', $html);
        [, , $html] = self::as('alphaboss')->get('index.php');
        $this->assertDoesNotMatchRegularExpression('#href="[^"]*platform_apk\.php"#', $html);
        // Grouped under "Devices & content" right after Devices & screens.
        $this->assertContains('platform_apk', Panel::GROUPS['platform']['Devices & content']);
    }

    // ------------------------------------------------------------------ upload / precedence / device API

    public function testUploadValidationPrecedenceDeviceApiAndPush(): void
    {
        $root = 'apkroot';
        // Validation: not an APK, wrong package, unsigned, wrong signer → nothing stored.
        $txt = tempnam(sys_get_temp_dir(), 'na');
        file_put_contents($txt, "PK\x03\x04 broken");
        [$s, $j] = self::upload($root, $txt, [], null, 'fake.apk');
        @unlink($txt);
        $this->assertSame(422, $s);
        $this->assertStringContainsString('not a valid Android APK', (string) ($j['error']['message'] ?? json_encode($j)));
        [$s, $j] = self::upload($root, self::FIX . 'other_pkg.apk', ['required' => 1]);
        $this->assertSame(422, $s);
        $this->assertStringContainsString('com.example.other', (string) json_encode($j, JSON_UNESCAPED_SLASHES));
        [$s, $j] = self::upload($root, self::FIX . 'unsigned_tv.apk', ['required' => 1]);
        $this->assertSame(422, $s);
        $this->assertStringContainsString('v2/v3', (string) json_encode($j, JSON_UNESCAPED_SLASHES));
        Settings::setPlatform('platform_apk_signer', AppReleases::DEFAULT_SIGNER);
        [$s, $j] = self::upload($root, self::FIX . 'tv_v90.apk', ['required' => 1]);
        $this->assertSame(422, $s);
        $this->assertStringContainsString('different key', (string) json_encode($j));
        Settings::setPlatform('platform_apk_signer', self::TEST_SIGNER);
        $this->assertSame(0, self::platformCount());
        $this->assertSame([], glob(HC_ROOT . '/storage/apk/platform/*.apk') ?: []);

        // No release yet → no update for anyone.
        [$s, $d] = self::appVersion('tv101');
        $this->assertSame(200, $s);
        $this->assertNull($d['update']);

        // Upload v90 as required, all customers.
        [$s, $j] = self::upload($root, self::FIX . 'tv_v90.apk', ['required' => 1, 'rollout' => 'all', 'notes' => 'First platform build']);
        $this->assertSame(200, $s, (string) json_encode($j));
        $rel = DB::one('SELECT * FROM apk_releases WHERE hotel_id IS NULL');
        $this->assertSame(90, (int) $rel['version_code']);
        $this->assertSame('9.0.0', $rel['version_name']);
        $this->assertSame(1, (int) $rel['is_required']);
        $this->assertSame(self::TEST_SIGNER, $rel['signer_sha256']);
        $this->assertSame(hash_file('sha256', self::FIX . 'tv_v90.apk'), $rel['sha256']);
        // Same version again → refused (not newer).
        [$s, $j] = self::upload($root, self::FIX . 'tv_v90.apk', ['required' => 1]);
        $this->assertSame(422, $s);
        $this->assertStringContainsString('not newer', (string) json_encode($j));

        // Device endpoint: latest + required + sha256 + url, only with a valid token.
        [$s, $d] = self::appVersion('tvA1');
        $this->assertSame(200, $s);
        $u = $d['update'];
        $this->assertSame(90, $u['version_code']);
        $this->assertTrue($u['required']);
        $this->assertSame(90, $u['required_version_code']);
        $this->assertSame($rel['sha256'], $u['sha256']);
        $this->assertSame('platform', $u['source']);
        $this->assertStringEndsWith('api/device/apk/' . $rel['id'], $u['url']);
        $this->assertSame(13, $d['installed_version_code']);
        [$s] = TestEnv::http('GET', self::$url . 'api/device/app-version');
        $this->assertSame(401, $s);
        [$s] = TestEnv::http('GET', self::$url . 'api/device/app-version', null, ['Authorization: Bearer wrong', 'X-Device-Id: ' . self::$tv['tvA1']['uid']]);
        $this->assertSame(401, $s);
        // The poll tells a running TV too.
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['tvB1']['uid'] . '?hash=x', null, self::dev('tvB1'));
        $this->assertSame(200, $s);
        $this->assertSame(90, $j['data']['app_update']['version_code']);
        // Web player: no update in app-version nor poll, and no download.
        [$s, $d] = self::appVersion('tvW');
        $this->assertSame(200, $s);
        $this->assertNull($d['update']);
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['tvW']['uid'] . '?hash=x', null, self::dev('tvW'));
        $this->assertArrayNotHasKey('app_update', $j['data']);
        [$s] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $rel['id'], null, self::dev('tvW'));
        $this->assertSame(404, $s);

        // Download: full (counted) and resumed (Range, not counted); unauthenticated → 401.
        [$s, , $body, $head] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $rel['id'], null, self::dev('tvA1'));
        $this->assertSame(200, $s);
        $this->assertSame($rel['sha256'], hash('sha256', $body));
        $this->assertStringContainsStringIgnoringCase('x-content-sha256: ' . $rel['sha256'], $head);
        [$s, , $part] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $rel['id'], null, array_merge(self::dev('tvA1'), ['Range: bytes=100-']));
        $this->assertSame(206, $s);
        $this->assertSame(substr($body, 100), $part);
        [$s] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $rel['id']);
        $this->assertSame(401, $s);
        $this->assertSame(1, (int) DB::value('SELECT downloads FROM apk_releases WHERE id = :id', ['id' => $rel['id']]));

        // Precedence: Alpha's own customer release v95 (optional) is higher → offered to Alpha; required stays 90.
        Tenant::run(self::$h['alpha'], static fn () => DB::insert('apk_releases', ['version_name' => '9.5.0', 'version_code' => 95, 'file_path' => 'apk/h' . self::$h['alpha'] . '/own.apk', 'file_size' => 1, 'sha256' => str_repeat('b', 64), 'created_at' => now()]));
        $st = AppReleases::forHotel(self::$h['alpha']);
        $this->assertSame(95, (int) $st['latest']['version_code']);
        $this->assertSame(90, $st['required_code']);
        [, $d] = self::appVersion('tvA1');
        $this->assertSame(95, $d['update']['version_code']);
        $this->assertSame('customer', $d['update']['source']);
        $this->assertSame(90, $d['update']['required_version_code']);
        // Another customer never sees Alpha's own release.
        [, $d] = self::appVersion('tvB1');
        $this->assertSame(90, $d['update']['version_code']);
        $alphaOwn = (int) DB::value('SELECT id FROM apk_releases WHERE version_code = 95');
        [$s] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $alphaOwn, null, self::dev('tvB1'));
        $this->assertSame(404, $s);
        // A lower customer release does not win over the platform release.
        Tenant::run(self::$h['beta'], static fn () => DB::insert('apk_releases', ['version_name' => '8.0.0', 'version_code' => 80, 'file_path' => 'apk/x.apk', 'file_size' => 1, 'sha256' => str_repeat('c', 64), 'created_at' => now()]));
        $this->assertSame(90, (int) AppReleases::forHotel(self::$h['beta'])['latest']['version_code']);

        // Selected roll-out: v91 only for Beta → My Hotel stays on 90, Beta gets 91 (required).
        [$s, $j] = self::upload($root, self::FIX . 'tv_v91.apk', ['required' => 1, 'rollout' => 'selected', 'hotels' => [self::$h['beta']]]);
        $this->assertSame(200, $s, (string) json_encode($j));
        [, $d] = self::appVersion('tvB1');
        $this->assertSame(91, $d['update']['version_code']);
        $this->assertSame(91, $d['update']['required_version_code']);
        [, $d] = self::appVersion('tv101');
        $this->assertSame(90, $d['update']['version_code']);
        $v91 = (int) DB::value('SELECT id FROM apk_releases WHERE version_code = 91');
        [$s] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $v91, null, self::dev('tv101'));
        $this->assertSame(404, $s, 'release not rolled out to this customer');
        // Selected roll-out without customers → refused.
        [$s] = self::upload($root, self::FIX . 'tv_v92.apk', ['required' => 1, 'rollout' => 'selected']);
        $this->assertSame(422, $s);

        // Options: make v91 optional and roll out to all → required stays 90 for My Hotel, latest becomes 91.
        [$s] = self::as($root)->post('platform_apk.php', ['op' => 'save', 'id' => $v91, 'rollout' => 'all', 'notes' => 'Optional now']);
        $this->assertSame(302, $s);
        $st = AppReleases::forHotel(1);
        $this->assertSame(91, (int) $st['latest']['version_code']);
        $this->assertSame(90, $st['required_code']);
        $this->assertSame(0, (int) DB::value('SELECT is_required FROM apk_releases WHERE id = :id', ['id' => $v91]));

        // Outdated counters use each customer's effective latest (Alpha 95, others 91).
        PlatformScreens::forget();
        DB::query("UPDATE devices SET app_version_code = 91, app_version = '9.1.0' WHERE id = :id", ['id' => self::$tv['tv102']['id']]);
        $c = PlatformScreens::counters(null);
        $this->assertSame(4, $c['outdated'], 'tv101, tvA1, tvA2, tvB1 (web player and tv102 not)');
        $fleet = AppReleases::fleetStats();
        $this->assertSame(1, $fleet['on_latest']);
        $this->assertSame(5, $fleet['android']);
        [$s, , $html] = self::as($root)->get('platform_apk.php');
        $this->assertSame(200, $s);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('v9.1.0', $html);
        $this->assertStringContainsString('Latest', $html);
        // Overview tile shows the platform latest.
        [, , $html] = self::as($root)->get('platform_overview.php');
        $this->assertStringContainsString('Latest: v9.1.0', $html);

        // "Update all TVs now": online Android TVs with an older app; never the web player; tv102 is current.
        DB::query("UPDATE devices SET status = 'offline' WHERE id = :id", ['id' => self::$tv['tvA2']['id']]);
        DB::query("UPDATE devices SET status = 'online', last_ping = NOW() WHERE id IN (:a, :b, :c, :d)", ['a' => self::$tv['tv101']['id'], 'b' => self::$tv['tvA1']['id'], 'c' => self::$tv['tvB1']['id'], 'd' => self::$tv['tvW']['id']]);
        [$s] = self::as($root)->post('platform_apk.php', ['op' => 'push_all']);
        $this->assertSame(302, $s);
        $cmds = DB::all("SELECT device_id, payload FROM device_commands WHERE command = 'UPDATE_APP' AND status = 'pending' ORDER BY device_id");
        $ids = array_map(static fn ($r) => (int) $r['device_id'], $cmds);
        $this->assertEqualsCanonicalizing([self::$tv['tv101']['id'], self::$tv['tvA1']['id'], self::$tv['tvB1']['id']], $ids);
        $byDev = array_column(array_map(static fn ($r) => ['d' => (int) $r['device_id'], 'p' => json_decode($r['payload'], true)], $cmds), 'p', 'd');
        $this->assertSame(95, $byDev[self::$tv['tvA1']['id']]['version_code'], 'Alpha gets its own higher release');
        $this->assertSame(91, $byDev[self::$tv['tvB1']['id']]['version_code']);
        $this->assertSame(91, $byDev[self::$tv['tv101']['id']]['version_code']);
        // The TV receives it on its next poll.
        [, $j] = TestEnv::http('GET', self::$url . 'api/device/command/' . self::$tv['tv101']['uid'] . '?hash=x', null, self::dev('tv101'));
        $this->assertSame('UPDATE_APP', $j['data']['commands'][0]['command'] ?? null);
        // Pushing again replaces (does not duplicate) the pending command.
        self::as($root)->post('platform_apk.php', ['op' => 'push_all']);
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'UPDATE_APP' AND status = 'pending' AND device_id = :d", ['d' => self::$tv['tvB1']['id']]));
        $this->assertSame(0, (int) DB::value("SELECT COUNT(*) FROM device_commands WHERE command = 'UPDATE_APP' AND device_id = :d", ['d' => self::$tv['tvW']['id']]));
        // Platform → Devices & screens "Update app" also uses the effective release (platform release for My Hotel).
        PlatformScreens::$userOverride = DB::one("SELECT * FROM users WHERE username = 'apkroot'");
        $res = PlatformScreens::pushUpdate([self::$tv['tv102']['id']]);
        PlatformScreens::$userOverride = null;
        $this->assertSame(1, $res['tvs']);
        $this->assertSame([], $res['skipped']);

        // Delete rules: the latest platform release cannot be deleted; an older one can (file removed).
        [$s] = self::as($root)->post('platform_apk.php', ['op' => 'delete', 'id' => $v91]);
        $this->assertSame(302, $s);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM apk_releases WHERE id = :id', ['id' => $v91]));
        $this->assertStringContainsString('cannot be deleted', self::flashText($root));
        $file90 = HC_ROOT . '/storage/' . $rel['file_path'];
        $this->assertFileExists($file90);
        [$s] = self::as($root)->post('platform_apk.php', ['op' => 'delete', 'id' => $rel['id']]);
        $this->assertSame(302, $s);
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM apk_releases WHERE id = :id', ['id' => $rel['id']]));
        $this->assertFileDoesNotExist($file90);
        // A customer admin cannot delete platform releases through the customer APK Manager either.
        self::as('alphaboss')->post('apk.php', ['op' => 'delete', 'id' => $v91]);
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM apk_releases WHERE id = :id', ['id' => $v91]));
        // Required is now nowhere (v90 gone, v91 optional) → no block.
        $this->assertSame(0, AppReleases::forHotel(1)['required_code']);
        // Audit rows at platform level.
        $this->assertGreaterThan(0, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'apk_upload' AND hotel_id IS NULL"));
    }

    private static function flashText(string $user): string
    {
        [, , $html] = self::as($user)->get('platform_apk.php');
        return $html;
    }

    public function testTranslations(): void
    {
        foreach (['gu', 'hi'] as $lang) {
            $t = I18n::table($lang);
            preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(HC_ROOT . '/admin/platform_apk.php') . file_get_contents(HC_ROOT . '/core/AppReleases.php') . file_get_contents(HC_ROOT . '/core/ApkInfo.php'), $m);
            $missing = [];
            foreach (array_unique($m[1]) as $k) {
                $k = stripslashes($k);
                if (!isset($t[$k]) || $t[$k] === '') {
                    $missing[] = $k;
                }
            }
            $this->assertSame([], $missing, "$lang translations");
        }
        DB::update('users', ['language' => 'gu'], 'username = :u', ['u' => 'apkroot']);
        $s = new AdminSession(self::$url, 'apkroot');
        [$code, , $html] = $s->get('platform_apk.php');
        DB::update('users', ['language' => 'en'], 'username = :u', ['u' => 'apkroot']);
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('બધા TV હમણાં અપડેટ કરો', $html);
        $this->assertStringContainsString('ફરજિયાત અપડેટ (ફોર્સ)', $html);
    }
}
