<?php
declare(strict_types=1);

/**
 * QR setup of TVs ("provisioning"): a fresh TV gets a short code + secret, a hotel user claims the
 * code for a room in admin/claim.php, the TV polls its status with the secret and receives the
 * server URL, room number and the hotel's registration key, then registers via /api/device/register.
 *
 *   pending  → claimed (admin picked hotel + room) → used (device registered in that hotel)
 *   pending  → expired (15 min without claim, or the TV started a new code)
 *   claimed  → expired (30 min without registration)
 *
 * device_provisioning is NOT a tenant table (hotel_id is NULL until claimed); callers must check
 * that the user may manage the hotel they claim into (Auth::canAccessHotel + rooms.manage).
 * All timestamps in this table are UTC.
 */
final class Provisioning
{
    /** Unambiguous alphabet: no 0/O, 1/I/L. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH = 6;
    /** Seconds a code waits for a claim. */
    public const TTL = 900;
    /** Seconds a claimed result stays retrievable by the TV. */
    public const CLAIM_TTL = 1800;
    /** Suggested TV poll interval (seconds). */
    public const POLL_INTERVAL = 3;
    /** Rows older than this are purged (ProvisioningCleanupTask). */
    public const RETENTION_DAYS = 7;

    /** Test hook: replaces the random code generator. */
    public static ?Closure $codeGenerator = null;

    public static function utcNow(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    public static function ts(?string $utc): int
    {
        return $utc ? (int) strtotime($utc . ' UTC') : 0;
    }

    public static function randomCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    /** Uppercase, strip spaces / dashes; null when it cannot be a code. */
    public static function normalizeCode(mixed $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        $re = '/^[' . preg_quote(self::ALPHABET, '/') . ']{' . self::CODE_LENGTH . '}$/';
        return preg_match($re, $code) ? $code : null;
    }

    /** Is the code currently in use (pending or claimed and not yet expired)? */
    public static function codeActive(string $code): bool
    {
        return (bool) DB::value(
            "SELECT id FROM device_provisioning WHERE code = :c AND (
                (status = 'pending' AND expires_at > :n1)
             OR (status = 'claimed' AND claimed_at > :cl)) LIMIT 1",
            ['c' => $code, 'n1' => self::utcNow(), 'cl' => self::utcNow(-self::CLAIM_TTL)]
        );
    }

    /**
     * New code for a TV. Validates input; the route applies rate limits first.
     * Returns the public answer (code, secret, claim_url, expires_in, poll_interval).
     */
    public static function start(array $in, string $ip): array
    {
        $uid = trim((string) ($in['device_id'] ?? ''));
        if (!DeviceManager::validUid($uid)) {
            throw new InvalidArgumentException('device_id must be 8-64 characters (letters, digits, -)');
        }
        $code = null;
        for ($i = 0; $i < 20; $i++) {
            $try = self::$codeGenerator ? (string) (self::$codeGenerator)() : self::randomCode();
            if (self::normalizeCode($try) === $try && !self::codeActive($try)) {
                $code = $try;
                break;
            }
        }
        if ($code === null) {
            throw new RuntimeException('Could not allocate a unique setup code');
        }
        $secret = random_token(32);
        DB::transaction(static function () use ($uid, $code, $secret, $in, $ip): void {
            // A TV that starts again invalidates its previous pending code.
            DB::query("UPDATE device_provisioning SET status = 'expired' WHERE device_uid = :u AND status = 'pending'", ['u' => $uid]);
            DB::insert('device_provisioning', [
                'code' => $code,
                'secret_hash' => hash('sha256', $secret),
                'device_uid' => $uid,
                'model' => self::str($in['model'] ?? null, 100),
                'app_version' => self::str($in['app_version'] ?? null, 20),
                'ip' => substr($ip, 0, 45),
                'status' => 'pending',
                'created_at' => self::utcNow(),
                'expires_at' => self::utcNow(self::TTL),
            ]);
        });
        Logger::write('device', 'info', 'QR setup started', ['uid' => $uid, 'code' => $code, 'ip' => $ip]);
        return [
            'code' => $code,
            'secret' => $secret,
            'claim_url' => admin_url('claim.php', ['code' => $code]),
            'expires_in' => self::TTL,
            'poll_interval' => self::POLL_INTERVAL,
        ];
    }

