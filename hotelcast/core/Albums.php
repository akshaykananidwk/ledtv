<?php
declare(strict_types=1);

/**
 * Photo albums (#19, display app "photo_album"): tenant tables `albums` + `album_photos`
 * (migrations/018_content_apps.sql), managed on the mobile-first page admin/album_upload.php
 * (permission albums.manage = staff+), shown by core/Apps/PhotoAlbumApp.php.
 *
 * Guest upload link (optional, per album): <base>/album/?a=<album id>&s=<sig>, sig = first 32 hex
 * characters of HMAC-SHA256("album:<hotel_id>:<album_id>:<guest_key>", APP_KEY). "New link" changes
 * guest_key and so invalidates printed QR codes. Guest photos are 'pending' until approved when
 * moderation is on; at most guest_max guest photos per album; RATE_* limits per IP.
 */
final class Albums
{
    public const MAX_PHOTOS = 1000;          // per album
    public const GUEST_MAX_LIMIT = 1000;
    public const RATE_HITS = 20;             // guest uploads per IP and album …
    public const RATE_WINDOW = 600;          // … per 10 minutes
    public const HEIC_EXT = ['heic', 'heif'];

    public static function find(int $id): ?array
    {
        return Tenant::find('albums', $id);
    }

    /** Albums of the hotel with photo counts (approved / pending). */
    public static function all(): array
    {
        return DB::all(
            "SELECT a.*, (SELECT COUNT(*) FROM album_photos p WHERE p.album_id = a.id AND p.hotel_id = a.hotel_id AND p.status = 'approved') AS photos,
                    (SELECT COUNT(*) FROM album_photos p WHERE p.album_id = a.id AND p.hotel_id = a.hotel_id AND p.status = 'pending') AS pending
             FROM albums a WHERE a.hotel_id = :h ORDER BY a.name, a.id",
            ['h' => Tenant::id()]
        );
    }

