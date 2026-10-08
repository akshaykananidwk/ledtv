<?php
declare(strict_types=1);

/**
 * Sound library for bell / chime schedules (device schedules, 2.4), emergency alarms and message sounds
 * (2.4.1): built-in sounds synthesized for this product (assets/sounds/*.wav, no third-party audio,
 * generator: tools/sounds/make_alarm_sounds.php) plus the hotel's own uploads (table `sounds`,
 * mp3 / wav / ogg ≤ 5 MB, validated by Uploader kind 'audio').
 *
 * A sound is referenced by a string: "b:school_bell" (built-in) or "u:12" (uploaded row id).
 */
final class Sounds
{
    public const BUILTIN = [
        'school_bell' => 'School bell',
        'temple_bell' => 'Temple bell',
        'soft_chime' => 'Soft chime',
        // 2.4.1: emergency alarms (loopable without a click) and the message / notice chime.
        'emergency_beep' => 'Emergency beep',
        'emergency_siren' => 'Emergency siren',
        'fire_alarm' => 'Fire alarm',
        'notice_chime' => 'Notice chime',
    ];
    /** Alarm sound of a new emergency (admin form default, AJAX API and chain emergencies). */
    public const DEFAULT_ALARM = 'b:emergency_beep';
    /** Built-in alarms offered first in the emergency form. */
    public const ALARMS = ['b:emergency_beep', 'b:emergency_siren', 'b:fire_alarm'];
    /** Built-in sound offered for messages and the notice board. */
    public const NOTICE_CHIME = 'b:notice_chime';

    /** Built-in sounds: [ref => ['ref', 'name', 'url', 'builtin' => true]]. */
    public static function builtins(): array
    {
        $out = [];
        foreach (self::BUILTIN as $key => $label) {
            $out['b:' . $key] = ['ref' => 'b:' . $key, 'id' => 0, 'name' => __($label), 'url' => self::absolute(base_url('assets/sounds/' . $key . '.wav')), 'builtin' => true, 'size' => (int) @filesize(HC_ROOT . '/assets/sounds/' . $key . '.wav')];
        }
        return $out;
    }

    /** Uploaded sounds of the current hotel (newest first). */
    public static function uploaded(): array
    {
        return DB::all('SELECT * FROM sounds WHERE hotel_id = :h ORDER BY name, id', ['h' => Tenant::id()]);
    }

    /** Every sound usable in a schedule: [ref => ['ref', 'id', 'name', 'url', 'builtin', 'size']]. */
    public static function all(): array
    {
        $out = self::builtins();
        foreach (self::uploaded() as $r) {
            $out['u:' . $r['id']] = ['ref' => 'u:' . $r['id'], 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'url' => self::absolute((string) media_url((string) $r['file_path'])), 'builtin' => false, 'size' => (int) $r['size_bytes']];
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        return Tenant::find('sounds', $id);
    }

    /** Sound for a reference of the current hotel, or null (unknown / deleted). Another hotel's id → 404. */
    public static function resolve(string $ref): ?array
    {
        if (preg_match('/^b:([a-z_]+)$/', $ref, $m)) {
            return self::builtins()[$ref] ?? null;
        }
        if (preg_match('/^u:(\d{1,10})$/', $ref, $m)) {
            $row = self::find((int) $m[1]);
            return $row ? ['ref' => $ref, 'id' => (int) $row['id'], 'name' => (string) $row['name'], 'url' => self::absolute((string) media_url((string) $row['file_path'])), 'builtin' => false, 'size' => (int) $row['size_bytes']] : null;
        }
        return null;
    }

    /** TVs need a full URL (http(s)://host/…); base_url() is absolute except for a relative CDN setting. */
    public static function absolute(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return rtrim(base_url(), '/') . '/' . ltrim($url, '/');
    }

    /**
     * Save an uploaded file ($_FILES entry) as a new sound. Returns the new id.
     * @throws RuntimeException with a user-friendly message
     */
    public static function upload(array $file, string $name, ?int $userId = null): int
    {
        $name = mb_substr(trim(strip_tags($name)), 0, 120);
        if ($name === '') {
            $name = mb_substr(trim(pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME)), 0, 120) ?: __('Sound');
        }
        if ((int) ($file['size'] ?? 0) > Uploader::AUDIO_MAX_MB * 1024 * 1024) {
            throw new RuntimeException(__('File too large. Maximum is :m MB.', ['m' => Uploader::AUDIO_MAX_MB]));
        }
        $up = Uploader::handle($file, 'audio');
        return DB::insert('sounds', [
            'name' => $name,
            'file_path' => $up['path'],
            'mime' => $up['mime'],
            'size_bytes' => $up['size'],
            'created_by' => $userId,
            'created_at' => now(),
        ]);
    }

    /** Number of schedules (and active emergency alarms, 2.4.1) of the current hotel that play this uploaded sound. */
    public static function usage(int $id): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM device_schedules WHERE hotel_id = :h AND action = 'bell' AND options LIKE :p",
            ['h' => Tenant::id(), 'p' => '%"sound":"u:' . $id . '"%']
        ) + (int) DB::value(
            "SELECT COUNT(*) FROM broadcast_commands WHERE hotel_id = :h AND is_emergency = 1 AND status = 'active' AND alarm_sound = :s",
            ['h' => Tenant::id(), 's' => 'u:' . $id]
        );
    }

    /**
     * Validated sound of the hotel's library for a TV payload (emergency alarm, message sound): the same
     * rule as PLAY_SOUND after the 2.4 security review (DeviceFeatures::playSoundPayload) — a built-in sound
     * or one of the current hotel's uploads, nothing else. Another hotel's id → 404 (Tenant::deny).
     * Returns ['ref', 'name', 'url']. Throws InvalidArgumentException.
     */
    public static function libraryRef(string $ref): array
    {
        $ref = trim($ref);
        $s = preg_match('/^(b:[a-z_]{1,30}|u:\d{1,10})$/', $ref) ? self::resolve($ref) : null;
        if (!$s) {
            throw new InvalidArgumentException(__('Choose a sound from the sound library (Device schedules → Sounds).'));
        }
        $url = DeviceFeatures::playSoundPayload(['sound' => $ref])['url'];
        return ['ref' => $ref, 'name' => (string) $s['name'], 'url' => $url];
    }

    /** Choices for a sound picker: [ref => name], built-ins listed first in the given order. */
    public static function choices(array $first = []): array
    {
        $all = self::all();
        $out = [];
        foreach ($first as $ref) {
            if (isset($all[$ref])) {
                $out[$ref] = $all[$ref]['name'];
            }
        }
        foreach ($all as $ref => $s) {
            $out[$ref] ??= $s['name'];
        }
        return $out;
    }

    /** Delete an uploaded sound (file + row). False when a schedule still uses it. */
    public static function delete(int $id): bool
    {
        $row = self::find($id);
        if (!$row) {
            return true;
        }
        if (self::usage($id) > 0) {
            return false;
        }
        DB::delete('sounds', 'id = :id', ['id' => $id]);
        Uploader::delete((string) $row['file_path']);
        return true;
    }
}
