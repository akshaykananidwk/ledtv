<?php
declare(strict_types=1);

/**
 * Token / queue system (hospital, clinic, bank, office) — display app "queue_display"
 * (core/Apps/QueueDisplayApp.php), staff calling page admin/queue.php (queue.operate, reception+;
 * setup queue.manage, manager+), token issue / kiosk page admin/queue_issue.php and the public
 * self-service page display/queue.php (signed URL, QR on the TV, rate-limited per IP).
 *
 * Tables (migration 016, tenant tables): queue_services, queue_counters, queue_tokens.
 *  - Numbers restart every day per service (hotel time zone, Tenant::set() sets it): the service row
 *    keeps last_date / last_number and is locked (SELECT … FOR UPDATE) while a number is issued;
 *    uq_qt_number (origin_service_id, token_date, number) makes a duplicate impossible anyway.
 *  - NEXT is one atomic `UPDATE … ORDER BY queued_at, id LIMIT 1` (InnoDB row locks): two counters
 *    pressing NEXT at the same moment always get different tokens.
 *  - Status flow: waiting → called → (serving) → done | skipped | no_show; skipped / no-show tokens can
 *    be called again by number; "transfer" puts the token at the end of another service's line.
 */
final class Queue
{
    public const STATUSES = ['waiting', 'called', 'serving', 'done', 'skipped', 'no_show'];
    public const OPEN = ['called', 'serving'];
    /** Self-service tokens per IP address and hotel in SELF_WINDOW seconds. */
    public const SELF_LIMIT = 3;
    public const SELF_WINDOW = 900;

    public static function today(): string
    {
        return date('Y-m-d');
    }

    /** Current time with microseconds (queued_at / called_at order the line and the calls). */
    public static function nowMicro(): string
    {
        return (new DateTime())->format('Y-m-d H:i:s.u');
    }

    // ------------------------------------------------------------------ services / counters