    /** Row for code + secret (the TV's view), or null. */
    public static function findBySecret(string $code, string $secret): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return null;
        }
        $row = DB::one(
            'SELECT * FROM device_provisioning WHERE code = :c AND secret_hash = :h ORDER BY id DESC LIMIT 1',
            ['c' => $code, 'h' => hash('sha256', $secret)]
        );
        return $row && hash_equals((string) $row['secret_hash'], hash('sha256', $secret)) ? $row : null;
    }

    /** Latest pending / claimed row of a code (admin view; never exposes the secret), or null. */
    public static function findByCode(string $code): ?array
    {
        $row = DB::one(
            "SELECT * FROM device_provisioning WHERE code = :c AND status IN ('pending','claimed') ORDER BY id DESC LIMIT 1",
            ['c' => $code]
        );
        return $row ? self::refresh($row) : null;
    }

    public static function find(int $id): ?array
    {
        $row = $id > 0 ? DB::one('SELECT * FROM device_provisioning WHERE id = :id', ['id' => $id]) : null;
        return $row ? self::refresh($row) : null;
    }

    /**
     * Bring a row's status up to date: expire stale rows, mark a claimed row "used" once the device
     * registered in the claimed hotel after the claim. Returns the updated row.
     */
    public static function refresh(array $row): array
    {
        $now = time();
        if ($row['status'] === 'pending' && self::ts($row['expires_at']) <= $now) {
            return self::setStatus($row, 'expired');
        }
        if ($row['status'] === 'claimed') {
            if (self::deviceRegistered($row)) {
                return self::setStatus($row, 'used', ['used_at' => self::utcNow()]);
            }
            if (self::ts($row['claimed_at']) + self::CLAIM_TTL <= $now) {
                return self::setStatus($row, 'expired');
            }
        }
        return $row;
    }

    /** Has the TV registered in the claimed hotel since the claim? */
    public static function deviceRegistered(array $row): bool
    {
        if (empty($row['hotel_id']) || empty($row['claimed_at'])) {
            return false;
        }
        $claimedTs = self::ts($row['claimed_at']);
        // devices.registered_at is written in the hotel's time zone.
        return (bool) Tenant::run((int) $row['hotel_id'], static function () use ($row, $claimedTs): bool {
            $d = DB::one(
                'SELECT registered_at FROM devices WHERE hotel_id = :h AND device_uid = :u AND is_revoked = 0 AND room_id IS NOT NULL',
                ['h' => (int) $row['hotel_id'], 'u' => $row['device_uid']]
            );
            return $d && (int) strtotime((string) $d['registered_at']) >= $claimedTs - 1;
        });
    }

    private static function setStatus(array $row, string $status, array $extra = []): array
    {
        DB::update('device_provisioning', ['status' => $status] + $extra, 'id = :id AND status = :old', ['id' => $row['id'], 'old' => $row['status']]);
        return array_merge($row, ['status' => $status], $extra);
    }

    /**
     * Status answer for the TV (GET /api/provision/status).
     * pending | claimed (+ server_url, room_number, registration_key, hotel_name) | used | expired.
     */
    public static function statusFor(array $row): array
    {
        $row = self::refresh($row);
        if ($row['status'] === 'pending') {
            return ['status' => 'pending', 'expires_in' => max(0, self::ts($row['expires_at']) - time())];
        }
        if ($row['status'] !== 'claimed') {
            return ['status' => $row['status']];
        }
        $hid = (int) $row['hotel_id'];
        $hotel = DB::one('SELECT id, name, registration_key FROM hotels WHERE id = :id', ['id' => $hid]);
        $room = $row['room_id'] ? DB::one('SELECT room_number FROM rooms WHERE id = :id AND hotel_id = :h', ['id' => $row['room_id'], 'h' => $hid]) : null;
        if (!$hotel || !$room || (string) $hotel['registration_key'] === '') {
            // Room deleted / key removed after the claim: the TV must start again.
            self::setStatus($row, 'expired');
            return ['status' => 'expired'];
        }
        return [
            'status' => 'claimed',
            'server_url' => base_url(),
            'room_number' => (string) $room['room_number'],
            'registration_key' => (string) $hotel['registration_key'],
            'hotel_name' => (string) (Settings::getFor($hid, 'hotel_name', '') ?: $hotel['name']),
        ];
    }

    // ------------------------------------------------------------------ admin side

    /**
     * Assign a pending code to a room (atomic). Caller has checked the user may manage $hotelId
     * and that $roomId belongs to it. Returns false when the code is no longer pending.
     */
    public static function claim(int $provId, int $hotelId, int $roomId, int $userId): bool
    {
        $n = DB::query(
            "UPDATE device_provisioning SET status = 'claimed', hotel_id = :h, room_id = :r, claimed_by = :u, claimed_at = :c
             WHERE id = :id AND status = 'pending' AND expires_at > :n",
            ['h' => $hotelId, 'r' => $roomId, 'u' => $userId, 'c' => self::utcNow(), 'id' => $provId, 'n' => self::utcNow()]
        )->rowCount();
        return $n === 1;
    }

    /**
     * Problems that would make the TV's registration fail in this hotel (shown before assigning).
     * Empty list = OK. Also creates a registration key when the hotel has none.
     */
    public static function hotelProblems(int $hotelId, string $deviceUid = ''): array
    {
        return Tenant::run($hotelId, static function () use ($hotelId, $deviceUid): array {
            $problems = [];
            $state = Tenant::state($hotelId);
            if ($state !== 'active') {
                $problems[] = $state === 'expired'
                    ? __('This hotel account has expired. TVs cannot be registered until it is renewed.')
                    : __('This hotel account is suspended. TVs cannot be registered until it is reactivated.');
            }
            $max = Tenant::maxTvs($hotelId);
            if ($max !== null) {
                $already = $deviceUid !== '' && DB::value(
                    'SELECT id FROM devices WHERE hotel_id = :h AND device_uid = :u AND is_revoked = 0 AND room_id IS NOT NULL',
                    ['h' => $hotelId, 'u' => $deviceUid]
                );
                if (!$already && Tenant::tvCount($hotelId) >= $max) {
                    $problems[] = __('TV limit reached (:n TVs). Remove an old TV in Rooms & TVs or upgrade your plan before adding this one.', ['n' => $max]);
                }
            }
            if (!$problems && trim((string) DB::value('SELECT registration_key FROM hotels WHERE id = :id', ['id' => $hotelId])) === '') {
                Settings::setFor($hotelId, 'registration_key', Hotels::newRegistrationKey());
                Settings::flush();
            }
            return $problems;
        });
    }

    /** Rooms of a hotel with their active TV count; rooms without a TV first. */
    public static function rooms(int $hotelId): array
    {
        return DB::all(
            'SELECT r.id, r.room_number, r.name, r.floor,
                (SELECT COUNT(*) FROM devices d WHERE d.hotel_id = r.hotel_id AND d.room_id = r.id AND d.is_revoked = 0) AS tv_count
             FROM rooms r WHERE r.hotel_id = :h
             ORDER BY tv_count > 0, LENGTH(r.floor), r.floor, LENGTH(r.room_number), r.room_number',
            ['h' => $hotelId]
        );
    }

    /** Unclaimed, unexpired codes started in the last $minutes (platform admin only). */
    public static function pendingRecent(int $minutes = 15): array
    {
        return DB::all(
            "SELECT id, code, device_uid, model, app_version, ip, created_at, expires_at FROM device_provisioning
             WHERE status = 'pending' AND expires_at > :n AND created_at > :since ORDER BY id DESC LIMIT 50",
            ['n' => self::utcNow(), 'since' => self::utcNow(-$minutes * 60)]
        );
    }

    /** Wrong-code throttle for the claim form: true when the key is over its limit (no hit). */
    public static function throttled(string $key, int $max, int $window): bool
    {
        $now = time();
        $hits = (int) DB::value(
            'SELECT hits FROM rate_limits WHERE rl_key = :k AND window_start = :w',
            ['k' => substr($key, 0, 190), 'w' => $now - ($now % $window)]
        );
        return $hits >= $max;
    }

    /** Delete rows older than RETENTION_DAYS. Returns the number of deleted rows. */
    public static function purge(): int
    {
        return DB::query('DELETE FROM device_provisioning WHERE created_at < :c', ['c' => self::utcNow(-self::RETENTION_DAYS * 86400)])->rowCount();
    }

    private static function str(mixed $v, int $max): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', (string) $v) ?? '');
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
