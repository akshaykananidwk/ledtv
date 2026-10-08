<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Business display apps (docs/modules/business_apps.md): shop offers (#4), class schedule (#6),
 * departures board (#7), KPI dashboard (#8) — pure logic with a fixed clock (discounts, NOW / NEXT,
 * board status / auto-hide, days since, shifts), TV rendering + live data in en / gu / hi, the four
 * management pages (CRUD, validation, CSRF, XSS, tenancy, permissions, restricted users) and the KPI
 * machine push API (token auth, rate limits).
 */
final class BusinessAppsTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'baMgr', 'staff' => 'baStaff', 'reception' => 'baRecep'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => $role]);
        }
        self::$id['r101'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Room 101', 'floor' => '1']);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'baBoss2', 'email' => 'ba2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            self::$id['h2offer'] = DB::insert('offers', ['title' => 'H2-OFFER-SECRET', 'price' => 10, 'created_at' => now()]);
            self::$id['h2class'] = DB::insert('class_sessions', ['name' => 'H2-CLASS-SECRET', 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'created_at' => now()]);
            self::$id['h2dep'] = DB::insert('departures', ['sched_time' => '12:00:00', 'destination' => 'H2-DEST-SECRET', 'created_at' => now()]);
            self::$id['h2kpi'] = DB::insert('kpi_tiles', ['label' => 'H2-KPI-SECRET', 'value_num' => 5, 'created_at' => now()]);
        });
        Tenant::set(1);
        DisplayApps::reset();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, self::$url);
    }

    public static function tearDownAfterClass(): void
    {
        Access::$userOverride = null;
        Access::forget();
        DisplayApps::reset();
        Tenant::forget();
        Settings::flush();
        I18n::reset();
    }

    protected function setUp(): void
    {
        Tenant::set(1);
        Settings::flush();
        DB::query('DELETE FROM rate_limits');
    }

    private static function ts(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get())))->getTimestamp();
    }

    private function assertNoXss(string $html, string $where): void
    {
        $this->assertStringNotContainsString('<script>alert(1)', $html, $where);
        $this->assertStringNotContainsString('<img src=x', $html, $where);
    }

    private static function png(): string
    {
        $png = tempnam(sys_get_temp_dir(), 'baimg') . '.png';
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        imagepng($im, $png);
        return $png;
    }

    // ------------------------------------------------------------------ pure logic, fixed clock

    public function testPureLogicWithFixedClock(): void
    {
        DisplayApps::all(); // loads core/Apps/*App.php
        $prevTz = date_default_timezone_get();
        date_default_timezone_set('Asia/Kolkata');
        try {
            // Money / discounts / countdown.
            $this->assertSame('12,34,567.50', BusinessApps::indian(1234567.5));
            $this->assertSame('₹1,299', BusinessApps::money(1299.0));
            $this->assertSame('999', BusinessApps::indian(999.0));
            $this->assertSame(33, Offers::discount(['discount_pct' => null, 'price' => 999, 'old_price' => 1499]));
            $this->assertSame(50, Offers::discount(['discount_pct' => 50, 'price' => 999, 'old_price' => 1499]), 'manual wins');
            $this->assertNull(Offers::discount(['discount_pct' => null, 'price' => 100, 'old_price' => null]));
            $this->assertNull(Offers::discount(['discount_pct' => null, 'price' => 100, 'old_price' => 90]));
            $this->assertSame('2d 04:13:22', OffersApp::countdown(2 * 86400 + 4 * 3600 + 13 * 60 + 22));
            $this->assertSame('00:00:59', OffersApp::countdown(59));
            $this->assertSame('00:00:00', OffersApp::countdown(-5));
            $this->assertSame('6:05 PM', BusinessApps::timeLabel('18:05:00', false));
            $this->assertSame('12:00 AM', BusinessApps::timeLabel('00:00:00', false));
            $this->assertSame('18:05', BusinessApps::timeLabel('18:05:00', true));
            $now = self::ts('2026-10-07 12:00:00');
            $this->assertSame('live', Offers::state(['is_active' => 1, 'valid_from' => null, 'valid_to' => '2026-10-07 12:00:01'], $now));
            $this->assertSame('expired', Offers::state(['is_active' => 1, 'valid_from' => null, 'valid_to' => '2026-10-07 12:00:00'], $now), 'valid_to is exclusive');
            $this->assertSame('scheduled', Offers::state(['is_active' => 1, 'valid_from' => '2026-10-08 00:00:00', 'valid_to' => null], $now));

            // NOW / NEXT: Wednesday 7 Oct 2026, 07:30.
            $s = static fn (int $id, string $a, string $b, string $days = '1,2,3,4,5,6,7'): array => ['id' => $id, 'name' => 'C' . $id, 'start_time' => $a, 'end_time' => $b, 'days' => $days];
            $sessions = [$s(1, '06:00:00', '07:00:00'), $s(2, '07:00:00', '08:00:00'), $s(3, '08:00:00', '09:00:00'), $s(4, '08:00:00', '08:30:00'), $s(5, '10:00:00', '11:00:00'), $s(6, '07:15:00', '07:45:00', '1,2'), $s(7, '09:00:00', '10:00:00', '3')];
            $at = static function (string $time) use ($sessions): array {
                $out = [];
                foreach (ClassSchedule::annotate($sessions, self::ts('2026-10-07 ' . $time)) as $r) {
                    $out[$r['id']] = $r['state'];
                }
                return $out;
            };
            $this->assertSame([1 => 'done', 2 => 'now', 4 => 'next', 3 => 'next', 7 => 'later', 5 => 'later'], $at('07:30:00'), 'Monday/Tuesday-only class is not on Wednesday');
            $this->assertSame('now', $at('07:00:00')[2], 'start is inclusive');
            $this->assertSame('done', $at('07:00:00')[1], 'end is exclusive');
            $this->assertSame('next', $at('05:00:00')[1]);
            $this->assertSame(['done'], array_values(array_unique($at('23:00:00'))));
            $this->assertSame([1, 2, 3, 4, 5, 6, 7], ClassSchedule::days(['days' => '7,1,2,3,4,5,6,9,x']));
            $this->assertSame('Mon–Fri', ClassSchedule::daysLabel(['days' => '1,2,3,4,5']));
            $this->assertSame('Mon, Wed, Fri', ClassSchedule::daysLabel(['days' => '1,3,5']));

            // Departures board at 10:00 on 7 Oct.
            $now = self::ts('2026-10-07 10:00:00');
            $d = static fn (int $id, string $t, array $x = []): array => $x + ['id' => $id, 'kind' => 'departure', 'sched_time' => $t, 'service_date' => null, 'number' => 'N' . $id,
                'destination' => 'D' . $id, 'platform' => '', 'status' => 'on_time', 'delay_min' => 0, 'remark' => '', 'status_date' => null, 'is_active' => 1];
            $rows = [
                $d(1, '09:40:00'),                                                                       // 20 min ago → hidden (hide after 10)
                $d(2, '09:55:00'),                                                                       // 5 min ago → shown
                $d(3, '09:30:00', ['status' => 'delayed', 'delay_min' => 35, 'status_date' => '2026-10-07']), // expected 10:05 → shown
                $d(4, '09:30:00', ['status' => 'delayed', 'delay_min' => 35, 'status_date' => '2026-10-06']), // yesterday's delay → on time today → hidden
                $d(5, '11:00:00', ['status' => 'cancelled', 'status_date' => '2026-10-06', 'remark' => 'OLD']), // yesterday's cancel → on time
                $d(6, '08:30:00', ['service_date' => '2026-10-08']),                                      // tomorrow only
                $d(7, '10:30:00', ['service_date' => '2026-10-06']),                                      // past date
                $d(8, '12:00:00', ['kind' => 'arrival']),
                $d(9, '13:00:00', ['is_active' => 0]),
                $d(10, '23:30:00'),                                                                      // beyond 12 h? no: 13.5 h → hidden
                $d(11, '09:00:00', ['status' => 'cancelled', 'delay_min' => 90, 'service_date' => '2026-10-07']), // cancelled 1 h ago → hidden
            ];
            $board = Departures::board($rows, $now, ['kind' => 'both', 'hide_after_min' => 10, 'lookahead_hours' => 12]);
            $keys = array_map(static fn (array $r): string => $r['id'] . '@' . $r['date'], $board);
            $this->assertSame(['3@2026-10-07', '2@2026-10-07', '5@2026-10-07', '8@2026-10-07'], $keys);
            $this->assertSame(['delayed', 35], [$board[0]['status_now'], $board[0]['delay_now']]);
            $this->assertSame(self::ts('2026-10-07 10:05:00'), $board[0]['eff_ts']);
            $this->assertSame(['on_time', 0, ''], [$board[2]['status_now'], $board[2]['delay_now'], $board[2]['remark_now']], 'daily rows reset every day');
            $this->assertSame(['3', '2', '5'], array_map(static fn ($r) => substr($r['number'], 1), Departures::board($rows, $now, ['kind' => 'departure', 'hide_after_min' => 10, 'lookahead_hours' => 12])));
            // Longer look-ahead brings tomorrow's early entries (dated tomorrow) and today's late ones.
            $keys = array_map(static fn (array $r): string => $r['id'] . '@' . $r['date'], Departures::board($rows, $now, ['kind' => 'departure', 'hide_after_min' => 0, 'lookahead_hours' => 24]));
            $this->assertContains('6@2026-10-08', $keys);
            $this->assertContains('10@2026-10-07', $keys);
            $this->assertContains('2@2026-10-08', $keys, 'daily rows also run tomorrow');
            $this->assertNotContains('2@2026-10-07', $keys, 'hide after 0 minutes');
            $this->assertSame(['on_time', 0, ''], Departures::statusOn($d(1, '09:00:00', ['status' => 'boarding', 'status_date' => '2026-10-06']), '2026-10-07'));
            $this->assertSame(['boarding', 0, ''], Departures::statusOn($d(1, '09:00:00', ['status' => 'boarding', 'service_date' => '2026-10-07']), '2026-10-07'));
            $this->assertSame('ઉ', Departures::destination(['destination' => 'U', 'destination_gu' => 'ઉ', 'destination_hi' => ''], 'gu'));
            $this->assertSame('U', Departures::destination(['destination' => 'U', 'destination_gu' => 'ઉ', 'destination_hi' => ''], 'hi'), 'falls back to English');

            // KPI: days since, thresholds, shifts.
            $now = self::ts('2026-10-07 01:30:00');
            $this->assertSame(128, Kpi::daysSince('2026-06-01', $now));
            $this->assertSame(0, Kpi::daysSince('2026-10-07', $now));
            $this->assertSame(0, Kpi::daysSince('2026-12-01', $now), 'future date → 0');
            $this->assertSame(0, Kpi::daysSince(null, $now));
            $this->assertSame('good', Kpi::level(950, 900, 600));
            $this->assertSame('warn', Kpi::level(700, 900, 600));
            $this->assertSame('bad', Kpi::level(600, 900, 600));
            $this->assertSame('good', Kpi::level(1, 2, 10), 'lower is better');
            $this->assertSame('warn', Kpi::level(5, 2, 10));
            $this->assertSame('bad', Kpi::level(12, 2, 10));
            $this->assertSame('', Kpi::level(5, null, null));
            $this->assertSame('warn', Kpi::level(5, 10, null));
            $this->assertSame('bad', Kpi::level(5, null, 7));
            $shifts = Kpi::parseShifts("A | 06:00 | 14:00\nB|14:00|22:00\nNight | 22:00 | 06:00\nbroken line\nX | 25:00 | 01:00");
            $this->assertCount(3, $shifts);
            $this->assertSame('Night', Kpi::currentShift($shifts, $now)['name'], 'overnight shift after midnight');
            $this->assertSame('Night', Kpi::currentShift($shifts, self::ts('2026-10-07 23:00:00'))['name']);
            $this->assertSame('A', Kpi::currentShift($shifts, self::ts('2026-10-07 06:00:00'))['name']);
            $this->assertSame('B', Kpi::currentShift($shifts, self::ts('2026-10-07 21:59:00'))['name']);
            $this->assertNull(Kpi::currentShift([], $now));
            $v = Kpi::view(['id' => 1, 'label' => 'Safe', 'type' => 'days_since', 'value_num' => 0, 'value_text' => '', 'since_date' => '2026-06-01', 'target' => 365, 'unit' => '', 'good_at' => 30, 'bad_at' => 7], $now);
            $this->assertSame(['128', 128.0, 'good', 35.1], [$v['value'], $v['num'], $v['level'], $v['progress']]);
            $v = Kpi::view(['id' => 2, 'label' => 'Q', 'type' => 'percent', 'value_num' => '97.50', 'value_text' => '', 'since_date' => null, 'target' => null, 'unit' => '', 'good_at' => 98, 'bad_at' => 95], $now);
            $this->assertSame(['97.5', '%', 97.5, 'warn'], [$v['value'], $v['unit'], $v['progress'], $v['level']]);
            $v = Kpi::view(['id' => 3, 'label' => 'P', 'type' => 'counter', 'value_num' => 12500, 'value_text' => '', 'since_date' => null, 'target' => 10000, 'unit' => 'pcs', 'good_at' => null, 'bad_at' => null], $now);
            $this->assertSame(['12,500', 100.0, '10,000 pcs'], [$v['value'], $v['progress'], $v['target_text']]);
            $this->assertSame(4, KpiDashboardApp::columns(7));
            $this->assertSame(2, KpiDashboardApp::columns(4));
        } finally {
            date_default_timezone_set($prevTz);
        }
    }

    // ------------------------------------------------------------------ offers

    public function testOffersDataAndRendering(): void
    {
        DB::query('DELETE FROM offers WHERE hotel_id = 1');
        $item = DisplayAppsTestKit::createItem('offers', ['layout' => 'grid']);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(200, $code);
        $this->assertSame([], $json['data']['offers']);
        $this->assertSame(15, $json['refresh_sec']);
        $this->assertSame(4, $json['data']['per_page']);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringContainsString('Add offers on the Offers page.', $html);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item, ['preview' => 1]);
        $this->assertStringContainsString('of-card', $html, 'preview shows sample offers');
        $this->assertStringContainsString('of-disc', $html);

        $ins = static fn (array $r): int => DB::insert('offers', $r + ['description' => '', 'badge' => '', 'created_at' => now()]);
        $ends = date('Y-m-d H:i:s', time() + 2 * 86400 + 3600);
        $live = $ins(['title' => 'LIVE-SAREE', 'price' => 1999, 'old_price' => 3499, 'badge' => 'Hot', 'valid_to' => $ends, 'sort' => 1]);
        $ins(['title' => 'LIVE-FIRST', 'price' => 100, 'sort' => 0, 'discount_pct' => 10]);
        $expiring = $ins(['title' => 'EXPIRING', 'price' => 5, 'valid_to' => date('Y-m-d H:i:s', time() + 3600), 'sort' => 2]);
        $ins(['title' => 'EXPIRED', 'price' => 5, 'valid_to' => date('Y-m-d H:i:s', time() - 60)]);
        $ins(['title' => 'FUTURE', 'price' => 5, 'valid_from' => date('Y-m-d H:i:s', time() + 3600)]);
        $ins(['title' => 'OFF', 'price' => 5, 'is_active' => 0]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(['LIVE-FIRST', 'LIVE-SAREE', 'EXPIRING'], array_column($json['data']['offers'], 'title'));
        $o = $json['data']['offers'][1];
        $this->assertSame([$live, '₹1,999', '₹3,499', 43, 'Hot', strtotime($ends) * 1000], [$o['id'], $o['price'], $o['old_price'], $o['discount'], $o['badge'], $o['ends']]);
        $this->assertSame([10, null, ''], [$json['data']['offers'][0]['discount'], $json['data']['offers'][0]['ends'], $json['data']['offers'][0]['old_price']]);
        $this->assertStringNotContainsString('H2-OFFER-SECRET', json_encode($json));
        // The expiring offer leaves the TV as soon as it ends (next refresh).
        DB::update('offers', ['valid_to' => date('Y-m-d H:i:s', time() - 1)], 'id = :id', ['id' => $expiring]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame(['LIVE-FIRST', 'LIVE-SAREE'], array_column($json['data']['offers'], 'title'));
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringContainsString('<s class="of-old">₹3,499</s>', $html);
        $this->assertStringContainsString('<b>43%</b>', $html);
        $this->assertMatchesRegularExpression('#data-ends="' . (strtotime($ends) * 1000) . '"><span>Ends in</span> <b>2d 0[01]:\d\d:\d\d</b>#', $html);
        $this->assertStringContainsString('class="hc-slides of-grid"', $html);
        // No countdown when switched off; Gujarati / Hindi texts.
        foreach (['gu', 'hi'] as $lang) {
            $tv = DisplayAppsTestKit::createItem('offers', ['show_countdown' => false, 'layout' => 'list'], ['lang' => $lang]);
            $html = DisplayAppsTestKit::assertRenders($this, self::$url, $tv);
            $this->assertStringNotContainsString('data-ends=', $html);
            $this->assertStringContainsString(e(I18n::translate('Today\'s offers', $lang)), $html);
            $this->assertNotSame('Today\'s offers', I18n::translate('Today\'s offers', $lang));
            [, $json] = DisplayAppsTestKit::data(self::$url, $tv);
            $this->assertSame(I18n::translate('Ends in', $lang), $json['data']['text']['ends_in']);
            $this->assertSame(5, $json['data']['per_page']);
        }
        // Config validation.
        $app = DisplayApps::find('offers');
        [$cfg] = $app->validate(['layout' => 'bogus', 'rotate_sec' => '1', 'max' => '999', 'currency' => '']);
        $this->assertSame(['hero', 3, 60, '₹'], [$cfg['layout'], $cfg['rotate_sec'], $cfg['max'], $cfg['currency']]);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testOffersAdminCrudTenancyAndXss(): void
    {
        DB::query('DELETE FROM offers WHERE hotel_id = 1');
        $s = new AdminSession(self::$url, 'baStaff');
        [$code, , $html] = $s->get('offers.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringNotContainsString('H2-OFFER-SECRET', $html);
        $this->assertStringContainsString('offers.php', $html);
        [$code, , $html] = $s->get('offers.php?action=new');
        $this->assertSame(200, $code);
        foreach (['name="title"', 'name="description"', 'name="price"', 'name="old_price"', 'name="discount_pct"', 'name="badge"', 'name="valid_from"', 'name="valid_to"', 'name="sort"', 'name="image"', 'name="is_active"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        foreach ([
            [['title' => ' '], 'The offer title is required.'],
            [['title' => 'x', 'price' => 'abc'], 'Offer price: enter a number between'],
            [['title' => 'x', 'price' => '200', 'old_price' => '100'], 'The offer price must not be higher than the old price.'],
            [['title' => 'x', 'discount_pct' => '150'], 'Discount %: enter a number between 0 and 99.'],
            [['title' => 'x', 'valid_from' => '2026-10-10T10:00', 'valid_to' => '2026-10-09T10:00'], 'The end must be after the start.'],
            [['title' => 'x', 'valid_to' => '31-12-2026'], 'Invalid date and time'],
        ] as [$in, $err]) {
            [$code, , $html] = $s->post('offers.php', ['op' => 'save', 'id' => 0] + $in);
            $this->assertSame(422, $code, $err);
            $this->assertStringContainsString(e($err), $html);
        }
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM offers WHERE hotel_id = 1'));
        [$code] = TestEnv::http('POST', self::$url . 'admin/offers.php', null, [], $s->jar, ['op' => 'save', 'id' => '0', 'title' => 'NO-CSRF']);
        $this->assertSame(419, $code);

        // Create with image + XSS.
        $png = self::png();
        [$code] = TestEnv::http('POST', self::$url . 'admin/offers.php', null, [], $s->jar, [
            '_csrf' => $s->csrf, 'op' => 'save', 'id' => '0', 'title' => 'સાડી ' . self::XSS, 'description' => 'Desc ' . self::XSS, 'price' => '1,299', 'old_price' => '1999',
            'discount_pct' => '', 'badge' => 'B' . self::XSS, 'valid_from' => '', 'valid_to' => date('Y-m-d\TH:i', time() + 86400), 'sort' => '2', 'is_active' => '1',
            'image' => new CURLFile($png, 'image/png', 'offer.png'),
        ]);
        @unlink($png);
        $this->assertSame(302, $code);
        $o = DB::one('SELECT * FROM offers WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame(['સાડી ' . self::XSS, '1299.00', '1999.00', null, 2, self::$id['baStaff']], [$o['title'], $o['price'], $o['old_price'], $o['discount_pct'], (int) $o['sort'], (int) $o['created_by']]);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $o['image_path']);
        [, , $html] = $s->get('offers.php');
        $this->assertNoXss($html, 'offers list');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('35% ', $html, 'computed discount');
        [, , $html] = $s->get('offers.php?action=edit&id=' . $o['id']);
        $this->assertNoXss($html, 'offer form');
        $tv = DisplayAppsTestKit::createItem('offers', ['heading' => 'H' . self::XSS, 'footer' => 'F' . self::XSS], [], 'T' . self::XSS);
        [, $html] = DisplayAppsTestKit::page(self::$url, $tv);
        $this->assertNoXss($html, 'offers TV page');
        $this->assertStringContainsString('સાડી &lt;script&gt;', $html);
        $this->assertStringContainsString('/uploads/' . $o['image_path'], $html);
        $this->assertStringContainsString('"title":"સાડી \u003Cscript\u003E', $html);

        // Edit: remove image; toggle; delete.
        [$code] = $s->post('offers.php', ['op' => 'save', 'id' => $o['id'], 'title' => 'Edited', 'price' => '50', 'old_price' => '', 'discount_pct' => '20', 'is_active' => 1, 'remove_image' => 1]);
        $this->assertSame(302, $code);
        $e = DB::one('SELECT * FROM offers WHERE id = :id', ['id' => $o['id']]);
        $this->assertSame(['Edited', 20, null], [$e['title'], (int) $e['discount_pct'], $e['image_path']]);
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $o['image_path']);
        $s->post('offers.php', ['op' => 'toggle', 'id' => $o['id']]);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM offers WHERE id = :id', ['id' => $o['id']]));
        [, $json] = DisplayAppsTestKit::data(self::$url, $tv);
        $this->assertSame([], $json['data']['offers']);

        // Tenancy: hotel 2 → 404 on hotel 1 offers and vice versa.
        $b = new AdminSession(self::$url, 'baBoss2');
        [, , $html] = $b->get('offers.php');
        $this->assertStringContainsString('H2-OFFER-SECRET', $html);
        $this->assertStringNotContainsString('Edited', $html);
        [$code] = $b->get('offers.php?action=edit&id=' . $o['id']);
        $this->assertSame(404, $code);
        foreach ([['op' => 'save', 'id' => $o['id'], 'title' => 'HACKED'], ['op' => 'toggle', 'id' => $o['id']], ['op' => 'delete', 'id' => $o['id']]] as $p) {
            [$code] = $b->post('offers.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        $this->assertSame('Edited', DB::value('SELECT title FROM offers WHERE id = :id', ['id' => $o['id']]));
        [$code] = $s->post('offers.php', ['op' => 'delete', 'id' => self::$id['h2offer']]);
        $this->assertSame(404, $code);
        $s->post('offers.php', ['op' => 'delete', 'id' => $o['id']]);
        $this->assertNull(DB::one('SELECT id FROM offers WHERE id = :id', ['id' => $o['id']]));
        $this->assertSame(1, (int) DB::value("SELECT COUNT(*) FROM activity_logs WHERE action = 'offer_delete' AND entity_id = :id", ['id' => $o['id']]));
        [$code] = (new AdminSession(self::$url, 'baRecep'))->get('offers.php');
        $this->assertSame(403, $code, 'reception may not manage offers');
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ class schedule

    public function testClassScheduleAdminAppAndTenancy(): void
    {
        DB::query('DELETE FROM class_sessions WHERE hotel_id = 1');
        $item = DisplayAppsTestKit::createItem('class_schedule', ['trainer_label' => 'Doctor', 'room_label' => 'Cabin']);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([200, 30], [$code, $json['refresh_sec']]);
        $this->assertStringContainsString('Add classes on the Class schedule page.', $json['data']['html']);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item, ['preview' => 1]);
        $this->assertStringContainsString('cs-row', $html, 'preview sample classes');

        $s = new AdminSession(self::$url, 'baStaff');
        [$code, , $html] = $s->get('class_schedule.php?action=new');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        foreach ([
            [['name' => '', 'days' => [1], 'start_time' => '06:00', 'end_time' => '07:00'], 'The class name is required.'],
            [['name' => 'x', 'start_time' => '06:00', 'end_time' => '07:00'], 'Choose at least one day.'],
            [['name' => 'x', 'days' => [1], 'start_time' => '08:00', 'end_time' => '07:00'], 'The end time must be after the start time.'],
            [['name' => 'x', 'days' => [1], 'start_time' => '25:00', 'end_time' => '07:00'], 'Enter a valid start and end time.'],
        ] as [$in, $err]) {
            [$code, , $html] = $s->post('class_schedule.php', ['op' => 'save', 'id' => 0] + $in);
            $this->assertSame(422, $code, $err);
            $this->assertStringContainsString(e($err), $html);
        }
        [$code] = TestEnv::http('POST', self::$url . 'admin/class_schedule.php', null, [], $s->jar, ['op' => 'save', 'id' => '0', 'name' => 'NO-CSRF']);
        $this->assertSame(419, $code);
        // All-day class (always NOW) with photo + XSS, plus a weekday class.
        $png = self::png();
        [$code] = TestEnv::http('POST', self::$url . 'admin/class_schedule.php', null, [], $s->jar, [
            '_csrf' => $s->csrf, 'op' => 'save', 'id' => '0', 'name' => 'યોગ ' . self::XSS, 'trainer' => 'Dr ' . self::XSS, 'room' => 'R' . self::XSS,
            'days[0]' => '1', 'days[1]' => '2', 'days[2]' => '3', 'days[3]' => '4', 'days[4]' => '5', 'days[5]' => '6', 'days[6]' => '7',
            'start_time' => '00:00', 'end_time' => '23:59', 'level' => 'beginner', 'color' => '#ff0000', 'is_active' => '1', 'photo' => new CURLFile($png, 'image/png', 'p.png'),
        ]);
        @unlink($png);
        $this->assertSame(302, $code);
        $c = DB::one('SELECT * FROM class_sessions WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame(['1,2,3,4,5,6,7', '00:00:00', '23:59:00', 'beginner', '#FF0000'], [$c['days'], $c['start_time'], $c['end_time'], $c['level'], strtoupper($c['color'])]);
        $this->assertFileExists(HC_ROOT . '/uploads/' . $c['photo_path']);
        [$code] = $s->post('class_schedule.php', ['op' => 'save', 'id' => 0, 'name' => 'Weekday', 'days' => [1, 3, 5], 'start_time' => '18:00', 'end_time' => '19:30', 'level' => 'bogus', 'is_active' => 1]);
        $this->assertSame(302, $code);
        $w = DB::one("SELECT * FROM class_sessions WHERE hotel_id = 1 AND name = 'Weekday'");
        $this->assertSame(['1,3,5', ''], [$w['days'], $w['level']]);
        [, , $html] = $s->get('class_schedule.php');
        $this->assertNoXss($html, 'class list');
        $this->assertStringContainsString('Mon, Wed, Fri', $html);
        $this->assertStringNotContainsString('H2-CLASS-SECRET', $html);

        // TV: today view with NOW, renamed labels, photo; week view.
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        if (date('H:i') !== '23:59') {
            $this->assertStringContainsString('cs-row hc-card cs-now', $json['data']['html']);
            $this->assertStringContainsString('cs-pill-now', $json['data']['html']);
        }
        $this->assertStringContainsString('<span class="cs-k">Doctor:</span>', $json['data']['html']);
        $this->assertStringContainsString('<span class="cs-k">Cabin:</span>', $json['data']['html']);
        $this->assertStringContainsString('/uploads/' . $c['photo_path'], $json['data']['html']);
        $this->assertStringNotContainsString('H2-CLASS-SECRET', $json['data']['html']);
        $this->assertNoXss($json['data']['html'], 'class data');
        $week = DisplayAppsTestKit::createItem('class_schedule', ['view' => 'week'], ['lang' => 'gu']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $week);
        $this->assertSame(7, preg_match_all('/class="cs-day[ "]/', $html));
        $this->assertStringContainsString('cs-day is-today', $html);
        $this->assertStringContainsString(I18n::translate('Monday', 'gu'), $html);
        $this->assertSame(3 + 7, preg_match_all('/class="cs-item[ "]/', $html), 'weekday class on Mon/Wed/Fri + all-day class every day');
        $this->assertNoXss($html, 'week view');
        $hi = DisplayAppsTestKit::createItem('class_schedule', [], ['lang' => 'hi']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $hi);
        if (date('H:i') !== '23:59') {
            $this->assertStringContainsString(e(I18n::translate('NOW', 'hi')), $html);
            $this->assertNotSame('NOW', I18n::translate('NOW', 'hi'));
        }

        // Toggle / delete; tenancy.
        $b = new AdminSession(self::$url, 'baBoss2');
        [$code] = $b->get('class_schedule.php?action=edit&id=' . $c['id']);
        $this->assertSame(404, $code);
        foreach ([['op' => 'save', 'id' => $c['id'], 'name' => 'HACK', 'days' => [1], 'start_time' => '01:00', 'end_time' => '02:00'], ['op' => 'toggle', 'id' => $c['id']], ['op' => 'delete', 'id' => $c['id']]] as $p) {
            [$code] = $b->post('class_schedule.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        [$code] = $s->post('class_schedule.php', ['op' => 'toggle', 'id' => self::$id['h2class']]);
        $this->assertSame(404, $code);
        $s->post('class_schedule.php', ['op' => 'toggle', 'id' => $c['id']]);
        $this->assertSame(0, (int) DB::value('SELECT is_active FROM class_sessions WHERE id = :id', ['id' => $c['id']]));
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringNotContainsString('યોગ', $json['data']['html'], 'hidden classes leave the TV');
        $s->post('class_schedule.php', ['op' => 'delete', 'id' => $c['id']]);
        $this->assertNull(DB::one('SELECT id FROM class_sessions WHERE id = :id', ['id' => $c['id']]));
        $this->assertFileDoesNotExist(HC_ROOT . '/uploads/' . $c['photo_path']);
        [$code] = (new AdminSession(self::$url, 'baRecep'))->get('class_schedule.php');
        $this->assertSame(403, $code);
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ departures

    public function testDeparturesAdminQuickButtonsAndBoard(): void
    {
        DB::query('DELETE FROM departures WHERE hotel_id = 1');
        $item = DisplayAppsTestKit::createItem('departures', ['mode' => 'both', 'platform_label' => 'gate']);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([200, 15], [$code, $json['refresh_sec']]);
        $this->assertStringContainsString('Add entries on the Departures board page.', $json['data']['html']);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item, ['preview' => 1]);
        $this->assertStringContainsString('df-st-boarding', $html, 'preview sample board');

        // Reception (counter staff) manages the board.
        $s = new AdminSession(self::$url, 'baRecep');
        [$code, , $html] = $s->get('departures.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        foreach ([
            [['sched_time' => '', 'destination' => 'X', 'daily' => 1], 'Enter a valid time.'],
            [['sched_time' => '10:00', 'destination' => '', 'daily' => 1], 'The destination is required.'],
            [['sched_time' => '10:00', 'destination' => 'X', 'daily' => 0, 'service_date' => ''], 'Choose a date or tick'],
            [['sched_time' => '10:00', 'destination' => 'X', 'daily' => 0, 'service_date' => '2026-02-31'], 'Invalid date'],
        ] as [$in, $err]) {
            [$code, , $html] = $s->post('departures.php', ['op' => 'save', 'id' => 0] + $in);
            $this->assertSame(422, $code, $err);
            $this->assertStringContainsString(e($err), $html);
        }
        [$code] = TestEnv::http('POST', self::$url . 'admin/departures.php', null, [], $s->jar, ['op' => 'save', 'id' => '0', 'destination' => 'NO-CSRF']);
        $this->assertSame(419, $code);

        // A daily departure in ~1 hour (with XSS) and a dated arrival.
        $t = date('H:i', time() + 3600);
        [$code] = $s->post('departures.php', ['op' => 'save', 'id' => 0, 'kind' => 'departure', 'sched_time' => $t, 'daily' => 1, 'number' => 'GJ' . self::XSS,
            'destination' => 'Ahmedabad' . self::XSS, 'destination_gu' => 'અમદાવાદ' . self::XSS, 'destination_hi' => '', 'platform' => '3', 'status' => 'on_time', 'remark' => 'R' . self::XSS, 'is_active' => 1]);
        $this->assertSame(302, $code);
        $dep = DB::one('SELECT * FROM departures WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertNull($dep['service_date']);
        $this->assertSame(substr($t, 0, 5) . ':00', $dep['sched_time']);
        $arrTime = date('H:i', time() + 1800);
        $arrDate = date('Y-m-d', time() + 1800);
        [$code] = $s->post('departures.php', ['op' => 'save', 'id' => 0, 'kind' => 'arrival', 'sched_time' => $arrTime, 'daily' => 0, 'service_date' => $arrDate,
            'number' => 'AI 101', 'destination' => 'Mumbai', 'platform' => '1', 'status' => 'delayed', 'delay_min' => 20, 'is_active' => 1]);
        $this->assertSame(302, $code);
        $arr = DB::one("SELECT * FROM departures WHERE hotel_id = 1 AND number = 'AI 101'");
        $this->assertSame([$arrDate, 'delayed', 20], [$arr['service_date'], $arr['status'], (int) $arr['delay_min']]);

        [, , $html] = $s->get('departures.php');
        $this->assertNoXss($html, 'departures admin');
        $this->assertStringContainsString('data-board="' . $dep['id'] . '"', $html);
        $this->assertStringContainsString('Delayed +15', $html);
        $this->assertStringNotContainsString('H2-DEST-SECRET', $html);

        // Quick buttons on the occurrence shown on the board.
        $occ = null;
        foreach (Departures::board(Departures::candidates(time()), time(), ['kind' => 'both', 'hide_after_min' => 60, 'lookahead_hours' => 24]) as $b) {
            if ((int) $b['id'] === (int) $dep['id']) {
                $occ = $b;
            }
        }
        $this->assertNotNull($occ);
        $q = static fn (string $st, int $add = 0): array => ['op' => 'status', 'id' => $dep['id'], 'date' => $occ['date'], 'status' => $st, 'add' => $add];
        [$code, , , $head] = $s->post('departures.php', $q('delayed', 15));
        $this->assertSame(302, $code);
        $this->assertStringContainsString('#d' . $dep['id'], $head);
        $s->post('departures.php', $q('delayed', 15));
        $row = Departures::find((int) $dep['id']);
        $this->assertSame(['delayed', 30, $occ['date']], [$row['status'], (int) $row['delay_min'], $row['status_date']]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringContainsString('df-st-delayed', $json['data']['html']);
        $this->assertStringContainsString('Delayed 30 min', $json['data']['html']);
        $this->assertStringContainsString('Expected ', $json['data']['html']);
        $s->post('departures.php', $q('boarding'));
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringContainsString('df-st-boarding', $json['data']['html']);
        $s->post('departures.php', $q('cancelled'));
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringContainsString('df-st-cancelled', $json['data']['html']);
        $s->post('departures.php', $q('on_time'));
        $row = Departures::find((int) $dep['id']);
        $this->assertSame(['on_time', 0, ''], [$row['status'], (int) $row['delay_min'], $row['remark']]);
        [$code] = $s->post('departures.php', $q('bogus'));
        $this->assertSame(302, $code);
        $this->assertSame('on_time', DB::value('SELECT status FROM departures WHERE id = :id', ['id' => $dep['id']]), 'unknown status ignored');
        // A dated row cannot get a status for another day.
        $s->post('departures.php', ['op' => 'status', 'id' => $arr['id'], 'date' => date('Y-m-d', strtotime($arrDate . ' +1 day')), 'status' => 'cancelled']);
        $this->assertSame('delayed', DB::value('SELECT status FROM departures WHERE id = :id', ['id' => $arr['id']]));

        // Board: XSS-safe, gate column, GU destination, arrival tag, hotel 2 hidden.
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $board = $json['data']['html'];
        $this->assertNoXss($board, 'board');
        $this->assertStringContainsString('>Gate<', $board);
        $this->assertStringContainsString('>ARR<', $board);
        $this->assertStringNotContainsString('H2-DEST-SECRET', $board);
        $gu = DisplayAppsTestKit::createItem('departures', [], ['lang' => 'gu']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $gu);
        $this->assertStringContainsString('અમદાવાદ&lt;script&gt;', $html);
        $this->assertStringContainsString('<small>Ahmedabad&lt;script&gt;', $html, 'bilingual English line');
        $this->assertStringContainsString(e(I18n::translate('Departures', 'gu')), $html);
        $this->assertStringNotContainsString('AI 101', $html, 'departures only');
        $hi = DisplayAppsTestKit::createItem('departures', ['mode' => 'arrival', 'rows_per_page' => 12], ['lang' => 'hi']);
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $hi);
        $this->assertStringContainsString('AI 101', $html);
        $this->assertStringContainsString('df-dense', $html);
        $this->assertStringContainsString(e(I18n::translate('Delayed :n min', 'hi', ['n' => 20])), $html);

        // Tenancy.
        $b = new AdminSession(self::$url, 'baBoss2');
        [$code] = $b->get('departures.php?action=edit&id=' . $dep['id']);
        $this->assertSame(404, $code);
        foreach ([$q('cancelled'), ['op' => 'toggle', 'id' => $dep['id']], ['op' => 'delete', 'id' => $dep['id']], ['op' => 'save', 'id' => $dep['id'], 'sched_time' => '10:00', 'destination' => 'HACK', 'daily' => 1]] as $p) {
            [$code] = $b->post('departures.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        $this->assertSame('on_time', DB::value('SELECT status FROM departures WHERE id = :id', ['id' => $dep['id']]));
        [$code] = $s->post('departures.php', ['op' => 'status', 'id' => self::$id['h2dep'], 'date' => date('Y-m-d'), 'status' => 'cancelled']);
        $this->assertSame(404, $code);
        // Toggle + delete.
        $s->post('departures.php', ['op' => 'toggle', 'id' => $arr['id']]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringNotContainsString('AI 101', $json['data']['html']);
        $s->post('departures.php', ['op' => 'delete', 'id' => $arr['id']]);
        $this->assertNull(Departures::find((int) $arr['id']));
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ KPI dashboard + push API

    public function testKpiAdminQuickButtonsAndDashboard(): void
    {
        DB::query('DELETE FROM kpi_tiles WHERE hotel_id = 1');
        $item = DisplayAppsTestKit::createItem('kpi_dashboard', ['messages' => "Wear helmet\n" . self::XSS, 'shifts' => "Day | 00:00 | 12:00\nNight | 12:00 | 00:00"]);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([200, 10], [$code, $json['refresh_sec']]);
        $this->assertStringContainsString('Add tiles on the KPI dashboard page.', $json['data']['html']);
        $this->assertMatchesRegularExpression('/^(Day|Night) · /', $json['data']['shift']);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item, ['preview' => 1]);
        $this->assertStringContainsString('kp-tile', $html);
        $this->assertStringContainsString('kp-ticker', $html);
        $this->assertNoXss($html, 'kpi ticker');

        $s = new AdminSession(self::$url, 'baStaff');
        foreach ([
            [['label' => '', 'type' => 'counter'], 'The tile name is required.'],
            [['label' => 'x', 'type' => 'percent', 'value_num' => '120'], 'A percent value must be between 0 and 100.'],
            [['label' => 'x', 'type' => 'days_since'], 'Enter the date to count the days from.'],
            [['label' => 'x', 'type' => 'days_since', 'since_date' => date('Y-m-d', time() + 3 * 86400)], 'The date cannot be in the future.'],
            [['label' => 'x', 'type' => 'counter', 'target' => '-5'], 'Target: enter a number between'],
        ] as [$in, $err]) {
            [$code, , $html] = $s->post('kpi.php', ['op' => 'save', 'id' => 0] + $in);
            $this->assertSame(422, $code, $err);
            $this->assertStringContainsString(e($err), $html);
        }
        [$code] = TestEnv::http('POST', self::$url . 'admin/kpi.php', null, [], $s->jar, ['op' => 'save', 'id' => '0', 'label' => 'NO-CSRF']);
        $this->assertSame(419, $code);
        $mk = function (array $in) use ($s): array {
            [$code] = $s->post('kpi.php', ['op' => 'save', 'id' => 0, 'is_active' => 1] + $in);
            $this->assertSame(302, $code, json_encode($in));
            return DB::one('SELECT * FROM kpi_tiles WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        };
        $since = date('Y-m-d', strtotime('-128 days'));
        $safe = $mk(['label' => 'Days without accident' . self::XSS, 'type' => 'days_since', 'since_date' => $since, 'good_at' => 30, 'bad_at' => 7, 'sort' => 1]);
        $prod = $mk(['label' => 'Production', 'type' => 'counter', 'value_num' => '820', 'target' => '1,000', 'unit' => 'pcs' . self::XSS, 'good_at' => 900, 'bad_at' => 600, 'sort' => 2]);
        $text = $mk(['label' => 'Line 2', 'type' => 'text', 'value_text' => 'Running', 'sort' => 3]);
        $this->assertSame(['820.00', '1000.00'], [$prod['value_num'], $prod['target']]);

        [, , $html] = $s->get('kpi.php');
        $this->assertNoXss($html, 'kpi list');
        $this->assertStringContainsString('>128', $html);
        $this->assertStringNotContainsString('H2-KPI-SECRET', $html);
        // Quick buttons: form post (redirect) and AJAX (JSON).
        [$code, , , $head] = $s->post('kpi.php', ['op' => 'quick', 'id' => $prod['id'], 'do' => 'add', 'value' => '1']);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('#t' . $prod['id'], $head);
        $ajax = function (array $f) use ($s): array {
            return TestEnv::http('POST', self::$url . 'admin/kpi.php', null, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'], $s->jar, ['_csrf' => $s->csrf] + $f);
        };
        [$code, $j] = $ajax(['op' => 'quick', 'id' => (string) $prod['id'], 'do' => 'add', 'value' => '-1']);
        $this->assertSame(200, $code);
        $this->assertSame(['820', 'warn'], [$j['data']['value'], $j['data']['level']]);
        [, $j] = $ajax(['op' => 'quick', 'id' => (string) $prod['id'], 'do' => 'set', 'value' => '950']);
        $this->assertSame(['950', 'good'], [$j['data']['value'], $j['data']['level']]);
        [$code, $j] = $ajax(['op' => 'quick', 'id' => (string) $prod['id'], 'do' => 'set', 'value' => 'abc']);
        $this->assertSame(422, $code);
        $this->assertFalse($j['ok']);
        [, $j] = $ajax(['op' => 'quick', 'id' => (string) $text['id'], 'do' => 'set', 'value' => 'Stopped ' . self::XSS]);
        $this->assertSame('Stopped ' . self::XSS, $j['data']['value']);
        [$code] = $ajax(['op' => 'quick', 'id' => (string) $safe['id'], 'do' => 'add', 'value' => '1']);
        $this->assertSame(422, $code, 'days-since tiles cannot be counted up');
        [$code] = $s->post('kpi.php', ['op' => 'quick', 'id' => $safe['id'], 'do' => 'reset']);
        $this->assertSame(302, $code);
        $this->assertSame(date('Y-m-d'), DB::value('SELECT since_date FROM kpi_tiles WHERE id = :id', ['id' => $safe['id']]));
        DB::update('kpi_tiles', ['since_date' => $since], 'id = :id', ['id' => $safe['id']]);

        // Dashboard JSON / page.
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $h = $json['data']['html'];
        $this->assertNoXss($h, 'kpi data');
        $this->assertStringContainsString('<b>128</b>', $h);
        $this->assertStringContainsString('kp-tile hc-card kp-t-days_since kp-good', $h);
        $this->assertStringContainsString('<b>950</b>', $h);
        $this->assertStringContainsString('width:95%', $h);
        $this->assertStringContainsString('Target: 1,000 pcs&lt;script&gt;', $h);
        $this->assertStringContainsString('kp-cols-3', $h);
        $this->assertStringNotContainsString('H2-KPI-SECRET', $h);
        foreach (['gu', 'hi'] as $lang) {
            $tv = DisplayAppsTestKit::createItem('kpi_dashboard', [], ['lang' => $lang]);
            $html = DisplayAppsTestKit::assertRenders($this, self::$url, $tv);
            $this->assertStringContainsString(e(I18n::translate('days', $lang)), $html);
            $this->assertStringContainsString(e(I18n::translate('KPI dashboard', $lang)), $html);
        }
        $app = DisplayApps::find('kpi_dashboard');
        [, $errors] = $app->validate(['shifts' => "Good | 06:00 | 14:00\nbad line"]);
        $this->assertNotEmpty($errors);

        // Tenancy + permissions.
        $b = new AdminSession(self::$url, 'baBoss2');
        foreach ([['op' => 'quick', 'id' => $prod['id'], 'do' => 'add', 'value' => 5], ['op' => 'token', 'id' => $prod['id']], ['op' => 'delete', 'id' => $prod['id']], ['op' => 'toggle', 'id' => $prod['id']]] as $p) {
            [$code] = $b->post('kpi.php', $p);
            $this->assertSame(404, $code, $p['op']);
        }
        [$code] = $b->get('kpi.php?action=edit&id=' . $prod['id']);
        $this->assertSame(404, $code);
        $this->assertSame('950.00', DB::value('SELECT value_num FROM kpi_tiles WHERE id = :id', ['id' => $prod['id']]));
        [$code] = (new AdminSession(self::$url, 'baRecep'))->get('kpi.php');
        $this->assertSame(403, $code);
        $s->post('kpi.php', ['op' => 'toggle', 'id' => $text['id']]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringNotContainsString('Stopped', $json['data']['html']);
        $s->post('kpi.php', ['op' => 'delete', 'id' => $text['id']]);
        $this->assertNull(Kpi::find((int) $text['id']));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testKpiPushApiTokenAuthAndRateLimit(): void
    {
        DB::query('DELETE FROM kpi_tiles WHERE hotel_id = 1');
        $prod = DB::insert('kpi_tiles', ['label' => 'Count', 'type' => 'counter', 'value_num' => 10, 'created_at' => now()]);
        $safe = DB::insert('kpi_tiles', ['label' => 'Safe', 'type' => 'days_since', 'since_date' => date('Y-m-d', strtotime('-5 days')), 'created_at' => now()]);
        $txt = DB::insert('kpi_tiles', ['label' => 'Txt', 'type' => 'text', 'created_at' => now()]);
        $s = new AdminSession(self::$url, 'baStaff');
        // Turn on machine push: the token is shown once on the edit page, only its hash is stored.
        [$code, , , $head] = $s->post('kpi.php', ['op' => 'token', 'id' => $prod]);
        $this->assertSame(302, $code);
        $this->assertStringContainsString('action=edit', $head);
        [, , $html] = $s->get('kpi.php?action=edit&id=' . $prod);
        $this->assertSame(1, preg_match('/value="(kpi[0-9a-f]{48})"/', $html, $m));
        $token = $m[1];
        $row = Kpi::find($prod);
        $this->assertSame([hash('sha256', $token), substr($token, -4)], [$row['push_token_hash'], $row['push_token_hint']]);
        $this->assertStringNotContainsString($token, json_encode(DB::all('SELECT * FROM kpi_tiles')));
        [, , $html] = $s->get('kpi.php?action=edit&id=' . $prod);
        $this->assertStringNotContainsString($token, $html, 'shown only once');
        $this->assertStringContainsString(substr($token, -4), $html);
        $tokSafe = Kpi::newToken($safe);
        $tokTxt = Kpi::newToken($txt);
        $tokH2 = Tenant::run(2, fn () => Kpi::newToken(self::$id['h2kpi']));

        $push = static function (?string $tok, array $body, bool $bearer = true): array {
            $h = $tok !== null && $bearer ? ['Authorization: Bearer ' . $tok] : [];
            if ($tok !== null && !$bearer) {
                $body['token'] = $tok;
            }
            return TestEnv::http('POST', self::$url . 'api/kpi/push', $body, $h);
        };
        [$code, $j] = $push(null, ['value' => 1]);
        $this->assertSame([401, 'INVALID_TOKEN'], [$code, $j['error']['code']]);
        [$code] = $push('kpi' . str_repeat('0', 48), ['value' => 1]);
        $this->assertSame(401, $code);
        [$code] = TestEnv::http('GET', self::$url . 'api/kpi/push');
        $this->assertSame(405, $code);
        [$code, $j] = $push($token, ['value' => 830]);
        $this->assertSame(200, $code);
        $this->assertSame(['830', 830], [$j['data']['value'], (int) $j['data']['num']]);
        [, $j] = $push($token, ['add' => 5], false);
        $this->assertSame('835', $j['data']['value'], 'token in the body works too');
        [$code, $j] = $push($token, ['value' => 'abc']);
        $this->assertSame([400, 'VALIDATION_ERROR'], [$code, $j['error']['code']]);
        [$code] = $push($token, ['nothing' => 1]);
        $this->assertSame(400, $code);
        [, $j] = $push($tokTxt, ['value' => 'Line stopped']);
        $this->assertSame('Line stopped', $j['data']['value']);
        [, $j] = $push($tokSafe, ['reset' => true]);
        $this->assertSame('0', $j['data']['value']);
        [, $j] = $push($tokSafe, ['since' => date('Y-m-d', strtotime('-40 days'))]);
        $this->assertSame('40', $j['data']['value']);
        [$code] = $push($tokSafe, ['value' => 3]);
        $this->assertSame(400, $code);
        $this->assertNotNull(DB::value('SELECT pushed_at FROM kpi_tiles WHERE id = :id', ['id' => $prod]));
        // A token only reaches its own tile / hotel.
        [, $j] = $push($tokH2, ['value' => 77]);
        $this->assertSame((int) self::$id['h2kpi'], $j['data']['id']);
        $this->assertSame('835.00', DB::value('SELECT value_num FROM kpi_tiles WHERE id = :id', ['id' => $prod]));
        $this->assertSame('77.00', DB::value('SELECT value_num FROM kpi_tiles WHERE id = :id', ['id' => self::$id['h2kpi']]));
        // Live dashboard follows the push.
        $item = DisplayAppsTestKit::createItem('kpi_dashboard', []);
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertStringContainsString('<b>835</b>', $json['data']['html']);
        $this->assertStringContainsString('<b>40</b>', $json['data']['html']);

        // Rate limit: 30 updates per minute per tile.
        DB::query('DELETE FROM rate_limits');
        $codes = [];
        for ($i = 0; $i < 31; $i++) {
            $codes[] = $push($token, ['add' => 1])[0];
        }
        $this->assertSame(array_fill(0, 30, 200), array_slice($codes, 0, 30));
        [$code, $j, , $head] = $push($token, ['add' => 1]);
        $this->assertSame([429, 'RATE_LIMITED'], [$code, $j['error']['code']]);
        $this->assertMatchesRegularExpression('/Retry-After: \d+/i', $head);
        [$code] = $push($tokTxt, ['value' => 'other tile still works']);
        $this->assertSame(200, $code);
        // Bad tokens: 20 per 10 minutes per IP.
        DB::query('DELETE FROM rate_limits');
        $codes = [];
        for ($i = 0; $i < 21; $i++) {
            $codes[] = $push('kpi' . str_repeat('a', 48), ['value' => 1])[0];
        }
        $this->assertSame(401, $codes[19]);
        $this->assertSame(429, $codes[20]);
        DB::query('DELETE FROM rate_limits');

        // Revoke → the token stops working; a new token replaces the old one.
        $s->post('kpi.php', ['op' => 'revoke', 'id' => $prod]);
        [$code] = $push($token, ['value' => 1]);
        $this->assertSame(401, $code);
        $this->assertNull(Kpi::byToken($token));
        $new = Kpi::newToken($safe);
        [$code] = $push($tokSafe, ['reset' => true]);
        $this->assertSame(401, $code, 'old token replaced');
        [$code] = $push($new, ['reset' => true]);
        $this->assertSame(200, $code);
        // Suspended hotel: 403.
        DB::update('hotels', ['status' => 'suspended'], 'id = :id', ['id' => 2]);
        Tenant::forget();
        [$code, $j] = $push($tokH2, ['value' => 1]);
        $this->assertSame([403, 'HOTEL_SUSPENDED'], [$code, $j['error']['code']]);
        DB::update('hotels', ['status' => 'active'], 'id = :id', ['id' => 2]);
        Tenant::forget();
        DB::query('DELETE FROM rate_limits');
        $this->assertSame('', TestEnv::phpErrors());
    }

    // ------------------------------------------------------------------ all apps, languages, restricted users

    public function testAllFourAppsRenderWithDataInEveryLanguage(): void
    {
        foreach (['offers', 'class_schedule', 'departures', 'kpi_dashboard'] as $key) {
            $app = DisplayApps::find($key);
            $this->assertNotNull($app, $key);
            $this->assertSame('business', $app->category());
            $this->assertSame(admin_url(['offers' => 'offers.php', 'class_schedule' => 'class_schedule.php', 'departures' => 'departures.php', 'kpi_dashboard' => 'kpi.php'][$key]), $app->adminPage());
            foreach (['en', 'gu', 'hi'] as $lang) {
                foreach (['classic_dark', 'light', 'diwali'] as $theme) {
                    $item = DisplayAppsTestKit::createItem($key, [], ['lang' => $lang, 'theme' => $theme]);
                    DisplayAppsTestKit::assertRenders($this, self::$url, $item);
                    DisplayAppsTestKit::assertRenders($this, self::$url, $item, ['preview' => 1]);
                    [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
                    $this->assertSame(200, $code);
                    $this->assertIsArray($json['data'], $key);
                    ContentManager::deleteItem((int) $item['id']);
                }
                if ($lang !== 'en') {
                    $this->assertNotSame($app->label(), I18n::translate($app->label(), $lang), $key . ' label translated');
                }
            }
        }
        // Another hotel's signature → 404.
        $item = DisplayAppsTestKit::createItem('offers', []);
        $bad = DisplayApps::dataUrl($item);
        $bad = preg_replace('/s=[0-9a-f]{32}/', 's=' . DisplayApps::signature(2, (int) $item['id']), $bad);
        [$code] = TestEnv::http('GET', self::$url . DisplayAppsTestKit::rel($bad));
        $this->assertSame(404, $code);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testRestrictedUsersAndSidebar(): void
    {
        // Users limited to some TVs (Access) still manage the hotel-wide business data.
        DB::insert('user_access', ['user_id' => self::$id['baStaff'], 'target_type' => 'room', 'target_id' => self::$id['r101'], 'created_at' => now()]);
        DB::insert('user_access', ['user_id' => self::$id['baRecep'], 'target_type' => 'room', 'target_id' => self::$id['r101'], 'created_at' => now()]);
        Access::$userOverride = DB::one('SELECT * FROM users WHERE id = :id', ['id' => self::$id['baStaff']]);
        Access::forget();
        $this->assertTrue(Access::restricted());
        Access::$userOverride = null;
        Access::forget();
        $st = new AdminSession(self::$url, 'baStaff');
        [$code, , $html] = $st->get('offers.php');
        $this->assertSame(200, $code);
        foreach (['offers.php', 'class_schedule.php', 'departures.php', 'kpi.php'] as $page) {
            $this->assertStringContainsString($page, $html, 'sidebar link ' . $page);
        }
        [$code] = $st->post('offers.php', ['op' => 'save', 'id' => 0, 'title' => 'Limited staff offer', 'is_active' => 1]);
        $this->assertSame(302, $code);
        [$code] = $st->post('class_schedule.php', ['op' => 'save', 'id' => 0, 'name' => 'Limited class', 'days' => [1], 'start_time' => '06:00', 'end_time' => '07:00', 'is_active' => 1]);
        $this->assertSame(302, $code);
        [$code] = $st->post('kpi.php', ['op' => 'save', 'id' => 0, 'label' => 'Limited tile', 'type' => 'counter', 'is_active' => 1]);
        $this->assertSame(302, $code);
        $rc = new AdminSession(self::$url, 'baRecep');
        [$code, , $html] = $rc->get('departures.php');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('kpi.php', $html, 'reception sees no KPI link');
        [$code] = $rc->post('departures.php', ['op' => 'save', 'id' => 0, 'sched_time' => '10:00', 'destination' => 'Limited', 'daily' => 1, 'is_active' => 1]);
        $this->assertSame(302, $code);
        DB::query('DELETE FROM user_access');
        $this->assertSame('', TestEnv::phpErrors());
    }
}
