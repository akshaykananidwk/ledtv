<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DisplayAppsTestKit.php';

/**
 * Token / queue system (core/Queue.php, admin/queue.php, admin/queue_issue.php, display/queue.php,
 * display app "queue_display"): issue / next / recall / serving / skip / no-show / done / call by
 * number / transfer, atomic NEXT (two counters at the same moment, real parallel requests), daily
 * numbering by hotel date and manual reset, public self-service (signature, rate limit, status page),
 * display data JSON + rendering, permissions (reception operates, manager manages), restricted users,
 * tenancy (another hotel's ids → 404) and XSS.
 */
final class QueueTest extends TestCase
{
    private static string $url;
    /** @var array<string, int> */
    private static array $id = [];
    private const XSS = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
    private const AJAX = ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'];

    public static function setUpBeforeClass(): void
    {
        TestEnv::resetDatabase();
        Tenant::set(1);
        $pw = Auth::hash('Passw0rd!');
        foreach (['manager' => 'qMgr', 'staff' => 'qStaff', 'reception' => 'qRecep', 'reception ' => 'qRecep2'] as $role => $u) {
            self::$id[$u] = DB::insert('users', ['hotel_id' => 1, 'username' => $u, 'email' => $u . '@t.test', 'full_name' => $u, 'password_hash' => $pw, 'role' => trim($role)]);
        }
        self::$id['r101'] = DB::insert('rooms', ['room_number' => '101', 'name' => 'Room 101', 'floor' => '1']);
        Hotels::create(['name' => 'Hotel Two'], ['username' => 'qBoss2', 'email' => 'q2@t.test', 'password' => 'Passw0rd!']);
        Tenant::run(2, function (): void {
            [self::$id['h2svc']] = Queue::saveService(['name' => 'H2-SVC-SECRET', 'prefix' => 'Z', 'is_active' => 1, 'self_service' => 1]);
            [self::$id['h2ctr']] = Queue::saveCounter(['name' => 'H2-CTR', 'service_id' => self::$id['h2svc'], 'is_active' => 1]);
            self::$id['h2tok'] = (int) Queue::issue(self::$id['h2svc'])['id'];
        });
        Tenant::set(1);
        DisplayApps::reset();
        Cache::clear();
        self::$url = TestEnv::startServer(HC_ROOT, __DIR__ . '/../../router.php', 4);
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
    }

    private static function wipe(): void
    {
        foreach (['queue_tokens', 'queue_counters', 'queue_services'] as $t) {
            DB::query("DELETE FROM $t WHERE hotel_id = 1");
        }
        DB::query('DELETE FROM rate_limits');
    }

    /** @return array{0:int,1:int,2:int,3:int} opd service, cash service, counter 1 (opd), counter 2 (opd) */
    private static function setupBasic(): array
    {
        self::wipe();
        [$opd] = Queue::saveService(['name' => 'OPD', 'prefix' => 'a', 'start_number' => 1, 'is_active' => 1, 'self_service' => 1, 'sort_order' => 1]);
        [$cash] = Queue::saveService(['name' => 'Cash', 'prefix' => 'C', 'start_number' => 100, 'is_active' => 1, 'sort_order' => 2]);
        [$c1] = Queue::saveCounter(['name' => 'Counter 1', 'service_id' => $opd, 'room_text' => 'Room 5', 'is_active' => 1, 'sort_order' => 1]);
        [$c2] = Queue::saveCounter(['name' => 'Counter 2', 'service_id' => $opd, 'is_active' => 1, 'sort_order' => 2]);
        return [(int) $opd, (int) $cash, (int) $c1, (int) $c2];
    }

    private static function ctr(int $id): array
    {
        return (array) Queue::findCounter($id);
    }

