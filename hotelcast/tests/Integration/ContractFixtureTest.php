<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * End-to-end contract check between the server and the Android TV app: builds a real Content
 * object with every 2.0 module active (guest welcome, checkout reminder, room-service QR, local
 * guide, Live TV/HDMI/cast, volume, sponsor ads, branding) and writes it to the Android unit-test
 * resources, where ServerContractTest.kt parses it with the app's own models.
 */
final class ContractFixtureTest extends TestCase
{
    public function testBuildFullContentAndExportForAndroid(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Installer::demoData();
        Settings::setMany([
            'guest_checkin_mode' => '1', 'guest_vacant_mode' => 'off',
            'guest_wifi_ssid' => 'Hotel-Guest', 'guest_wifi_password' => 'pa;ss:wo"rd',
            'wifi_ssid' => 'Hotel-Guest', 'wifi_password' => 'pa;ss:wo"rd',
            'guest_reminder_enabled' => '1', 'guest_reminder_time' => '00:00',
            'guest_menu_live_tv' => '1', 'guest_menu_inputs' => json_encode(['hdmi1' => 'Set-top box']),
            'guest_menu_cast' => '1',
            'tv_volume_enabled' => '1', 'volume_default' => '30', 'volume_max' => '90',
            'volume_night_enabled' => '1', 'volume_night_max' => '25', 'volume_night_from' => '22:00', 'volume_night_to' => '06:00',
        ]);
        $room = DB::one("SELECT * FROM rooms WHERE room_number = '101'");
        $vacantRoom = DB::one("SELECT * FROM rooms WHERE room_number = '102'");
        $this->assertNotNull($room);

        // Local guide content + sponsor ad
        $guideId = DB::insert('content_items', ['title' => 'Dwarka guide', 'type' => 'html', 'body' => '<h1>Dwarka</h1>', 'duration' => 15]);
        Settings::set('local_guide_content_id', (string) $guideId);
        $adContent = DB::insert('content_items', ['title' => 'Sponsor ad', 'type' => 'announcement', 'body' => 'Visit Shree Sweets', 'duration' => 10]);
        [$sp, $err] = Ads::validateSponsor(['name' => 'Shree Sweets', 'phone' => '9999999999']);
        $this->assertSame([], $err);
        $sponsorId = Ads::saveSponsor(null, $sp);
        [$camp, $err] = Ads::validateCampaign(['sponsor_id' => $sponsorId, 'content_id' => $adContent, 'name' => 'Diwali offer', 'target_type' => 'all', 'freq_type' => 'items', 'freq_items' => 2]);
        $this->assertSame([], $err);
        Ads::saveCampaign(null, $camp);

        // Ticker bar (2.2): the demo's hotel-wide ticker + a room ticker with every style field.
        [$tk, $err] = Tickers::validate(['message' => 'Room 101: ચેક-આઉટ 10:00 AM', 'target_type' => 'room', 'room_id' => (string) $room['id'],
            'bg_color' => '#7B1FA2', 'text_color' => '#FFFFFF', 'speed' => '6', 'font_size' => '30', 'height' => '64', 'position' => 'bottom',
            'reserve_space' => '1', 'priority' => '1', 'is_active' => '1']);
        $this->assertSame([], $err);
        Tickers::save(null, $tk);

        // Guest checks in (checkout today so the reminder is active)
        $stayId = Guests::checkIn((int) $room['id'], [
            'guest_name' => 'Rajesh Shah', 'salutation' => 'Mr', 'language' => 'gu',
            'checkout_at' => date('Y-m-d') . ' 23:59',
        ]);
        $this->assertGreaterThan(0, $stayId);
        Settings::bumpContentVersion();

        $c = ContentResolver::build(DB::one('SELECT * FROM rooms WHERE id = :id', ['id' => $room['id']]));
        $this->assertSame('gu', $c['guest']['language'] ?? null);
        $this->assertTrue($c['welcome']['show'] ?? false);
        $this->assertSame('stay-' . $stayId, $c['welcome']['id']);
        $this->assertSame('Hotel-Guest', $c['welcome']['wifi']['ssid'] ?? null);
        $this->assertNotEmpty($c['checkout_reminder']['show'] ?? false, 'Checkout reminder on checkout day');
        $this->assertTrue($c['services']['enabled'] ?? false);
        $ids = array_column($c['guest_menu'], 'id');
        $this->assertSame('services', $ids[0], 'Room service is first in the guest menu');
        foreach (['requests', 'feedback', 'guide', 'live_tv', 'hdmi1', 'cast'] as $want) {
            $this->assertContains($want, $ids);
        }
        $this->assertLessThan(array_search('live_tv', $ids, true), array_search('guide', $ids, true));
        $this->assertSame(30, $c['volume']['default'] ?? null);
        $this->assertSame(25, $c['volume']['night_max'] ?? null);
        $this->assertNotEmpty(array_filter($c['items'], fn ($i) => isset($i['ad_campaign_id'])), 'Sponsor ad inserted');
        $this->assertArrayHasKey('branding', $c);
        $this->assertSame(['text', 'messages', 'speed', 'bg_color', 'text_color', 'font_size', 'height', 'position', 'reserve_space'], array_keys($c['overlay']['ticker']));
        $this->assertCount(2, $c['overlay']['ticker']['messages'], 'room ticker + hotel-wide demo ticker');
        $this->assertSame([30, 64, 'bottom', true], [$c['overlay']['ticker']['font_size'], $c['overlay']['ticker']['height'], $c['overlay']['ticker']['position'], $c['overlay']['ticker']['reserve_space']]);

        $vacant = ContentResolver::build($vacantRoom);
        $this->assertSame('off', $vacant['mode']);
        $this->assertSame('vacant', $vacant['off_reason'] ?? null);
        $this->assertFalse($vacant['screen_on']);

        // Export for the Android contract test.
        $dir = dirname(TestEnv::$appSrc) . '/android/app/src/test/resources';
        if (is_dir(dirname($dir))) {
            @mkdir($dir, 0755, true);
            file_put_contents($dir . '/server_content_v2.json', json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            file_put_contents($dir . '/server_content_vacant.json', json_encode($vacant, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        Tenant::clear();
    }
}
