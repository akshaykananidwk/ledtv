<?php
declare(strict_types=1);

/**
 * 2.7 TV app releases (docs/modules/apk_manager.md).
 *
 *  - Platform releases: uploaded once in Super Admin → APK Manager (admin/platform_apk.php), stored in
 *    apk_releases with hotel_id NULL; rollout 'all' customers or 'selected' customers (apk_release_hotels).
 *  - Customer releases: a customer's own APK Manager (admin/apk.php, feature "TV app updates"), hotel_id set.
 *
 * Precedence for a TV: the highest versionCode among {platform releases rolled out to its customer, its
 * customer's own releases} is offered (a tie goes to the platform release). The TV MUST update on app
 * start when its installed versionCode is lower than the highest *required* (is_required = 1) release
 * among the same set — required_version_code below. Web players (platform 'web') never get an APK.
 */
final class AppReleases
{
    public const PACKAGE = 'com.hotelcast.tv';
    /** SHA-256 of the release signing certificate (android/keystore). Platform setting platform_apk_signer overrides it; '-' disables the check. */
    public const DEFAULT_SIGNER = 'b0f2c89990dcc8376790e2d6add7f9830f3990f1e8e44abb29ef7eb1dc1a156c';
    public const MAX_MB = 300;

    private static ?bool $available = null;

    public static function forget(): void
    {
        self::$available = null;
    }

    /** Migration 034 applied (platform releases supported). */
    public static function available(): bool
    {
        return self::$available ??= Migrator::hasTable(DB::pdo(), 'apk_release_hotels') && Migrator::hasColumn(DB::pdo(), 'apk_releases', 'is_required');
    }

    /** Expected signing certificate (lower-case hex) or '' when the check is disabled. */
    public static function expectedSigner(): string
    {
        $v = strtolower(trim((string) Settings::platform('platform_apk_signer', self::DEFAULT_SIGNER)));
        return $v === '-' || $v === 'off' ? '' : $v;
    }

    // ------------------------------------------------------------------ queries

    /** SQL condition "release a is offered to customer :hid" (a = apk_releases alias). */
    private static function eligibleSql(string $p = 'eh'): string
    {
        $own = "a.hotel_id = :{$p}1";
        if (!self::available()) {
            return "($own)";
        }
        return "($own OR (a.hotel_id IS NULL AND (a.rollout = 'all' OR EXISTS (SELECT 1 FROM apk_release_hotels x WHERE x.release_id = a.id AND x.hotel_id = :{$p}2))))";
    }

    private static function eligibleParams(int $hotelId, string $p = 'eh'): array
    {
        return self::available() ? [$p . '1' => $hotelId, $p . '2' => $hotelId] : [$p . '1' => $hotelId];
    }

    /**
     * Release state for a customer: ['latest' => row|null (highest versionCode offered), 'required_code' => int
     * (highest required versionCode, 0 = none)].
     */
    public static function forHotel(int $hotelId): array
    {
        $order = self::available() ? 'a.version_code DESC, (a.hotel_id IS NULL) DESC, a.id DESC' : 'a.version_code DESC, a.id DESC';
        $latest = DB::one('SELECT a.* FROM apk_releases a WHERE ' . self::eligibleSql() . ' ORDER BY ' . $order . ' LIMIT 1', self::eligibleParams($hotelId));
        $required = self::available()
            ? (int) (DB::value('SELECT MAX(a.version_code) FROM apk_releases a WHERE a.is_required = 1 AND ' . self::eligibleSql(), self::eligibleParams($hotelId)) ?? 0)
            : 0;
        return ['latest' => $latest ?: null, 'required_code' => $required];
    }

    /** Release row a TV may download (own customer's or a platform release rolled out to it), else null. */
    public static function findForDevice(int $releaseId, array $device): ?array
    {
        $row = DB::one('SELECT a.* FROM apk_releases a WHERE a.id = :id AND ' . self::eligibleSql(), ['id' => $releaseId] + self::eligibleParams((int) $device['hotel_id']));
        return $row ?: null;
    }

    /**
     * Update info for a TV (device API: GET device/app-version and the poll response), null for web players
     * and when no release exists. The TV blocks on start when installed < required_version_code < = version_code.
     */
    public static function forDevice(array $device): ?array
    {
        if (DeviceManager::isWeb($device)) {
            return null;
        }
        $st = self::forHotel((int) $device['hotel_id']);
        $r = $st['latest'];
        if (!$r) {
            return null;
        }
        return [
            'version_code' => (int) $r['version_code'],
            'version_name' => (string) $r['version_name'],
            'sha256' => (string) $r['sha256'],
            'size' => (int) $r['file_size'],
            'url' => base_url('api/device/apk/' . (int) $r['id']),
            'required' => $st['required_code'] > 0,
            'required_version_code' => $st['required_code'],
            'notes' => (string) ($r['notes'] ?? ''),
            'source' => $r['hotel_id'] === null ? 'platform' : 'customer',
        ];
    }