    public function testIssueCallRecallSkipDoneTransferFlow(): void
    {
        [$opd, $cash, $c1, $c2] = self::setupBasic();
        // Validation.
        $this->assertNotEmpty(Queue::saveService(['name' => '', 'prefix' => 'TOOLONG', 'start_number' => 0])[1]);
        $this->assertSame([null, ['Choose a service.']], Queue::saveCounter(['name' => 'X', 'service_id' => 999999]));
        $this->assertSame('A', Queue::findService($opd)['prefix'], 'prefix upper-cased');

        $t1 = Queue::issue($opd, ['name' => 'Ramesh', 'phone' => '+91 98xx-765', 'user' => self::$id['qRecep']]);
        $t2 = Queue::issue($opd);
        $t3 = Queue::issue($opd);
        $k1 = Queue::issue($cash);
        $this->assertSame(['A-001', 'A-002', 'A-003', 'C-100'], [Queue::label($t1), Queue::label($t2), Queue::label($t3), Queue::label($k1)]);
        $this->assertSame(['Ramesh', '+91 98765', 'desk', 'waiting'], [$t1['customer_name'], $t1['customer_phone'], $t1['source'], $t1['status']]);
        $this->assertSame([0, 1, 2], [Queue::ahead($t1), Queue::ahead($t2), Queue::ahead($t3)]);
        $this->assertSame(3, Queue::waitingFor(self::ctr($c1)));
        $this->assertSame(4, Queue::waiting());

        // NEXT at counter 1 → A-001; NEXT at counter 2 → A-002; NEXT again at 1 finishes A-001 (done) → A-003.
        $this->assertSame('A-001', Queue::label(Queue::next(self::ctr($c1))));
        $this->assertSame('A-002', Queue::label(Queue::next(self::ctr($c2))));
        $this->assertSame('A-003', Queue::label(Queue::next(self::ctr($c1))));
        $this->assertSame('done', Queue::findToken((int) $t1['id'])['status']);
        $this->assertNull(Queue::next(self::ctr($c2)), 'OPD empty (cash is another service)');
        $this->assertSame('done', Queue::findToken((int) $t2['id'])['status']);
        $this->assertNull(Queue::current(self::ctr($c2)));
        // Recall, arrived, no-show, call a skipped number back, transfer.
        $r = Queue::recall(self::ctr($c1));
        $this->assertSame(1, (int) $r['recall_count']);
        $this->assertNull(Queue::recall(self::ctr($c2)));
        $this->assertSame('serving', Queue::serving(self::ctr($c1))['status']);
        $this->assertSame('no_show', Queue::finish(self::ctr($c1), 'no_show')['status']);
        $this->assertNull(Queue::callNumber(self::ctr($c2), 'B-3'), 'wrong prefix');
        $this->assertNull(Queue::callNumber(self::ctr($c2), 'xyz!'));
        $back = Queue::callNumber(self::ctr($c2), ' a-003 ');
        $this->assertSame(['A-003', 'called', $c2, 0], [Queue::label($back), $back['status'], (int) $back['counter_id'], (int) $back['recall_count']]);
        $this->assertNull(Queue::callNumber(self::ctr($c1), '3'), 'already called');
        $moved = Queue::transfer(self::ctr($c2), $cash);
        $this->assertSame([$cash, $opd, 'waiting', null, 'A-003'], [(int) $moved['service_id'], (int) $moved['origin_service_id'], $moved['status'], $moved['counter_id'], Queue::label($moved)]);
        $this->assertSame(1, Queue::ahead($moved), 'behind C-100');
        // A counter for every service calls the oldest waiting token of any service.
        [$any] = Queue::saveCounter(['name' => 'Any', 'service_id' => 0, 'is_active' => 1]);
        $this->assertSame('C-100', Queue::label(Queue::next(self::ctr((int) $any))));
        $this->assertSame('A-003', Queue::label(Queue::next(self::ctr((int) $any))));
        $this->assertSame('skipped', Queue::finish(self::ctr((int) $any), 'skipped')['status']);
        $this->assertSame(['waiting' => 0, 'called' => 0, 'serving' => 0, 'done' => 3, 'skipped' => 1, 'no_show' => 0], Queue::todayStats());
        // Deleting a counter puts its open token back in the line; deleting a service removes its tokens.
        Queue::issue($cash);
        $open = Queue::next(self::ctr((int) $any));
        Queue::deleteCounter((int) $any);
        $this->assertSame(['waiting', null], [Queue::findToken((int) $open['id'])['status'], Queue::findToken((int) $open['id'])['counter_id']]);
        $this->assertTrue(Queue::deleteService($cash));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = 1 AND (service_id = :s OR origin_service_id = :s2)', ['s' => $cash, 's2' => $cash]));
        $this->assertSame(1, (int) DB::value('SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = 2'), 'other hotel untouched');
        // Another hotel's ids.
        foreach ([fn () => Queue::findCounter(self::$id['h2ctr']), fn () => Queue::issue(self::$id['h2svc']), fn () => Queue::transfer(self::ctr($c1), self::$id['h2svc']), fn () => Queue::reset(self::$id['h2svc'])] as $fn) {
            try {
                $fn();
                $this->fail('cross-hotel access must be denied');
            } catch (TenantException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testDailyNumberingByHotelDateAndReset(): void
    {
        [$opd, , $c1] = self::setupBasic();
        // Hotel time zone decides "today".
        Settings::set('timezone', 'Pacific/Kiritimati');
        Settings::flush();
        Tenant::clear();
        Tenant::set(1);
        $this->assertSame((new DateTime('now', new DateTimeZone('Pacific/Kiritimati')))->format('Y-m-d'), Queue::today());
        $t = Queue::issue($opd);
        $this->assertSame([Queue::today(), 1], [$t['token_date'], (int) $t['number']]);
        Queue::issue($opd);
        // Yesterday's leftovers: numbering starts again today; old waiting tokens are not called.
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        DB::query('UPDATE queue_tokens SET token_date = :d WHERE hotel_id = 1', ['d' => $yesterday]);
        DB::query('UPDATE queue_services SET last_date = :d WHERE id = :id', ['d' => $yesterday, 'id' => $opd]);
        $this->assertNull(Queue::next(self::ctr($c1)));
        $n = Queue::issue($opd);
        $this->assertSame([1, Queue::today()], [(int) $n['number'], $n['token_date']], 'number 1 again on a new day');
        $this->assertSame('A-001', Queue::label(Queue::next(self::ctr($c1))));
        $this->assertSame(2, (int) Queue::issue($opd)['number']);
        // Manual reset (manager) starts at the first number again and removes today's tokens.
        DB::update('queue_services', ['start_number' => 50], 'id = :id', ['id' => $opd]);
        $this->assertTrue(Queue::reset($opd));
        $this->assertSame(0, (int) DB::value('SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = 1 AND token_date = :d', ['d' => Queue::today()]));
        $this->assertSame(2, (int) DB::value('SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = 1 AND token_date = :d', ['d' => $yesterday]));
        $this->assertSame('A-050', Queue::label(Queue::issue($opd)));
        // The unique key makes a duplicate number impossible even if the counter row were stale.
        try {
            DB::insert('queue_tokens', ['service_id' => $opd, 'origin_service_id' => $opd, 'token_date' => Queue::today(), 'number' => 50, 'prefix' => 'A', 'created_at' => now(), 'queued_at' => now()]);
            $this->fail('duplicate number accepted');
        } catch (PDOException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
        // Over HTTP: reset is for managers only.
        $r = new AdminSession(self::$url, 'qRecep');
        [$code] = $r->post('queue.php', ['op' => 'svc_reset', 'id' => $opd]);
        $this->assertSame(403, $code);
        $m = new AdminSession(self::$url, 'qMgr');
        [$code] = $m->post('queue.php', ['op' => 'svc_reset', 'id' => $opd]);
        $this->assertSame(302, $code);
        $this->assertSame('A-050', Queue::label(Queue::issue($opd)));
        Settings::set('timezone', 'Asia/Kolkata');
        Settings::flush();
        Tenant::clear();
        Tenant::set(1);
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testAtomicNextWithTwoCountersInParallel(): void
    {
        [$opd, , $c1, $c2] = self::setupBasic();
        for ($i = 0; $i < 12; $i++) {
            Queue::issue($opd);
        }
        // Sequential with an open transaction on a second connection: a token claimed (locked) by
        // another counter is skipped once committed, never handed out twice.
        $cfg = DB::config();
        $pdo2 = DB::connect($cfg);
        $pdo2->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo2->beginTransaction();
        $pdo2->exec("UPDATE queue_tokens SET status = 'called', counter_id = " . $c2 . ", called_at = NOW() WHERE hotel_id = 1 AND token_date = '" . Queue::today() . "' AND status = 'waiting' ORDER BY queued_at, id LIMIT 1");
        $pdo2->commit();
        $this->assertSame('A-002', Queue::label(Queue::next(self::ctr($c1))));

        // Real parallel requests: two counters press NEXT at the same moment, 5 rounds.
        $a = new AdminSession(self::$url, 'qRecep');
        $b = new AdminSession(self::$url, 'qRecep2');
        $called = [];
        for ($round = 0; $round < 5; $round++) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ([[$a, $c1], [$b, $c2]] as [$s, $cid]) {
                $ch = curl_init(self::$url . 'admin/queue.php');
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $s->jar, CURLOPT_COOKIEJAR => $s->jar, CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTPHEADER => self::AJAX, CURLOPT_POSTFIELDS => ['_csrf' => $s->csrf, 'op' => 'next', 'counter' => (string) $cid]]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
            }
            do {
                $st = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $st === CURLM_OK);
            foreach ($handles as $ch) {
                $this->assertSame(200, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) curl_multi_getcontent($ch));
                $j = json_decode((string) curl_multi_getcontent($ch), true);
                $this->assertTrue($j['ok']);
                $called[] = $j['data']['current']['label'];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        $this->assertCount(10, $called);
        $this->assertCount(10, array_unique($called), 'the same token was called twice: ' . implode(',', $called));
        $this->assertSame([], array_intersect($called, ['A-001', 'A-002']));
        // Every token is called by exactly one counter.
        $rows = DB::all("SELECT number, COUNT(*) n FROM queue_tokens WHERE hotel_id = 1 AND called_at IS NOT NULL GROUP BY number HAVING n > 1");
        $this->assertSame([], $rows);
        $this->assertSame(0, Queue::waiting([$opd]));
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testCallingPageAjaxPermissionsRestrictedUsersAndTenancy(): void
    {
        [$opd, $cash, $c1] = self::setupBasic();
        // Reception (limited to one room by per-user TV access) operates the queue.
        DB::insert('user_access', ['user_id' => self::$id['qRecep'], 'target_type' => 'room', 'target_id' => self::$id['r101'], 'created_at' => now()]);
        $r = new AdminSession(self::$url, 'qRecep');
        [$code, , $html] = $r->get('queue.php');
        $this->assertSame(200, $code);
        $this->assertFalse(TestEnv::hasPhpError($html));
        $this->assertStringContainsString('data-counter-link="' . $c1 . '"', $html);
        $this->assertStringNotContainsString('tab=setup', $html, 'no setup link for reception');
        [$code] = $r->get('queue.php?tab=setup');
        $this->assertSame(403, $code);
        [$code, , $html] = $r->get('queue.php?counter=' . $c1);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('id="qApp"', $html);

        // Issue page (desk): AJAX and form post with the ticket.
        [$code, $j] = TestEnv::http('POST', self::$url . 'admin/queue_issue.php', null, self::AJAX, $r->jar, ['_csrf' => $r->csrf, 'op' => 'issue', 'service_id' => (string) $opd, 'name' => 'Asha ' . self::XSS]);
        $this->assertSame(200, $code);
        $this->assertSame(['A-001', 'OPD', 0], [$j['data']['label'], $j['data']['service'], $j['data']['ahead']]);
        [$code, , , $head] = $r->post('queue_issue.php', ['op' => 'issue', 'service_id' => $opd]);
        $this->assertSame(302, $code);
        preg_match('/[?&]t=(\d+)/', $head, $m);
        [$code, , $html] = $r->get('queue_issue.php?t=' . $m[1]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('A-002', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('1 people before you', $html);
        $this->assertSame(self::$id['qRecep'], (int) DB::value('SELECT issued_by FROM queue_tokens WHERE id = :id', ['id' => $m[1]]));

        // Calling with AJAX.
        $op = static fn (string $o, array $extra = []): array => TestEnv::http('POST', self::$url . 'admin/queue.php', null, self::AJAX, $r->jar, $extra + ['_csrf' => $r->csrf, 'op' => $o, 'counter' => (string) $c1]);
        [$code, $j] = $op('next');
        $this->assertSame(200, $code);
        $this->assertSame(['A-001', 'called', 1, ['A-002']], [$j['data']['current']['label'], $j['data']['current']['status'], $j['data']['waiting'], $j['data']['next']]);
        $this->assertStringContainsString('Asha &lt;script&gt;', e($j['data']['current']['name']));
        [, $j] = $op('recall');
        $this->assertSame(1, $j['data']['current']['recall']);
        [, $j] = $op('serving');
        $this->assertSame('serving', $j['data']['current']['status']);
        [, $j] = $op('done');
        $this->assertNull($j['data']['current']);
        $this->assertSame('warning', $op('done')[1]['data']['level']);
        [, $j] = $op('call', ['number' => '2']);
        $this->assertSame('A-002', $j['data']['current']['label']);
        [, $j] = $op('transfer', ['service_id' => (string) $cash]);
        $this->assertSame([null, 0], [$j['data']['current'], $j['data']['waiting']]);
        [, $j] = $op('next');
        $this->assertSame('Nobody is waiting.', $j['data']['message']);
        [$code, $j] = TestEnv::http('GET', self::$url . 'admin/queue.php?counter=' . $c1, null, self::AJAX, $r->jar);
        $this->assertSame([200, 'Counter 1'], [$code, $j['data']['counter']['name']]);
        // Plain form post fallback.
        [$code] = $r->post('queue.php', ['op' => 'skip', 'counter' => $c1]);
        $this->assertSame(302, $code);
        // CSRF.
        [$code] = TestEnv::http('POST', self::$url . 'admin/queue.php', null, self::AJAX, $r->jar, ['op' => 'next', 'counter' => (string) $c1]);
        $this->assertSame(419, $code);
        // Reception may not manage services / counters.
        foreach ([['op' => 'svc_save', 'id' => 0, 'name' => 'Hack'], ['op' => 'ctr_delete', 'id' => $c1], ['op' => 'svc_delete', 'id' => $opd]] as $p) {
            [$code] = $r->post('queue.php', $p);
            $this->assertSame(403, $code, $p['op']);
        }
        $this->assertNotNull(Queue::findService($opd));

        // Another hotel's counter / service / token → 404.
        [$code, $j] = $op('next', ['counter' => (string) self::$id['h2ctr']]);
        $this->assertSame([404, 'NOT_FOUND'], [$code, $j['error']['code']]);
        Queue::issue($opd);
        $op('next');
        [$code] = $op('transfer', ['service_id' => (string) self::$id['h2svc']]);
        $this->assertSame(404, $code);
        [$code] = TestEnv::http('POST', self::$url . 'admin/queue_issue.php', null, self::AJAX, $r->jar, ['_csrf' => $r->csrf, 'op' => 'issue', 'service_id' => (string) self::$id['h2svc']]);
        $this->assertSame(404, $code);
        [$code] = $r->get('queue_issue.php?t=' . self::$id['h2tok']);
        $this->assertSame(404, $code);
        [$code] = $r->get('queue.php?counter=' . self::$id['h2ctr']);
        $this->assertSame(404, $code);
        $this->assertSame(1, (int) Tenant::run(2, fn () => DB::value('SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = 2')));

        // Manager: setup CRUD (XSS-safe), foreign ids 404.
        $mg = new AdminSession(self::$url, 'qMgr');
        [$code] = $mg->post('queue.php', ['op' => 'svc_save', 'id' => 0, 'name' => 'Lab ' . self::XSS, 'prefix' => 'L', 'start_number' => 1, 'is_active' => 1, 'self_service' => 1]);
        $this->assertSame(302, $code);
        $lab = (int) DB::value("SELECT id FROM queue_services WHERE hotel_id = 1 AND prefix = 'L'");
        [$code] = $mg->post('queue.php', ['op' => 'ctr_save', 'id' => 0, 'name' => 'Desk ' . self::XSS, 'service_id' => $lab, 'room_text' => 'R ' . self::XSS, 'is_active' => 1]);
        $this->assertSame(302, $code);
        [$code, , $html] = $mg->get('queue.php?tab=setup');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('Lab &lt;script&gt;', $html);
        $this->assertStringContainsString('display/queue.php?h=1&amp;q=' . $lab, $html, 'self-service link');
        $this->assertStringNotContainsString('H2-SVC-SECRET', $html);
        foreach ([['op' => 'svc_save', 'id' => self::$id['h2svc'], 'name' => 'HACK'], ['op' => 'svc_delete', 'id' => self::$id['h2svc']], ['op' => 'svc_reset', 'id' => self::$id['h2svc']],
            ['op' => 'ctr_save', 'id' => self::$id['h2ctr'], 'name' => 'HACK'], ['op' => 'ctr_delete', 'id' => self::$id['h2ctr']], ['op' => 'ctr_save', 'id' => 0, 'name' => 'X', 'service_id' => self::$id['h2svc']]] as $p) {
            [$code] = $mg->post('queue.php', $p);
            $this->assertSame(404, $code, $p['op'] . ' ' . $p['id']);
        }
        $this->assertSame('H2-SVC-SECRET', Tenant::run(2, fn () => DB::value('SELECT name FROM queue_services WHERE id = :id', ['id' => self::$id['h2svc']])));
        [$code] = $mg->post('queue.php', ['op' => 'svc_delete', 'id' => $lab]);
        $this->assertSame(302, $code);
        $this->assertNull(DB::one('SELECT id FROM queue_services WHERE id = :id', ['id' => $lab]));
        // Staff role (no queue.manage) and sidebar entries.
        [$code, , $html] = (new AdminSession(self::$url, 'qStaff'))->get('queue_issue.php');
        $this->assertSame(200, $code);
        $this->assertStringContainsString('/admin/queue.php"', $html);
        DB::query('DELETE FROM user_access');
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testPublicSelfServiceSignatureRateLimitAndStatus(): void
    {
        [$opd, $cash, $c1] = self::setupBasic();
        $svc = Queue::findService($opd);
        $url = Queue::publicUrl($svc);
        $rel = self::$url . DisplayAppsTestKit::rel($url);
        [$code, , $html] = TestEnv::http('GET', $rel);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Get my token', $html);
        $this->assertStringContainsString('0 people waiting', $html);
        [, , $html] = TestEnv::http('GET', $rel . '&lang=gu');
        $this->assertStringContainsString(I18n::translate('Get my token', 'gu'), $html);
        // Bad / foreign signatures, other hotel's service, self-service off.
        $bad = [
            'display/queue.php?h=1&q=' . $opd . '&s=' . str_repeat('0', 32),
            'display/queue.php?h=1&q=' . $opd,
            'display/queue.php?h=2&q=' . $opd . '&s=' . Queue::serviceSignature(2, $opd),
            'display/queue.php?h=1&q=' . self::$id['h2svc'] . '&s=' . Queue::serviceSignature(1, self::$id['h2svc']),
            'display/queue.php?h=1&q=' . $cash . '&s=' . Queue::serviceSignature(1, $cash),
            'display/queue.php?h=99&q=' . $opd . '&s=' . Queue::serviceSignature(99, $opd),
            'display/queue.php?h=abc&q=1&s=x',
        ];
        foreach ($bad as $u) {
            [$code, , $html] = TestEnv::http('GET', self::$url . $u);
            $this->assertSame(404, $code, $u);
            $this->assertFalse(TestEnv::hasPhpError($html));
        }
        // Take a token: 303 to the signed status page.
        [$code, , , $head] = TestEnv::http('POST', $rel, null, [], null, ['name' => 'Self ' . self::XSS]);
        $this->assertSame(303, $code);
        $this->assertTrue((bool) preg_match('/Location: (\S+)/i', $head, $m));
        $t = DB::one('SELECT * FROM queue_tokens WHERE hotel_id = 1 ORDER BY id DESC LIMIT 1');
        $this->assertSame(['self', 'Self ' . self::XSS, '127.0.0.1', 'A-001'], [$t['source'], $t['customer_name'], $t['ip_address'], Queue::label($t)]);
        $this->assertStringContainsString('&t=' . $t['id'] . '&k=' . Queue::tokenSignature(1, (int) $t['id']), $m[1]);
        [$code, , $html] = TestEnv::http('GET', self::$url . DisplayAppsTestKit::rel($m[1]));
        $this->assertSame(200, $code);
        $this->assertStringContainsString('A-001', $html);
        $this->assertStringContainsString('0 people before you', $html);
        $this->assertStringContainsString('http-equiv="refresh"', $html);
        Queue::next(self::ctr($c1));
        [, , $html] = TestEnv::http('GET', self::$url . DisplayAppsTestKit::rel($m[1]));
        $this->assertStringContainsString('Please go to Counter 1', $html);
        $this->assertStringContainsString('Room 5', $html);
        // Status page: wrong token signature / another hotel's token → 404.
        [$code] = TestEnv::http('GET', $rel . '&t=' . $t['id'] . '&k=' . str_repeat('a', 32));
        $this->assertSame(404, $code);
        [$code] = TestEnv::http('GET', $rel . '&t=' . self::$id['h2tok'] . '&k=' . Queue::tokenSignature(1, self::$id['h2tok']));
        $this->assertSame(404, $code);
        // Rate limit per IP: SELF_LIMIT tokens per window, then 429.
        for ($i = 1; $i < Queue::SELF_LIMIT; $i++) {
            [$code] = TestEnv::http('POST', $rel, null, [], null, ['name' => '']);
            $this->assertSame(303, $code);
        }
        [$code, , $html, $head] = TestEnv::http('POST', $rel, null, [], null, ['name' => '']);
        $this->assertSame(429, $code);
        $this->assertStringContainsString('Retry-After', $head);
        $this->assertSame(Queue::SELF_LIMIT, (int) DB::value("SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = 1 AND source = 'self'"));
        // Suspended hotel → 403.
        DB::query("UPDATE hotels SET status = 'suspended' WHERE id = 1");
        Tenant::forget();
        [$code] = TestEnv::http('GET', $rel);
        $this->assertSame(403, $code);
        DB::query("UPDATE hotels SET status = 'active' WHERE id = 1");
        Tenant::forget();
        $this->assertSame('', TestEnv::phpErrors());
    }

    public function testDisplayDataRenderingAndXss(): void
    {
        [$opd, $cash, $c1, $c2] = self::setupBasic();
        $app = DisplayApps::find('queue_display');
        $this->assertSame([admin_url('queue.php'), 'business'], [$app->adminPage(), $app->category()]);
        DB::update('queue_counters', ['name' => 'D ' . self::XSS], 'id = :id', ['id' => $c2]);
        DB::update('queue_services', ['name' => 'OPD ' . self::XSS], 'id = :id', ['id' => $opd]);
        [$c3] = Queue::saveCounter(['name' => 'Cash desk', 'service_id' => $cash, 'is_active' => 1, 'sort_order' => 3]);
        for ($i = 0; $i < 4; $i++) {
            Queue::issue($opd);
        }
        Queue::issue($cash);
        Queue::next(self::ctr($c1));
        Queue::next(self::ctr($c2));
        Queue::next(self::ctr((int) $c3));

        [$cfg, $err] = $app->validate(['services' => [(string) $opd, '999999'], 'show_qr' => '1', 'chime' => '1', 'speak' => '0', 'show_recent' => '1', 'show_waiting' => '1']);
        $this->assertSame([[$opd], false, true], [$cfg['services'], $cfg['speak'], $cfg['chime']]);
        try {
            $app->validate(['services' => [(string) self::$id['h2svc']]]);
            $this->fail('another hotel\'s service must be denied');
        } catch (TenantException) {
            $this->assertTrue(true);
        }

        $item = DisplayAppsTestKit::createItem('queue_display', ['heading' => 'Q ' . self::XSS], ['lang' => 'gu']);
        [$code, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertSame([200, 2], [$code, $json['refresh_sec']]);
        $d = $json['data'];
        $this->assertSame(['Counter 1', 'D ' . self::XSS, 'Cash desk'], array_column($d['counters'], 'name'));
        $this->assertSame(['A-001', 'A-002', 'C-100'], array_column($d['counters'], 'token'));
        $this->assertSame([2, 0], array_column($d['services'], 'waiting'));
        $this->assertSame(['C-100', 'A-002', 'A-001'], array_column($d['recent'], 'token'));
        $this->assertSame('gu-IN', $d['speech_lang']);
        $this->assertSame(I18n::translate('Token :token, :counter', 'gu', ['token' => 'A 1', 'counter' => 'Counter 1']), $d['counters'][0]['say']);
        $this->assertStringStartsWith('ટોકન A 1', $d['counters'][0]['say']);
        $key = $d['counters'][0]['key'];
        Queue::recall(self::ctr($c1));
        [, $json] = DisplayAppsTestKit::data(self::$url, $item);
        $this->assertNotSame($key, $json['data']['counters'][0]['key'], 'a recall changes the key (blink + chime on the TV)');
        // Service filter: only OPD counters / waiting counts.
        $only = DisplayAppsTestKit::createItem('queue_display', ['services' => [$opd]]);
        [, $json] = DisplayAppsTestKit::data(self::$url, $only);
        $this->assertSame(['Counter 1', 'D ' . self::XSS], array_column($json['data']['counters'], 'name'));
        $this->assertSame(['OPD ' . self::XSS], array_column($json['data']['services'], 'name'));

        // Page: escaped, chime, QR for the self-service service, script.
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('D &lt;script&gt;', $html);
        $this->assertStringContainsString('queue_display_chime.wav', $html);
        $this->assertStringContainsString('qd-qr', $html);
        $this->assertStringContainsString('queue_display.js', $html);
        $this->assertStringNotContainsString('H2-', $html);
        $this->assertFileExists(HC_ROOT . '/assets/display/apps/queue_display_chime.wav');
        $quiet = DisplayAppsTestKit::createItem('queue_display', ['chime' => false, 'show_qr' => false, 'show_recent' => false]);
        [, $html] = DisplayAppsTestKit::page(self::$url, $quiet);
        $this->assertStringNotContainsString('qdChime', $html);
        $this->assertStringNotContainsString('qd-qr', $html);
        $this->assertStringNotContainsString('qdRecent', $html);
        // Empty hotel: preview shows sample counters, the TV the empty text.
        self::wipe();
        [, $html] = DisplayAppsTestKit::page(self::$url, $quiet, ['preview' => 1]);
        $this->assertStringContainsString('A-025', $html);
        [, $html] = DisplayAppsTestKit::page(self::$url, $quiet);
        $this->assertStringContainsString('No counters yet.', $html);
        $this->assertSame('', TestEnv::phpErrors());
    }

    /** QA 2.3: two self-service services must not push the side panel off the TV (one panel, codes side by side). */
    public function testTwoSelfServiceQrCodesShareOnePanel(): void
    {
        self::wipe();
        [$a] = Queue::saveService(['name' => 'OPD', 'prefix' => 'A', 'self_service' => 1, 'is_active' => 1]);
        [$b] = Queue::saveService(['name' => 'Pharmacy <b>', 'prefix' => 'P', 'self_service' => 1, 'is_active' => 1, 'sort_order' => 1]);
        Queue::saveCounter(['name' => 'Counter 1', 'service_id' => $a, 'is_active' => 1]);
        $item = DisplayAppsTestKit::createItem('queue_display');
        $html = DisplayAppsTestKit::assertRenders($this, self::$url, $item);
        $this->assertSame(1, substr_count($html, 'qd-qr qd-qr2'));
        $this->assertSame(2, substr_count($html, 'class="qd-qr-cell"'));
        $this->assertStringContainsString('<span>Pharmacy &lt;b&gt;</span>', $html);
        $this->assertSame(2, substr_count($html, 'qd-panel qd-list'), 'the lists are the panels that give way');
        DB::update('queue_services', ['self_service' => 0], 'id = :id', ['id' => $b]);
        [, $html] = DisplayAppsTestKit::page(self::$url, $item);
        $this->assertStringNotContainsString('qd-qr2', $html);
        $this->assertSame(1, substr_count($html, 'class="qd-qr-img"'));
        $this->assertSame('', TestEnv::phpErrors());
    }
}