    /** id => name for selects. */
    public static function options(): array
    {
        $out = [];
        foreach (DB::all('SELECT id, name FROM albums WHERE hotel_id = :h ORDER BY name, id', ['h' => Tenant::id()]) as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }

    /** Validate album form input → [row, errors]. */
    public static function validate(array $in): array
    {
        $errors = [];
        $name = trim(preg_replace('/\s+/', ' ', is_string($in['name'] ?? null) ? $in['name'] : '') ?? '');
        if ($name === '') {
            $errors[] = __('The album name is required.');
        }
        $max = is_numeric($in['guest_max'] ?? null) ? max(1, min(self::GUEST_MAX_LIMIT, (int) $in['guest_max'])) : 100;
        return [[
            'name' => mb_substr($name, 0, 120),
            'guest_upload' => !empty($in['guest_upload']) ? 1 : 0,
            'guest_moderation' => !empty($in['guest_moderation']) ? 1 : 0,
            'guest_max' => $max,
        ], $errors];
    }

    public static function create(array $data): int
    {
        return DB::insert('albums', $data + ['guest_key' => random_token(8), 'created_by' => Auth::id(), 'created_at' => now()]);
    }

    public static function update(int $id, array $data): void
    {
        DB::update('albums', $data, 'id = :id', ['id' => $id]);
    }

    /** Delete an album, its photos and their files. */
    public static function delete(int $id): void
    {
        if (!self::find($id)) {
            return;
        }
        foreach (DB::all('SELECT image_path, thumb_path FROM album_photos WHERE hotel_id = :h AND album_id = :a', ['h' => Tenant::id(), 'a' => $id]) as $p) {
            Uploader::delete($p['image_path'], $p['thumb_path']);
        }
        DB::delete('album_photos', 'album_id = :a', ['a' => $id]);
        DB::delete('albums', 'id = :id', ['id' => $id]);
    }

    // ------------------------------------------------------------------ photos

    public static function photo(int $id): ?array
    {
        return Tenant::find('album_photos', $id);
    }

    /**
     * Photos of an album. $status: 'approved' (TV), 'pending' (moderation) or null (both).
     * Order: sort_order, then oldest first.
     */
    public static function photos(int $albumId, ?string $status = 'approved', int $limit = self::MAX_PHOTOS): array
    {
        $sql = 'SELECT * FROM album_photos WHERE hotel_id = :h AND album_id = :a';
        $p = ['h' => Tenant::id(), 'a' => $albumId];
        if ($status !== null) {
            $sql .= ' AND status = :s';
            $p['s'] = $status;
        }
        return DB::all($sql . ' ORDER BY sort_order, id LIMIT ' . max(1, min(self::MAX_PHOTOS, $limit)), $p);
    }

    public static function count(int $albumId, ?string $source = null): int
    {
        $sql = 'SELECT COUNT(*) FROM album_photos WHERE hotel_id = :h AND album_id = :a';
        $p = ['h' => Tenant::id(), 'a' => $albumId];
        if ($source !== null) {
            $sql .= ' AND source = :s';
            $p['s'] = $source;
        }
        return (int) DB::value($sql, $p);
    }

    /** True when the upload is an HEIC / HEIF photo (iPhone default) — GD cannot read it. */
    public static function isHeic(array $file): bool
    {
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (in_array($ext, self::HEIC_EXT, true)) {
            return true;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp !== '' && is_file($tmp)) {
            $head = (string) @file_get_contents($tmp, false, null, 0, 32);
            // ISO-BMFF "ftyp" box with an HEIF brand.
            if (substr($head, 4, 4) === 'ftyp' && preg_match('/^(heic|heix|hevc|hevx|heim|heis|mif1|msf1)$/', substr($head, 8, 4))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Store one uploaded photo ($_FILES entry) in an album. Resized / re-encoded by Uploader (image
     * rules: JPG / PNG / GIF / WEBP, max 25 MB, max width image_max_width), EXIF orientation applied.
     * @throws RuntimeException with a translated message
     */
    public static function addPhoto(int $albumId, array $file, array $meta = []): int
    {
        if (self::isHeic($file)) {
            throw new RuntimeException(__('HEIC photos (iPhone format) are not supported. On the iPhone choose Settings → Camera → Formats → Most Compatible, or share the photo as JPG.'));
        }
        if (self::count($albumId) >= self::MAX_PHOTOS) {
            throw new RuntimeException(__('This album is full (:n photos).', ['n' => self::MAX_PHOTOS]));
        }
        if (isset($file['tmp_name']) && is_string($file['tmp_name']) && ($file['error'] ?? 1) === UPLOAD_ERR_OK) {
            self::fixOrientation($file['tmp_name']);
        }
        $up = Uploader::handle($file, 'image');
        $sort = (int) DB::value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM album_photos WHERE hotel_id = :h AND album_id = :a', ['h' => Tenant::id(), 'a' => $albumId]);
        $caption = trim(preg_replace('/\s+/', ' ', (string) ($meta['caption'] ?? '')) ?? '');
        $guest = trim(preg_replace('/\s+/', ' ', (string) ($meta['guest_name'] ?? '')) ?? '');
        return DB::insert('album_photos', [
            'album_id' => $albumId,
            'image_path' => $up['path'],
            'thumb_path' => $up['thumb'],
            'caption' => $caption !== '' ? mb_substr($caption, 0, 190) : null,
            'status' => ($meta['status'] ?? 'approved') === 'pending' ? 'pending' : 'approved',
            'source' => ($meta['source'] ?? 'staff') === 'guest' ? 'guest' : 'staff',
            'guest_name' => $guest !== '' ? mb_substr($guest, 0, 80) : null,
            'uploader_ip' => ($meta['source'] ?? 'staff') === 'guest' ? mb_substr(client_ip(), 0, 45) : null,
            'sort_order' => $sort,
            'created_by' => ($meta['source'] ?? 'staff') === 'guest' ? null : Auth::id(),
            'created_at' => now(),
        ]);
    }

    /** Rotate a JPEG in place according to its EXIF orientation (phones store photos sideways). */
    private static function fixOrientation(string $path): void
    {
        if (!function_exists('exif_read_data') || !function_exists('imagecreatefromjpeg') || !is_file($path)) {
            return;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 3);
        if ($head !== "\xFF\xD8\xFF") {
            return;
        }
        $exif = @exif_read_data($path);
        $o = (int) ($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle === 0) {
            return;
        }
        $img = @imagecreatefromjpeg($path);
        if (!$img) {
            return;
        }
        $rot = imagerotate($img, $angle, 0);
        if ($rot) {
            imagejpeg($rot, $path, 92);
            imagedestroy($rot);
        }
        imagedestroy($img);
    }

    public static function updateCaption(int $photoId, string $caption): void
    {
        $caption = trim(preg_replace('/\s+/', ' ', $caption) ?? '');
        DB::update('album_photos', ['caption' => $caption !== '' ? mb_substr($caption, 0, 190) : null], 'id = :id', ['id' => $photoId]);
    }

    public static function approve(int $photoId): void
    {
        DB::update('album_photos', ['status' => 'approved'], 'id = :id', ['id' => $photoId]);
    }

    public static function deletePhoto(int $photoId): void
    {
        $p = self::photo($photoId);
        if (!$p) {
            return;
        }
        DB::delete('album_photos', 'id = :id', ['id' => $photoId]);
        Uploader::delete($p['image_path'], $p['thumb_path']);
    }

    /** New order of an album's photos (ids of this album only; others ignored). */
    public static function reorder(int $albumId, array $ids): void
    {
        $own = array_flip(array_map('intval', DB::column('SELECT id FROM album_photos WHERE hotel_id = :h AND album_id = :a', ['h' => Tenant::id(), 'a' => $albumId])));
        $n = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($own[$id])) {
                DB::update('album_photos', ['sort_order' => ++$n], 'id = :id', ['id' => $id]);
                unset($own[$id]);
            }
        }
    }

    public static function photoUrl(array $p, bool $thumb = false): ?string
    {
        $path = $thumb && !empty($p['thumb_path']) ? $p['thumb_path'] : $p['image_path'];
        return $path ? media_url((string) $path) : null;
    }

    // ------------------------------------------------------------------ guest upload link

    public static function guestSignature(int $hotelId, int $albumId, string $guestKey): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            throw new RuntimeException('APP_KEY missing from .env');
        }
        return substr(hash_hmac('sha256', 'album:' . $hotelId . ':' . $albumId . ':' . $guestKey, $key), 0, 32);
    }

