<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/** TEMPORARY security fuzz (removed after review). */
final class ZzFuzzTmpTest extends TestCase
{
    private const P = '<img src=x onerror=alert(9)>"\'<svg/onload=alert(8)>';

    private static function fill(string $table, array $fixed): int
    {
        $cols = DB::all("SELECT COLUMN_NAME c, DATA_TYPE t, CHARACTER_MAXIMUM_LENGTH l, COLUMN_TYPE ct FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t", ['t' => $table]);
        $row = [];
        foreach ($cols as $c) {
            if (in_array($c['c'], ['id', 'hotel_id'], true) || array_key_exists($c['c'], $fixed)) {
                continue;
            }
            if (in_array($c['t'], ['varchar', 'text', 'char'], true) && !str_ends_with($c['c'], '_path') && $c['c'] !== 'push_token_hash') {
                $row[$c['c']] = mb_substr(self::P, 0, (int) $c['l']);
            }
        }
        return DB::insert($table, $fixed + $row);
    }

    public function testFuzz(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        Settings::set('hotel_name', self::P);
        Settings::set('rates_market_manual', "NIFTY<img src=x onerror=alert(9)> | 100 | 1\n" . self::P . " | 5 | 2");
        $now = date('H:i:s');
        self::fill('notices', ['category' => 'general', 'is_active' => 1]);
        self::fill('offers', ['is_active' => 1]);
        self::fill('class_sessions', ['start_time' => '00:00:00', 'end_time' => '23:59:00', 'days' => '1,2,3,4,5,6,7', 'color' => '#123456', 'level' => mb_substr(self::P, 0, 20)]);
        self::fill('departures', ['sched_time' => date('H:i:s', time() + 600), 'kind' => 'departure', 'status' => 'on_time']);
        foreach (['counter', 'percent', 'text', 'days_since'] as $ty) {
            self::fill('kpi_tiles', ['type' => $ty, 'since_date' => '2026-01-01']);
        }
        $alb = self::fill('albums', ['guest_upload' => 1, 'guest_key' => 'abcd1234']);
        self::fill('album_photos', ['album_id' => $alb, 'image_path' => 'h1/x"><img src=x onerror=alert(7)>.jpg', 'status' => 'approved', 'source' => 'guest']);
        $cat = self::fill('guest_menu_categories', ['board_from' => null, 'board_to' => null]);
        self::fill('guest_menu_items', ['category_id' => $cat, 'badge' => null, 'available_from' => null, 'available_to' => null, 'is_special' => 1]);
        self::fill('guest_menu_items', ['category_id' => $cat, 'badge' => null, 'available_from' => null, 'available_to' => null, 'is_special' => 0]);
        $svc = self::fill('queue_services', ['prefix' => 'A', 'self_service' => 1]);
        $ctr = self::fill('queue_counters', ['service_id' => $svc]);
        self::fill('queue_tokens', ['service_id' => $svc, 'origin_service_id' => $svc, 'token_date' => Queue::today(), 'number' => 5, 'prefix' => 'A', 'status' => 'called', 'counter_id' => $ctr, 'source' => 'self', 'created_at' => now(), 'queued_at' => Queue::nowMicro(), 'called_at' => Queue::nowMicro()]);
        self::fill('queue_tokens', ['service_id' => $svc, 'origin_service_id' => $svc, 'token_date' => Queue::today(), 'number' => 6, 'prefix' => 'A', 'status' => 'waiting', 'source' => 'self', 'created_at' => now(), 'queued_at' => Queue::nowMicro()]);
        self::fill('metal_rates', ['gold_24k' => 70000, 'gold_22k' => 65000, 'silver_kg' => 80000]);

        // Feeds: every provider answers with payload strings.
        foreach (DataFeeds::PROVIDERS as $p => $def) {
            if ($def['key']) {
                DataFeeds::setPlatformKey($p, 'testkey12345');
            }
        }
        $P = self::P;
        Http::$mock = static function (string $m, string $url) use ($P): array {
            $host = parse_url($url, PHP_URL_HOST);
            $body = match ($host) {
                'open.er-api.com' => ['result' => 'success', 'rates' => ['USD' => 0.012, 'EUR' => 0.011]],
                'api.frankfurter.dev' => ['rates' => ['USD' => 0.012], 'date' => '2026-10-07'],
                'www.goldapi.io' => ['price' => 2000, 'timestamp' => time()],
                'api.twelvedata.com' => ['NSEI' => ['name' => $P, 'close' => '100', 'percent_change' => '1'], 'BSESN' => ['name' => $P, 'close' => '100'], 'NSEBANK' => ['name' => $P, 'close' => 1], 'RELIANCE' => ['name' => $P, 'close' => 5]],
                'api.cricapi.com' => ['status' => 'success', 'data' => [['id' => $P, 'name' => $P, 'matchType' => 't20', 'status' => $P, 'venue' => $P, 'teams' => [$P, $P . '2'], 'teamInfo' => [['name' => $P, 'shortname' => $P]], 'score' => [['inning' => $P . ' Inning 1', 'r' => 100, 'w' => 2, 'o' => 10]], 'matchStarted' => true, 'matchEnded' => false]]],
                'api.aviationstack.com' => ['data' => [['flight_date' => date('Y-m-d'), 'flight_status' => $P, 'airline' => ['name' => $P], 'departure' => ['airport' => $P, 'iata' => '<i>', 'terminal' => '<b>', 'gate' => '<u>', 'scheduled' => '2026-10-07T10:00:00+00:00'], 'arrival' => ['airport' => $P, 'iata' => 'X']]]],
                'docs.google.com' => null,
                default => null,
            };
            if ($host === 'docs.google.com') {
                return ['status' => 200, 'body' => "h1,h2\n\"$P\",\"" . str_replace('"', '""', $P) . "\"\n"];
            }
            return ['status' => 200, 'body' => json_encode($body)];
        };

        $hits = [];
        foreach (DisplayApps::all() as $key => $app) {
            $in = [];
            foreach ($app->defaults() as $k => $v) {
                $in[$k] = is_string($v) ? self::P : $v;
            }
            // realistic values that must validate
            $in['album_id'] = $alb;
            $in['url'] = 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTestSheetId_abcdefghijklmnop/pub?output=csv';
            $in['mode'] = $app->defaults()['mode'] ?? null;
            foreach ([[], ['mode' => 'live'], ['mode' => 'manual'], ['mode' => 'url'], ['mode' => 'text'], ['mode' => 'whatsapp'], ['mode' => 'wifi'], ['mode' => 'both']] as $variant) {
                [$cfg, $errs] = $app->validate($variant + $in);
                foreach ([false, true] as $preview) {
                    $item = DisplayAppsTestKit::createItem($key, $cfg, [], self::P);
                    try {
                        $html = DisplayApps::renderPage($item, $preview);
                        $json = json_encode(DisplayApps::payload($item));
                    } catch (Throwable $e) {
                        $hits[] = "$key EXC " . $e->getMessage();
                        continue;
                    }
                    foreach (['<img src=x', '<svg/onload', '"><img'] as $needle) {
                        if (str_contains($html, $needle)) {
                            $pos = strpos($html, $needle);
                            $hits[] = "$key " . json_encode($variant) . " preview=" . (int) $preview . ": " . substr($html, max(0, $pos - 150), 220);
                        }
                    }
                    $d = json_decode($json, true)['data'] ?? null;
                    $html2 = is_array($d) ? (string) ($d['html'] ?? '') : '';
                    foreach (['<img src=x', '<svg/onload'] as $needle) {
                        if (str_contains($html2, $needle)) {
                            $hits[] = "$key DATA html: " . substr($html2, max(0, strpos($html2, $needle) - 150), 220);
                        }
                    }
                    file_put_contents('/tmp/claude-0/-home-user-ledtv/3c491869-7d2d-5400-9e92-547ac425217f/scratchpad/fuzz_' . $key . '.json', $json);
                }
            }
        }
        // Admin pages over HTTP
        $pw = Auth::hash('Passw0rd!');
        DB::insert('users', ['hotel_id' => 1, 'username' => 'fzBoss', 'email' => 'fz@t.test', 'full_name' => self::P, 'password_hash' => $pw, 'role' => 'super_admin']);
        $url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 2);
        TestEnv::writeConfig(HC_ROOT, $url);
        $s = new AdminSession($url, 'fzBoss');
        $item = DisplayAppsTestKit::createItem('notice_board', [], [], self::P);
        foreach (['album_upload.php', 'album_upload.php?album=' . $alb, 'queue.php', 'queue.php?counter=' . $ctr, 'queue.php?tab=setup', 'queue_issue.php', 'queue_issue.php?t=1', 'notices.php', 'notices.php?action=edit&id=1',
            'offers.php', 'offers.php?action=edit&id=1', 'kpi.php', 'kpi.php?action=edit&id=1', 'departures.php', 'departures.php?action=edit&id=1', 'class_schedule.php', 'class_schedule.php?action=edit&id=1',
            'menu_board.php', 'rates.php', 'data_feeds.php', 'apps.php', 'apps.php?action=edit&id=' . $item['id'], 'designer.php', 'pdf_import.php', 'content.php', 'playlists.php', 'index.php', 'platform_settings.php?tab=data_feeds'] as $pg) {
            [$code, , $html] = $s->get($pg);
            foreach (['<img src=x', '<svg/onload'] as $needle) {
                if (str_contains($html, $needle)) {
                    $hits[] = "ADMIN $pg ($code): " . substr($html, max(0, strpos($html, $needle) - 200), 260);
                }
            }
            $hits[] = "INFO $pg $code escaped=" . substr_count($html, '&lt;img src=x');
            if ($code >= 400 || TestEnv::hasPhpError($html)) { $hits[] = "ADMIN $pg code $code"; }
        }
        // Public pages
        $album = Albums::find($alb);
        foreach ([DisplayAppsTestKit::rel(Albums::guestUrl($album)), DisplayAppsTestKit::rel(Queue::statusUrl(Queue::findService($svc), Queue::findToken(1))), DisplayAppsTestKit::rel(Queue::publicUrl(Queue::findService($svc)))] as $pg) {
            [$code, , $html] = TestEnv::http('GET', $url . $pg);
            foreach (['<img src=x', '<svg/onload'] as $needle) {
                if (str_contains($html, $needle)) {
                    $hits[] = "PUBLIC $pg ($code): " . substr($html, max(0, strpos($html, $needle) - 200), 260);
                }
            }
        }
        fwrite(STDERR, implode("\n\n", array_unique($hits)) . "\n");
        $this->assertTrue(true);
    }
}