    public static function services(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM queue_services WHERE hotel_id = :h' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id', ['h' => Tenant::id()]);
    }

    public static function counters(bool $activeOnly = false): array
    {
        return DB::all('SELECT * FROM queue_counters WHERE hotel_id = :h' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY sort_order, id', ['h' => Tenant::id()]);
    }

    public static function findService(int $id): ?array
    {
        return Tenant::find('queue_services', $id);
    }

    public static function findCounter(int $id): ?array
    {
        return Tenant::find('queue_counters', $id);
    }

    public static function findToken(int $id): ?array
    {
        return Tenant::find('queue_tokens', $id);
    }

    private static function line(mixed $v, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($v) ? (string) $v : '')), 0, $max);
    }

    /** @return array{0: ?int, 1: string[]} */
    public static function saveService(array $in, ?int $id = null): array
    {
        $errors = [];
        $name = self::line($in['name'] ?? '', 120);
        if ($name === '') {
            $errors[] = __('The service name is required.');
        }
        $prefix = strtoupper(self::line($in['prefix'] ?? '', 10));
        if ($prefix !== '' && !preg_match('/^[A-Z0-9]{1,5}$/', $prefix)) {
            $errors[] = __('The prefix can have up to 5 letters or digits.');
        }
        $start = is_numeric($in['start_number'] ?? null) ? (int) $in['start_number'] : 1;
        if ($start < 1 || $start > 99999) {
            $errors[] = __('The first number must be between 1 and 99999.');
        }
        if ($id && !self::findService($id)) {
            return [null, [__('Not found.')]];
        }
        if ($errors) {
            return [null, $errors];
        }
        $data = [
            'name' => $name,
            'prefix' => $prefix,
            'start_number' => $start,
            'self_service' => !empty($in['self_service']) ? 1 : 0,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'sort_order' => is_numeric($in['sort_order'] ?? null) ? max(-9999, min(9999, (int) $in['sort_order'])) : 0,
        ];
        if ($id) {
            DB::update('queue_services', $data, 'id = :id', ['id' => $id]);
            return [$id, []];
        }
        return [DB::insert('queue_services', $data + ['created_at' => now()]), []];
    }

    /** Delete a service with its tokens; its counters then call every service. */
    public static function deleteService(int $id): bool
    {
        if (!self::findService($id)) {
            return false;
        }
        DB::transaction(static function () use ($id): void {
            DB::query('DELETE FROM queue_tokens WHERE hotel_id = :h AND (service_id = :s1 OR origin_service_id = :s2)', ['h' => Tenant::id(), 's1' => $id, 's2' => $id]);
            DB::update('queue_counters', ['service_id' => null], 'service_id = :s', ['s' => $id]);
            DB::delete('queue_services', 'id = :id', ['id' => $id]);
        });
        return true;
    }

    /** @return array{0: ?int, 1: string[]} */
    public static function saveCounter(array $in, ?int $id = null): array
    {
        $errors = [];
        $name = self::line($in['name'] ?? '', 60);
        if ($name === '') {
            $errors[] = __('The counter name is required.');
        }
        $sid = is_numeric($in['service_id'] ?? null) ? (int) $in['service_id'] : 0;
        if ($sid > 0 && !self::findService($sid)) { // another hotel's id → 404
            $errors[] = __('Choose a service.');
        }
        if ($id && !self::findCounter($id)) {
            return [null, [__('Not found.')]];
        }
        if ($errors) {
            return [null, $errors];
        }
        $data = [
            'name' => $name,
            'service_id' => $sid > 0 ? $sid : null,
            'room_text' => self::line($in['room_text'] ?? '', 120) ?: null,
            'is_active' => !empty($in['is_active']) ? 1 : 0,
            'sort_order' => is_numeric($in['sort_order'] ?? null) ? max(-9999, min(9999, (int) $in['sort_order'])) : 0,
        ];
        if ($id) {
            DB::update('queue_counters', $data, 'id = :id', ['id' => $id]);
            return [$id, []];
        }
        return [DB::insert('queue_counters', $data + ['created_at' => now()]), []];
    }

    public static function deleteCounter(int $id): bool
    {
        if (!self::findCounter($id)) {
            return false;
        }
        DB::query("UPDATE queue_tokens SET status = 'waiting', counter_id = NULL WHERE hotel_id = :h AND counter_id = :c AND status IN ('called','serving')", ['h' => Tenant::id(), 'c' => $id]);
        DB::delete('queue_counters', 'id = :id', ['id' => $id]);
        return true;
    }

    /** Start today's numbering of a service again: today's tokens of the service are removed. */
    public static function reset(int $serviceId): bool
    {
        $s = self::findService($serviceId);
        if (!$s) {
            return false;
        }
        DB::transaction(static function () use ($s): void {
            $today = self::today();
            DB::query('SELECT id FROM queue_services WHERE id = :id FOR UPDATE', ['id' => (int) $s['id']]);
            DB::query('DELETE FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND (service_id = :s1 OR origin_service_id = :s2)', ['h' => Tenant::id(), 'd' => $today, 's1' => (int) $s['id'], 's2' => (int) $s['id']]);
            DB::update('queue_services', ['last_date' => $today, 'last_number' => max(0, (int) $s['start_number'] - 1)], 'id = :id', ['id' => (int) $s['id']]);
        });
        return true;
    }

    // ------------------------------------------------------------------ tokens

    /** "A-025" (prefix + at least 3 digits). */
    public static function label(array $t): string
    {
        $n = str_pad((string) (int) $t['number'], 3, '0', STR_PAD_LEFT);
        return ((string) $t['prefix'] !== '' ? $t['prefix'] . '-' : '') . $n;
    }

    /** Text for speech: "A 25". */
    public static function spoken(array $t): string
    {
        return trim((string) $t['prefix'] . ' ' . (int) $t['number']);
    }

    /**
     * Issue the next number of a service. $o: name, phone, source (desk|self), ip, user.
     * Numbers restart each day (hotel date). Throws DomainException('SERVICE_CLOSED') for an inactive service.
     */
    public static function issue(int $serviceId, array $o = []): array
    {
        if (!self::findService($serviceId)) {
            throw new DomainException('NOT_FOUND');
        }
        $id = DB::transaction(static function () use ($serviceId, $o): int {
            $today = self::today();
            $s = DB::one('SELECT * FROM queue_services WHERE id = :id AND hotel_id = :h FOR UPDATE', ['id' => $serviceId, 'h' => Tenant::id()]);
            if (!$s || !(int) $s['is_active']) {
                throw new DomainException('SERVICE_CLOSED');
            }
            $last = $s['last_date'] === $today ? (int) $s['last_number'] : (int) $s['start_number'] - 1;
            $number = max($last + 1, (int) $s['start_number']);
            DB::update('queue_services', ['last_date' => $today, 'last_number' => $number], 'id = :id', ['id' => $serviceId]);
            $now = self::nowMicro();
            return DB::insert('queue_tokens', [
                'service_id' => $serviceId,
                'origin_service_id' => $serviceId,
                'token_date' => $today,
                'number' => $number,
                'prefix' => (string) $s['prefix'],
                'status' => 'waiting',
                'customer_name' => self::line($o['name'] ?? '', 80) ?: null,
                'customer_phone' => mb_substr((string) preg_replace('/[^0-9+ ]/', '', (string) ($o['phone'] ?? '')), 0, 20) ?: null,
                'source' => ($o['source'] ?? 'desk') === 'self' ? 'self' : 'desk',
                'issued_by' => $o['user'] ?? null,
                'ip_address' => isset($o['ip']) ? mb_substr((string) $o['ip'], 0, 45) : null,
                'created_at' => substr($now, 0, 19),
                'queued_at' => $now,
            ]);
        });
        return (array) self::findToken($id);
    }

    /** Service ids a counter calls (null = every service). */
    private static function counterServices(array $counter): ?array
    {
        return $counter['service_id'] ? [(int) $counter['service_id']] : null;
    }

    /** Token currently at a counter (called / serving today) or null. */
    public static function current(array $counter): ?array
    {
        return DB::one(
            "SELECT * FROM queue_tokens WHERE hotel_id = :h AND counter_id = :c AND status IN ('called','serving') AND token_date = :d ORDER BY called_at DESC, id DESC LIMIT 1",
            ['h' => Tenant::id(), 'c' => (int) $counter['id'], 'd' => self::today()]
        );
    }

    /** Close the counter's open token(s) with $status (done / skipped / no_show). */
    private static function closeOpen(array $counter, string $status): int
    {
        return DB::query(
            "UPDATE queue_tokens SET status = :st, done_at = :t WHERE hotel_id = :h AND counter_id = :c AND status IN ('called','serving')",
            ['st' => $status, 't' => now(), 'h' => Tenant::id(), 'c' => (int) $counter['id']]
        )->rowCount();
    }

    /** Waiting tokens today (for the counter's services, or the given services; null = all). */
    public static function waiting(?array $serviceIds = null): int
    {
        $sql = "SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND status = 'waiting'";
        $p = ['h' => Tenant::id(), 'd' => self::today()];
        if ($serviceIds !== null) {
            [$in, $ip] = DB::in($serviceIds ?: [0], 's');
            $sql .= " AND service_id IN $in";
            $p += $ip;
        }
        return (int) DB::value($sql, $p);
    }

    public static function waitingFor(array $counter): int
    {
        return self::waiting(self::counterServices($counter));
    }

    /**
     * NEXT: finish the counter's current token (done) and call the oldest waiting token of its
     * services. Atomic: the UPDATE … LIMIT 1 locks and claims exactly one row. Null when nobody waits.
     */
    public static function next(array $counter): ?array
    {
        return self::atomic(static function () use ($counter): ?array {
            self::closeOpen($counter, 'done');
            $sql = "UPDATE queue_tokens SET status = 'called', counter_id = :c, called_at = :t, recall_count = 0
                    WHERE hotel_id = :h AND token_date = :d AND status = 'waiting'";
            $p = ['c' => (int) $counter['id'], 't' => self::nowMicro(), 'h' => Tenant::id(), 'd' => self::today()];
            $services = self::counterServices($counter);
            if ($services !== null) {
                [$in, $ip] = DB::in($services, 's');
                $sql .= " AND service_id IN $in";
                $p += $ip;
            }
            if (DB::query($sql . ' ORDER BY queued_at, id LIMIT 1', $p)->rowCount() !== 1) {
                return null;
            }
            return self::current($counter);
        });
    }

    /**
     * Call a specific number ("A-25", "a25", "25") that is waiting, skipped or a no-show today.
     * Searches the counter's services first, then every service. Null when not found / taken.
     */
    public static function callNumber(array $counter, string $code): ?array
    {
        if (!preg_match('/^\s*([A-Za-z0-9]{0,5}?)[\s-]*0*(\d{1,6})\s*$/', $code, $m)) {
            return null;
        }
        $prefix = strtoupper($m[1]);
        $number = (int) $m[2];
        $p = ['h' => Tenant::id(), 'd' => self::today(), 'n' => $number];
        $sql = "SELECT id, service_id FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND number = :n AND status IN ('waiting','skipped','no_show')";
        if ($prefix !== '') {
            $sql .= ' AND prefix = :p';
            $p['p'] = $prefix;
        }
        $rows = DB::all($sql . ' ORDER BY id', $p);
        if (!$rows) {
            return null;
        }
        $mine = self::counterServices($counter);
        $pick = $rows[0];
        foreach ($rows as $r) {
            if ($mine === null || in_array((int) $r['service_id'], $mine, true)) {
                $pick = $r;
                break;
            }
        }
        return self::atomic(static function () use ($counter, $pick): ?array {
            self::closeOpen($counter, 'done');
            $n = DB::query(
                "UPDATE queue_tokens SET status = 'called', counter_id = :c, called_at = :t, recall_count = 0, done_at = NULL
                 WHERE id = :id AND hotel_id = :h AND status IN ('waiting','skipped','no_show')",
                ['c' => (int) $counter['id'], 't' => self::nowMicro(), 'id' => (int) $pick['id'], 'h' => Tenant::id()]
            )->rowCount();
            return $n === 1 ? self::current($counter) : null;
        });
    }

    /**
     * Run a claiming transaction in READ COMMITTED (no gap locks, UPDATE skips rows another counter
     * claimed meanwhile) and retry on a deadlock / lock wait timeout (MySQL 1213 / 1205).
     */
    private static function atomic(callable $fn): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                if (!DB::pdo()->inTransaction()) {
                    DB::pdo()->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                return DB::transaction($fn);
            } catch (PDOException $e) {
                if ($try >= 4 || !in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $e;
                }
                usleep(20000 * $try);
            }
        }
    }

    /** Call the current token again (TV blinks + chime again). */
    public static function recall(array $counter): ?array
    {
        $t = self::current($counter);
        if (!$t) {
            return null;
        }
        DB::query('UPDATE queue_tokens SET recall_count = recall_count + 1, called_at = :t WHERE id = :id AND hotel_id = :h', ['t' => self::nowMicro(), 'id' => (int) $t['id'], 'h' => Tenant::id()]);
        return self::findToken((int) $t['id']);
    }

    /** The visitor arrived at the counter: called → serving. */
    public static function serving(array $counter): ?array
    {
        $t = self::current($counter);
        if (!$t) {
            return null;
        }
        DB::query("UPDATE queue_tokens SET status = 'serving' WHERE id = :id AND hotel_id = :h", ['id' => (int) $t['id'], 'h' => Tenant::id()]);
        return self::findToken((int) $t['id']);
    }

    /** Finish the current token: done | skipped | no_show. Returns the closed token or null. */
    public static function finish(array $counter, string $status): ?array
    {
        if (!in_array($status, ['done', 'skipped', 'no_show'], true)) {
            throw new InvalidArgumentException('Unknown status');
        }
        $t = self::current($counter);
        if (!$t) {
            return null;
        }
        self::closeOpen($counter, $status);
        return self::findToken((int) $t['id']);
    }

    /** Send the current token to the end of another service's line (keeps its number). */
    public static function transfer(array $counter, int $serviceId): ?array
    {
        $s = self::findService($serviceId); // another hotel's id → 404
        $t = self::current($counter);
        if (!$s || !$t) {
            return null;
        }
        DB::query(
            "UPDATE queue_tokens SET service_id = :s, status = 'waiting', counter_id = NULL, queued_at = :t, called_at = NULL WHERE id = :id AND hotel_id = :h",
            ['s' => $serviceId, 't' => self::nowMicro(), 'id' => (int) $t['id'], 'h' => Tenant::id()]
        );
        return self::findToken((int) $t['id']);
    }

    /** People waiting before a token in its line (0 = next). */
    public static function ahead(array $t): int
    {
        if ($t['status'] !== 'waiting') {
            return 0;
        }
        return (int) DB::value(
            "SELECT COUNT(*) FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND status = 'waiting' AND service_id = :s
             AND (queued_at < :q1 OR (queued_at = :q2 AND id < :id))",
            ['h' => Tenant::id(), 'd' => (string) $t['token_date'], 's' => (int) $t['service_id'], 'q1' => $t['queued_at'], 'q2' => $t['queued_at'], 'id' => (int) $t['id']]
        );
    }

    /** Translated status label. */
    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'waiting' => __('Waiting'),
            'called' => __('Called'),
            'serving' => __('Being served'),
            'done' => __('Done'),
            'skipped' => __('Skipped'),
            'no_show' => __('No-show'),
            default => $s,
        };
    }

    /** JSON-able token for the staff page / public page. */
    public static function tokenJson(?array $t): ?array
    {
        if (!$t) {
            return null;
        }
        return [
            'id' => (int) $t['id'], 'label' => self::label($t), 'status' => (string) $t['status'], 'status_label' => self::statusLabel((string) $t['status']),
            'service_id' => (int) $t['service_id'], 'recall' => (int) $t['recall_count'], 'name' => (string) ($t['customer_name'] ?? ''),
            'phone' => (string) ($t['customer_phone'] ?? ''), 'called_at' => $t['called_at'],
        ];
    }

    /** Today's token counts per status for the staff page. */
    public static function todayStats(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach (DB::all('SELECT status, COUNT(*) n FROM queue_tokens WHERE hotel_id = :h AND token_date = :d GROUP BY status', ['h' => Tenant::id(), 'd' => self::today()]) as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    // ------------------------------------------------------------------ TV data

    /**
     * Data of the queue display (polled every 2 s — a handful of indexed queries, no joins).
     * $serviceIds [] = all services. $lang only affects the spoken text (I18n is already switched).
     */
    public static function display(array $serviceIds = []): array
    {
        $h = Tenant::id();
        $today = self::today();
        $services = [];
        foreach (DB::all('SELECT id, name, prefix FROM queue_services WHERE hotel_id = :h AND is_active = 1 ORDER BY sort_order, id', ['h' => $h]) as $s) {
            if (!$serviceIds || in_array((int) $s['id'], $serviceIds, true)) {
                $services[(int) $s['id']] = ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'waiting' => 0];
            }
        }
        foreach (DB::all("SELECT service_id, COUNT(*) n FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND status = 'waiting' GROUP BY service_id", ['h' => $h, 'd' => $today]) as $r) {
            if (isset($services[(int) $r['service_id']])) {
                $services[(int) $r['service_id']]['waiting'] = (int) $r['n'];
            }
        }
        $counters = [];
        foreach (DB::all('SELECT id, name, service_id, room_text FROM queue_counters WHERE hotel_id = :h AND is_active = 1 ORDER BY sort_order, id', ['h' => $h]) as $c) {
            $sid = $c['service_id'] ? (int) $c['service_id'] : 0;
            if ($serviceIds && $sid && !isset($services[$sid])) {
                continue;
            }
            $counters[(int) $c['id']] = [
                'id' => (int) $c['id'], 'name' => (string) $c['name'], 'room' => (string) ($c['room_text'] ?? ''),
                'service' => $sid && isset($services[$sid]) ? $services[$sid]['name'] : '', 'token' => '', 'key' => '', 'status' => '', 'say' => '',
            ];
        }
        $open = DB::all(
            "SELECT id, counter_id, service_id, prefix, number, status, recall_count FROM queue_tokens
             WHERE hotel_id = :h AND token_date = :d AND status IN ('called','serving') ORDER BY called_at, id",
            ['h' => $h, 'd' => $today]
        );
        foreach ($open as $t) {
            $cid = (int) $t['counter_id'];
            if (!isset($counters[$cid]) || ($serviceIds && !isset($services[(int) $t['service_id']]))) {
                continue;
            }
            $counters[$cid]['token'] = self::label($t);
            $counters[$cid]['key'] = $t['id'] . ':' . $t['recall_count'];
            $counters[$cid]['status'] = (string) $t['status'];
            $counters[$cid]['say'] = __('Token :token, :counter', ['token' => self::spoken($t), 'counter' => $counters[$cid]['name']]);
        }
        $recent = [];
        foreach (DB::all(
            'SELECT counter_id, service_id, prefix, number FROM queue_tokens WHERE hotel_id = :h AND token_date = :d AND called_at IS NOT NULL ORDER BY called_at DESC, id DESC LIMIT 12',
            ['h' => $h, 'd' => $today]
        ) as $t) {
            if (!isset($counters[(int) $t['counter_id']]) || ($serviceIds && !isset($services[(int) $t['service_id']]))) {
                continue;
            }
            $recent[] = ['token' => self::label($t), 'counter' => $counters[(int) $t['counter_id']]['name']];
            if (count($recent) >= 5) {
                break;
            }
        }
        return ['counters' => array_values($counters), 'recent' => $recent, 'services' => array_values($services)];
    }

    // ------------------------------------------------------------------ public self-service

    private static function sign(string $msg): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            throw new RuntimeException('APP_KEY missing from .env');
        }
        return substr(hash_hmac('sha256', $msg, $key), 0, 32);
    }

    public static function serviceSignature(int $hotelId, int $serviceId): string
    {
        return self::sign('queue:' . $hotelId . ':' . $serviceId);
    }

    public static function tokenSignature(int $hotelId, int $tokenId): string
    {
        return self::sign('queue-token:' . $hotelId . ':' . $tokenId);
    }

    public static function verify(string $expected, mixed $sig): bool
    {
        return is_string($sig) && preg_match('/^[0-9a-f]{32}$/', $sig) === 1 && hash_equals($expected, $sig);
    }

    /** Signed public "take a token" URL of a service (shown as QR on the TV). */
    public static function publicUrl(array $service): string
    {
        $h = (int) ($service['hotel_id'] ?? Tenant::id());
        return base_url('display/queue.php') . '?' . http_build_query(['h' => $h, 'q' => (int) $service['id'], 's' => self::serviceSignature($h, (int) $service['id'])]);
    }

    /** Signed public status URL of a token (after self-service). */
    public static function statusUrl(array $service, array $token): string
    {
        $h = (int) $token['hotel_id'];
        return self::publicUrl($service) . '&' . http_build_query(['t' => (int) $token['id'], 'k' => self::tokenSignature($h, (int) $token['id'])]);
    }
}
