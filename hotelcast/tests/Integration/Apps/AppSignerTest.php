<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.10.1 TV app signing keys (migration 038, AppReleases signer filter): the original release key was lost and
 * TV app 2.6.3 is signed with a new key. A TV only gets updates it can install: old apps (no app_signer) never get
 * a release signed with the new key, apps that report the new key only get new-key releases; the heartbeat /
 * registration stores app_signer; push-all respects it; uploads accept both keys.
 */
final class AppSignerTest extends TestCase
{
    private static string $url;
    private static array $tv = [];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        AppReleases::forget();
        Tenant::set(1);
        foreach (['DeviceHealthTask', 'AnalyticsTask'] as $t) {
            Settings::setPlatform('task_last_' . $t, (string) (time() + 86400));
        }
        DB::insert('rooms', ['room_number' => 'A1', 'name' => 'Old app', 'floor' => '0']);
        DB::insert('rooms', ['room_number' => 'B1', 'name' => 'New app', 'floor' => '0']);
        Settings::flush();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
        foreach (['old' => ['A1', null, 16], 'new' => ['B1', AppReleases::DEFAULT_SIGNER, 17]] as $k => [$room, $signer, $code]) {
            $body = ['device_id' => 'tv-signer-' . $k . '-0001', 'room_number' => $room, 'registration_key' => 'TESTKEY123456789', 'app_version' => 'x', 'app_version_code' => $code];
            if ($signer) {
                $body['app_signer'] = $signer;
            }
            [$s, $j] = TestEnv::http('POST', self::$url . 'api/device/register', $body);
            self::assertSame(200, $s, (string) json_encode($j));
            self::$tv[$k] = ['uid' => $body['device_id'], 'token' => $j['data']['token']];
        }
    }

    private static function release(int $code, string $signer, bool $required = true): int
    {
        return DB::insert('apk_releases', ['hotel_id' => null, 'version_name' => '2.6.' . ($code - 14), 'version_code' => $code, 'file_path' => 'apk/x' . $code . '.apk',
            'file_size' => 1, 'sha256' => str_repeat('a', 64), 'notes' => '', 'is_required' => $required ? 1 : 0, 'rollout' => 'all', 'signer_sha256' => $signer, 'created_at' => now()]);
    }

    private static function appVersion(string $k): ?array
    {
        $t = self::$tv[$k];
        [$s, $j] = TestEnv::http('GET', self::$url . 'api/device/app-version', null, ['Authorization: Bearer ' . $t['token'], 'X-Device-Id: ' . $t['uid']]);
        self::assertSame(200, $s);
        return $j['data']['update'];
    }

    public function testEachTvOnlyGetsReleasesSignedWithItsKey(): void
    {
        $this->assertNull(DB::value("SELECT app_signer FROM devices WHERE device_uid = 'tv-signer-old-0001'"));
        $this->assertSame(AppReleases::DEFAULT_SIGNER, DB::value("SELECT app_signer FROM devices WHERE device_uid = 'tv-signer-new-0001'"));

        self::release(16, AppReleases::LEGACY_SIGNER);
        self::release(17, AppReleases::DEFAULT_SIGNER);
        // Old app (key not reported): never the new-key 2.6.3 (it could not install it and would be blocked).
        $old = self::appVersion('old');
        $this->assertSame(16, $old['version_code']);
        $this->assertSame(16, $old['required_version_code']);
        // New app: the new-key release.
        $new = self::appVersion('new');
        $this->assertSame(17, $new['version_code']);

        // Download URL of the other key is refused.
        $t = self::$tv['old'];
        $id17 = (int) DB::value('SELECT id FROM apk_releases WHERE version_code = 17');
        [$s] = TestEnv::http('GET', self::$url . 'api/device/apk/' . $id17, null, ['Authorization: Bearer ' . $t['token'], 'X-Device-Id: ' . $t['uid']]);
        $this->assertSame(404, $s);

        // Push-all: the old TV gets 16 (already installed → up to date), the new TV is up to date too.
        DB::query("UPDATE devices SET status = 'online', app_version_code = 15 WHERE device_uid = 'tv-signer-old-0001'");
        $r = AppReleases::pushToAll(null);
        $this->assertSame(1, $r['tvs']);
        $payload = json_decode((string) DB::value("SELECT c.payload FROM device_commands c JOIN devices d ON d.id = c.device_id WHERE d.device_uid = 'tv-signer-old-0001' AND c.command = 'UPDATE_APP'"), true);
        $this->assertSame(16, $payload['version_code']);
    }

    public function testHeartbeatStoresTheKeyAndUploadsAcceptBothKeys(): void
    {
        $t = self::$tv['old'];
        [$s] = TestEnv::http('POST', self::$url . 'api/device/heartbeat', ['app_version' => '2.6.3', 'app_version_code' => 17, 'app_signer' => strtoupper(AppReleases::DEFAULT_SIGNER)],
            ['Authorization: Bearer ' . $t['token'], 'X-Device-Id: ' . $t['uid']]);
        $this->assertSame(200, $s);
        $this->assertSame(AppReleases::DEFAULT_SIGNER, DB::value("SELECT app_signer FROM devices WHERE device_uid = 'tv-signer-old-0001'"));
        [$s] = TestEnv::http('POST', self::$url . 'api/device/heartbeat', ['app_version' => '2.6.3', 'app_version_code' => 17, 'app_signer' => 'not-a-hash'],
            ['Authorization: Bearer ' . $t['token'], 'X-Device-Id: ' . $t['uid']]);
        $this->assertSame(AppReleases::DEFAULT_SIGNER, DB::value("SELECT app_signer FROM devices WHERE device_uid = 'tv-signer-old-0001'"));

        $this->assertContains(AppReleases::LEGACY_SIGNER, AppReleases::acceptedSigners());
        $this->assertContains(AppReleases::DEFAULT_SIGNER, AppReleases::acceptedSigners());
        Settings::setPlatform('platform_apk_signer', AppReleases::LEGACY_SIGNER); // saved with the old default
        Settings::flush();
        $this->assertSame(AppReleases::DEFAULT_SIGNER, AppReleases::expectedSigner());
    }
}