    public static function guestUrl(array $album): string
    {
        $id = (int) $album['id'];
        return base_url('album/') . '?' . http_build_query(['a' => $id, 's' => self::guestSignature((int) $album['hotel_id'], $id, (string) $album['guest_key'])]);
    }

    /** New guest link (old QR codes stop working). */
    public static function rotateGuestKey(int $albumId): void
    {
        DB::update('albums', ['guest_key' => random_token(8)], 'id = :id', ['id' => $albumId]);
    }

    /**
     * Album for a guest request (?a=&s=) with the hotel context switched; null for a bad signature or a
     * missing album. Does NOT check guest_upload (the page shows "closed" for switched-off links).
     */
    public static function albumFromGuestRequest(array $q): ?array
    {
        $a = $q['a'] ?? '';
        $s = $q['s'] ?? '';
        if (!is_string($a) || !ctype_digit($a) || strlen($a) > 9 || (int) $a <= 0 || !is_string($s) || !preg_match('/^[0-9a-f]{32}$/', $s)) {
            return null;
        }
        $row = DB::one('SELECT * FROM albums WHERE id = :id', ['id' => (int) $a]);
        if (!$row || (string) $row['guest_key'] === '' || !hash_equals(self::guestSignature((int) $row['hotel_id'], (int) $row['id'], (string) $row['guest_key']), $s)) {
            return null;
        }
        Tenant::set((int) $row['hotel_id']);
        return $row;
    }

    /** Guest photos left on the link (0 = limit reached). */
    public static function guestRemaining(array $album): int
    {
        return max(0, (int) $album['guest_max'] - self::count((int) $album['id'], 'guest'));
    }
}
