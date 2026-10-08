<?php
declare(strict_types=1);

/**
 * Sound library for bell / chime schedules (device schedules, 2.4): three built-in chimes synthesized for
 * this product (assets/sounds/*.wav, no third-party audio) plus the hotel's own uploads (table `sounds`,
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
    ];

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

    /** Number of schedules of the current hotel that play this uploaded sound. */
    public static function usage(int $id): int
    {
        return (int) DB::value(
            "SELECT COUNT(*) FROM device_schedules WHERE hotel_id = :h AND action = 'bell' AND options LIKE :p",
            ['h' => Tenant::id(), 'p' => '%"sound":"u:' . $id . '"%']
        );
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