    /** Platform releases, newest first, with uploader name, installed count and selected customers. */
    public static function platformReleases(): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = DB::all('SELECT a.*, u.username FROM apk_releases a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.hotel_id IS NULL ORDER BY a.version_code DESC, a.id DESC');
        $installed = self::installedCounts();
        $sel = [];
        foreach (DB::all('SELECT x.release_id, x.hotel_id, h.name FROM apk_release_hotels x JOIN hotels h ON h.id = x.hotel_id ORDER BY h.name') as $x) {
            $sel[(int) $x['release_id']][(int) $x['hotel_id']] = (string) $x['name'];
        }
        foreach ($rows as $i => &$r) {
            $r['installed'] = $installed[(int) $r['version_code']] ?? 0;
            $r['customers'] = $sel[(int) $r['id']] ?? [];
            $r['is_latest'] = $i === 0;
        }
        unset($r);
        return $rows;
    }

    /** Highest platform release (null = none). */
    public static function platformLatest(): ?array
    {
        if (!self::available()) {
            return null;
        }
        return DB::one('SELECT * FROM apk_releases WHERE hotel_id IS NULL ORDER BY version_code DESC, id DESC LIMIT 1') ?: null;
    }

    /** versionCode => number of active Android TVs that report it. */
    public static function installedCounts(): array
    {
        $out = [];
        foreach (DB::all('SELECT app_version_code AS c, COUNT(*) AS n FROM devices WHERE is_revoked = 0 AND app_version_code IS NOT NULL' . DeviceManager::notWebSql() . ' GROUP BY app_version_code') as $r) {
            $out[(int) $r['c']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Dashboard numbers against each customer's effective latest version:
     * ['android' => n, 'on_latest' => n, 'outdated' => n, 'unknown' => n, 'latest' => ?row].
     */
    public static function fleetStats(): array
    {
        $c = PlatformScreens::counters(null);
        $android = (int) DB::value('SELECT COUNT(*) FROM devices d WHERE d.is_revoked = 0' . DeviceManager::notWebSql('d.platform'));
        $unknown = (int) DB::value('SELECT COUNT(*) FROM devices d WHERE d.is_revoked = 0 AND d.app_version_code IS NULL' . DeviceManager::notWebSql('d.platform'));
        return ['android' => $android, 'outdated' => (int) $c['outdated'], 'unknown' => $unknown,
            'on_latest' => max(0, $android - (int) $c['outdated'] - $unknown), 'latest' => self::platformLatest()];
    }

    // ------------------------------------------------------------------ changes

    /**
     * Validate and store a platform release. $file: one $_FILES entry. $opt: notes, required (bool),
     * rollout ('all'|'selected'), hotels (int[]). Returns the new release id.
     *
     * @throws RuntimeException with a user-friendly message
     */
    public static function upload(array $file, array $opt, ?int $userId): int
    {
        if (!self::available()) {
            throw new RuntimeException(__('Run the database update first (migration 034).'));
        }
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException(__('No file was selected.'));
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            Uploader::handle($file, 'apk', 'platform'); // throws the matching upload error message
            throw new RuntimeException(__('Upload failed.'));
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_MB * 1024 * 1024) {
            throw new RuntimeException(__('File too large. Maximum is :m MB.', ['m' => self::MAX_MB]));
        }
        if (strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'apk') {
            throw new RuntimeException(__('Only .apk files are allowed.'));
        }
        $info = ApkInfo::read((string) $file['tmp_name']);
        if ($info['package'] !== self::PACKAGE) {
            throw new RuntimeException(__('Wrong app: the APK package is :p, expected :e.', ['p' => $info['package'], 'e' => self::PACKAGE]));
        }
        $signer = self::expectedSigner();
        if ($signer !== '') {
            if ($info['signer_sha256'] === null) {
                throw new RuntimeException(__('The APK is not signed with APK Signature Scheme v2/v3. Build it with ./gradlew assembleRelease.'));
            }
            if (!hash_equals($signer, $info['signer_sha256'])) {
                throw new RuntimeException(__('The APK is signed with a different key (:s…). TVs would refuse it; sign it with the release key.', ['s' => substr($info['signer_sha256'], 0, 12)]));
            }
        }
        $max = (int) (DB::value('SELECT MAX(version_code) FROM apk_releases WHERE hotel_id IS NULL') ?? 0);
        if ($info['version_code'] <= $max) {
            throw new RuntimeException(__('Version code :c is not newer than the latest platform release (:m). Increase versionCode in the app build.', ['c' => $info['version_code'], 'm' => $max]));
        }
        $up = Uploader::handle($file, 'apk', 'platform');
        $abs = HC_ROOT . '/storage/' . $up['path'];
        $hotels = self::cleanHotels($opt['hotels'] ?? []);
        $rollout = ($opt['rollout'] ?? 'all') === 'selected' ? 'selected' : 'all';
        if ($rollout === 'selected' && !$hotels) {
            @unlink($abs);
            throw new RuntimeException(__('Select at least one customer, or roll out to all customers.'));
        }
        $name = preg_replace('/[^0-9A-Za-z._-]/', '', $info['version_name']) ?: (string) $info['version_code'];
        $id = DB::transaction(static function () use ($up, $abs, $info, $name, $opt, $rollout, $hotels, $userId): int {
            $id = DB::insert('apk_releases', [
                'hotel_id' => null,
                'version_name' => mb_substr($name, 0, 30),
                'version_code' => $info['version_code'],
                'file_path' => $up['path'],
                'file_size' => (int) filesize($abs),
                'sha256' => (string) hash_file('sha256', $abs),
                'notes' => mb_substr(trim((string) ($opt['notes'] ?? '')), 0, 5000) ?: null,
                'is_required' => !empty($opt['required']) ? 1 : 0,
                'rollout' => $rollout,
                'package_name' => $info['package'],
                'signer_sha256' => $info['signer_sha256'],
                'uploaded_by' => $userId,
                'created_at' => now(),
            ]);
            self::saveHotels($id, $rollout === 'selected' ? $hotels : []);
            return $id;
        });
        ActivityLog::add('apk_upload', 'apk', $id, 'Platform v' . $name . ' (' . $info['version_code'] . ')' . (!empty($opt['required']) ? ', required' : '') . ', rollout ' . $rollout, null);
        Settings::bumpContentVersion();
        return $id;
    }

    /** Change required / notes / rollout of a platform release. */
    public static function saveOptions(int $id, array $opt): void
    {
        $r = self::platformRelease($id);
        $hotels = self::cleanHotels($opt['hotels'] ?? []);
        $rollout = ($opt['rollout'] ?? 'all') === 'selected' ? 'selected' : 'all';
        if ($rollout === 'selected' && !$hotels) {
            throw new RuntimeException(__('Select at least one customer, or roll out to all customers.'));
        }
        DB::transaction(static function () use ($id, $opt, $rollout, $hotels): void {
            DB::query('UPDATE apk_releases SET is_required = :q, rollout = :r, notes = :n WHERE id = :id AND hotel_id IS NULL', [
                'q' => !empty($opt['required']) ? 1 : 0, 'r' => $rollout,
                'n' => mb_substr(trim((string) ($opt['notes'] ?? '')), 0, 5000) ?: null, 'id' => $id,
            ]);
            self::saveHotels($id, $rollout === 'selected' ? $hotels : []);
        });
        ActivityLog::add('apk_options', 'apk', $id, 'Platform v' . $r['version_name'] . ': ' . (!empty($opt['required']) ? 'required' : 'optional') . ', rollout ' . $rollout, null);
    }

    /** Delete a platform release (never the latest one: TVs may be downloading it). */
    public static function delete(int $id): array
    {
        $r = self::platformRelease($id);
        $latest = self::platformLatest();
        if ($latest && (int) $latest['id'] === $id) {
            throw new RuntimeException(__('The latest release cannot be deleted. Upload a newer version first.'));
        }
        DB::query('DELETE FROM apk_releases WHERE id = :id AND hotel_id IS NULL', ['id' => $id]);
        $path = (string) $r['file_path'];
        if ($path !== '' && !str_contains($path, '..') && str_starts_with($path, 'apk/platform/')) {
            @unlink(HC_ROOT . '/storage/' . $path);
        }
        ActivityLog::add('apk_delete', 'apk', $id, 'Platform v' . $r['version_name'] . ' (' . $r['version_code'] . ')', null);
        return $r;
    }

    /**
     * "Update all TVs now": queue UPDATE_APP with each customer's effective latest release for every online,
     * active Android TV (with a screen) that reports an older versionCode. Returns
     * ['tvs' => n, 'customers' => n, 'up_to_date' => n, 'no_release' => n].
     */
    public static function pushToAll(?int $userId = null): array
    {
        $out = ['tvs' => 0, 'customers' => 0, 'up_to_date' => 0, 'no_release' => 0];
        $rows = DB::all("SELECT d.id, d.hotel_id, d.room_id, d.app_version_code FROM devices d JOIN hotels h ON h.id = d.hotel_id
                          WHERE d.is_revoked = 0 AND d.status = 'online' AND d.room_id IS NOT NULL" . DeviceManager::notWebSql('d.platform') . ' ORDER BY d.hotel_id, d.id');
        $byHotel = [];
        foreach ($rows as $r) {
            $byHotel[(int) $r['hotel_id']][] = $r;
        }
        foreach ($byHotel as $hid => $devs) {
            $rel = self::forHotel($hid)['latest'];
            if (!$rel) {
                $out['no_release'] += count($devs);
                continue;
            }
            $code = (int) $rel['version_code'];
            $todo = array_values(array_filter($devs, static fn ($d) => $d['app_version_code'] === null || (int) $d['app_version_code'] < $code));
            $out['up_to_date'] += count($devs) - count($todo);
            if (!$todo) {
                continue;
            }
            $payload = ['url' => base_url('api/device/apk/' . (int) $rel['id']), 'version_code' => $code,
                'version_name' => $rel['version_name'], 'sha256' => $rel['sha256']];
            Tenant::run($hid, static function () use ($todo, $payload, $userId, $rel): void {
                $roomIds = array_values(array_unique(array_map(static fn ($d) => (int) $d['room_id'], $todo)));
                $bid = DB::insert('broadcast_commands', [
                    'title' => 'UPDATE_APP', 'command' => 'UPDATE_APP', 'target_type' => 'rooms', 'target_ids' => json_out($roomIds),
                    'payload' => json_out((object) $payload), 'mode' => 'now', 'status' => 'completed', 'start_at' => now(),
                    'created_by' => $userId, 'created_at' => now(),
                ]);
                $json = json_out((object) $payload);
                foreach ($todo as $d) {
                    // One pending update per TV: older queued UPDATE_APP commands are replaced.
                    DB::query("UPDATE device_commands SET status = 'expired' WHERE device_id = :d AND command = 'UPDATE_APP' AND status IN ('pending','delivered')", ['d' => $d['id']]);
                    DB::insert('device_commands', ['device_id' => $d['id'], 'broadcast_id' => $bid, 'command' => 'UPDATE_APP', 'payload' => $json, 'status' => 'pending', 'created_at' => now()]);
                    DB::insert('broadcast_logs', ['broadcast_id' => $bid, 'device_id' => $d['id'], 'room_id' => $d['room_id'], 'event' => 'queued', 'message' => 'UPDATE_APP', 'created_at' => now()]);
                }
                ActivityLog::add('platform_command', 'apk', (int) $rel['id'], 'UPDATE_APP v' . $rel['version_name'] . ' → ' . count($todo) . ' TV(s) (Super Admin → APK Manager)', Tenant::id());
            });
            $out['customers']++;
            $out['tvs'] += count($todo);
        }
        ActivityLog::add('platform_command', 'apk', null, 'Update all TVs now: ' . $out['tvs'] . ' TV(s) of ' . $out['customers'] . ' customer(s)', null);
        return $out;
    }

    /** Count a download (device API). */
    public static function countDownload(int $id): void
    {
        if (self::available()) {
            DB::query('UPDATE apk_releases SET downloads = downloads + 1 WHERE id = :id', ['id' => $id]);
        }
    }

    // ------------------------------------------------------------------ helpers

    private static function platformRelease(int $id): array
    {
        $r = self::available() ? DB::one('SELECT * FROM apk_releases WHERE id = :id AND hotel_id IS NULL', ['id' => $id]) : null;
        if (!$r) {
            throw new RuntimeException(__('Release not found.'));
        }
        return $r;
    }

    /** Existing customer ids from input. */
    private static function cleanHotels(mixed $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []), static fn ($v) => $v > 0)));
        if (!$ids) {
            return [];
        }
        [$in, $p] = DB::in($ids, 'ch');
        return array_map('intval', DB::column("SELECT id FROM hotels WHERE id IN $in ORDER BY id", $p));
    }

    private static function saveHotels(int $releaseId, array $hotelIds): void
    {
        DB::query('DELETE FROM apk_release_hotels WHERE release_id = :r', ['r' => $releaseId]);
        foreach ($hotelIds as $h) {
            DB::query('INSERT INTO apk_release_hotels (release_id, hotel_id) VALUES (:r, :h)', ['r' => $releaseId, 'h' => $h]);
        }
    }
}
