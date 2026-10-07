<?php
declare(strict_types=1);

/**
 * Slide designer (#11) and PDF → slides import (#12).
 *
 * Both run in the browser (fabric.js / pdf.js, assets/vendor) because shared hosting cannot render
 * designs or convert PDFs. The browser uploads finished images; this class validates them with the
 * normal Uploader rules (real MIME, re-encode, resize to 1920 px, thumbnail) and stores them as
 * ordinary `image` content items, so TVs need nothing new.
 *
 *  - Designs: the fabric.js JSON lives in `designs` (kind = design, one row per content item) so the
 *    slide can be opened and edited again; re-saving replaces the item's file in place.
 *  - Hotel templates: `designs` rows with kind = template (no content item).
 *  - Starter templates: templates/designer/*.json (read-only, shipped with the app).
 *  - PDF import: `pdf_imports` (batch id from the browser) + `pdf_import_pages` (page → item), so a
 *    retried page upload returns the existing item instead of creating a duplicate.
 *
 * Errors are RuntimeException with the HTTP status as code (413 too large, 422 invalid, 404 not found).
 */
final class Designer
{
    public const WIDTH = 1920;
    public const HEIGHT = 1080;
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;   // exported PNG / PDF page image
    public const MAX_JSON_BYTES = 1024 * 1024;        // design JSON
    public const MAX_PDF_PAGES = 100;
    public const MAX_TEMPLATES = 100;                  // hotel templates
    private const MAX_DIMENSION = 4096;

    private static ?array $starters = null;

    /** Folder with the starter template files (overridable in tests). */
    public static string $starterDir = '';

    // ------------------------------------------------------------------ starter templates

    /**
     * Starter templates: id => [id, name, category, design] from templates/designer/*.json
     * (file order). Invalid files are skipped.
     */
    public static function starters(): array
    {
        if (self::$starters !== null) {
            return self::$starters;
        }
        $dir = self::$starterDir !== '' ? self::$starterDir : HC_ROOT . '/templates/designer';
        $files = glob($dir . '/*.json') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $t = json_decode((string) file_get_contents($f), true);
            if (is_array($t) && preg_match('/^[a-z0-9_]{2,40}$/', (string) ($t['id'] ?? '')) && is_array($t['design']['objects'] ?? null)) {
                $out[$t['id']] = ['id' => $t['id'], 'name' => (string) ($t['name'] ?? $t['id']), 'category' => (string) ($t['category'] ?? ''), 'design' => $t['design']];
            }
        }
        return self::$starters = $out;
    }

    public static function reset(): void
    {
        self::$starters = null;
    }

    // ------------------------------------------------------------------ validation

    /**
     * Validate a design JSON string (≤ 1 MB, an object with an "objects" list).
     * Returns the canonical JSON to store.
     */
    public static function cleanJson(string $json): string
    {
        if ($json === '') {
            throw new RuntimeException(__('The design is missing.'), 422);
        }
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new RuntimeException(__('The design is too large (maximum :m MB). Use fewer or smaller pictures.', ['m' => 1]), 413);
        }
        $data = json_decode($json, true, 64);
        if (!is_array($data) || !is_array($data['objects'] ?? null) || array_is_list($data)) {
            throw new RuntimeException(__('The design data is not valid.'), 422);
        }
        // Only canvas content is kept; anything else (width, viewport …) is dropped.
        $keep = array_intersect_key($data, array_flip(['version', 'objects', 'background', 'backgroundImage', 'hc']));
        return json_out($keep);
    }

    /**
     * Check an uploaded slide / page image before the Uploader: present, ≤ 8 MB, a real PNG (or JPEG
     * when allowed) of sane dimensions. Returns the $_FILES entry with a matching file name.
     *
     * @param list<string> $mimes
     */
    public static function checkImage(mixed $file, array $mimes = ['image/png']): array
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size']) || is_array($file['error'])) {
            throw new RuntimeException(__('No image was received.'), 422);
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || (int) $file['size'] > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException(__('The image is too large. Maximum is :m MB.', ['m' => self::MAX_IMAGE_BYTES / 1024 / 1024]), 413);
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_file((string) $file['tmp_name'])) {
            throw new RuntimeException(__('No image was received.'), 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: '';
        $size = @getimagesize((string) $file['tmp_name']);
        if (!in_array($mime, $mimes, true) || $size === false || !in_array($size['mime'] ?? '', $mimes, true)) {
            throw new RuntimeException(in_array('image/jpeg', $mimes, true) ? __('Only PNG or JPG images are accepted.') : __('Only PNG images are accepted.'), 422);
        }
        if ($size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION) {
            throw new RuntimeException(__('The image size is not valid.'), 422);
        }
        $file['name'] = 'slide.' . ($mime === 'image/png' ? 'png' : 'jpg');
        return $file;
    }

    public static function cleanTitle(mixed $title, string $fallback = ''): string
    {
        $t = is_string($title) ? trim(preg_replace('/\s+/u', ' ', $title) ?? '') : '';
        $t = $t === '' ? $fallback : $t;
        if ($t === '') {
            throw new RuntimeException(__('Title is required (max 190 characters).'), 422);
        }
        return mb_substr($t, 0, 190);
    }

    // ------------------------------------------------------------------ designs

    /** Design row of a content item of the current hotel (null when the item has no design). */
    public static function designFor(int $contentId): ?array
    {
        return DB::one("SELECT * FROM designs WHERE hotel_id = :h AND content_id = :c AND kind = 'design'", ['h' => Tenant::id(), 'c' => $contentId]);
    }

    /**
     * Create (id 0) or update an image content item from an exported PNG + its design JSON.
     * Updating replaces the item's file and keeps its id, playlists and room assignments.
     *
     * @return array{id:int, created:bool, url:?string, thumb:?string}
     */
    public static function save(int $id, string $title, int $duration, mixed $file, string $json, ?bool $active = null): array
    {
        $json = self::cleanJson($json);
        $title = self::cleanTitle($title);
        $existing = null;
        if ($id > 0) {
            $existing = ContentManager::find($id); // other hotel's id → 404 (Tenant::deny)
            if (!$existing) {
                throw new RuntimeException(__('Content not found.'), 404);
            }
            if ($existing['type'] !== 'image') {
                throw new RuntimeException(__('Only designer slides can be saved here.'), 422);
            }
        }
        $file = self::checkImage($file);
        $up = Uploader::handle($file, 'image');
        $row = [
            'title' => $title,
            'duration' => max(0, min(86400, $duration)),
            'file_path' => $up['path'], 'thumb_path' => $up['thumb'], 'mime_type' => $up['mime'], 'file_size' => $up['size'], 'url' => null,
        ];
        if ($active !== null) {
            $row['is_active'] = $active ? 1 : 0;
        }
        try {
            DB::transaction(function () use (&$id, $existing, $row, $json, $title) {
                if ($existing) {
                    DB::update('content_items', $row, 'id = :id', ['id' => $id]);
                } else {
                    $id = DB::insert('content_items', $row + ['type' => 'image', 'settings' => json_out(['designer' => true]), 'created_by' => Auth::id(), 'created_at' => now()]);
                }
                if (self::designFor($id)) {
                    DB::update('designs', ['data' => $json, 'name' => $title], "content_id = :c AND kind = 'design'", ['c' => $id]);
                } else {
                    DB::insert('designs', ['content_id' => $id, 'kind' => 'design', 'name' => $title, 'data' => $json, 'created_by' => Auth::id(), 'created_at' => now()]);
                }
            });
        } catch (Throwable $e) {
            Uploader::delete($up['path'], $up['thumb']);
            throw $e;
        }
        if ($existing) {
            Uploader::delete($existing['file_path'], $existing['thumb_path']);
        }
        Settings::bumpContentVersion();
        ActivityLog::add($existing ? 'content_update' : 'content_create', 'content', $id, 'designer: ' . $title);
        return ['id' => $id, 'created' => !$existing, 'url' => media_url($up['path']), 'thumb' => media_url($up['thumb'])];
    }

    // ------------------------------------------------------------------ hotel templates

    /** Hotel's own templates (newest first): id, name, data (decoded), updated_at. */
    public static function templates(): array
    {
        $rows = DB::all("SELECT id, name, data, updated_at FROM designs WHERE hotel_id = :h AND kind = 'template' ORDER BY updated_at DESC, id DESC LIMIT " . self::MAX_TEMPLATES, ['h' => Tenant::id()]);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['data'] = json_decode((string) $r['data'], true) ?: ['objects' => []];
        }
        return $rows;
    }

    public static function findTemplate(int $id): ?array
    {
        $row = $id > 0 ? Tenant::find('designs', $id) : null;
        return $row && $row['kind'] === 'template' ? $row : null;
    }

    /** Save the current design as a hotel template (a template with the same name is replaced). */
    public static function saveTemplate(string $name, string $json): int
    {
        $name = self::cleanTitle($name);
        $json = self::cleanJson($json);
        $same = (int) DB::value("SELECT id FROM designs WHERE hotel_id = :h AND kind = 'template' AND name = :n LIMIT 1", ['h' => Tenant::id(), 'n' => $name]);
        if ($same) {
            DB::update('designs', ['data' => $json], 'id = :id', ['id' => $same]);
            return $same;
        }
        if ((int) DB::value("SELECT COUNT(*) FROM designs WHERE hotel_id = :h AND kind = 'template'", ['h' => Tenant::id()]) >= self::MAX_TEMPLATES) {
            throw new RuntimeException(__('You can keep at most :n templates. Delete one first.', ['n' => self::MAX_TEMPLATES]), 422);
        }
        $id = DB::insert('designs', ['kind' => 'template', 'name' => $name, 'data' => $json, 'created_by' => Auth::id(), 'created_at' => now()]);
        ActivityLog::add('design_template_save', 'design', $id, $name);
        return $id;
    }

    public static function deleteTemplate(int $id): void
    {
        $t = self::findTemplate($id);
        if (!$t) {
            throw new RuntimeException(__('Template not found.'), 404);
        }
        DB::delete('designs', 'id = :id', ['id' => $id]);
        ActivityLog::add('design_template_delete', 'design', $id, (string) $t['name']);
    }

    // ------------------------------------------------------------------ PDF import

    public static function cleanBatch(mixed $batch): string
    {
        if (!is_string($batch) || !preg_match('/^[a-f0-9]{32}$/', $batch)) {
            throw new RuntimeException(__('Invalid upload batch.'), 422);
        }
        return $batch;
    }

    /** Import row of a batch (created on first use). */
    private static function import(string $batch, string $fileName): array
    {
        DB::query(
            'INSERT IGNORE INTO pdf_imports (hotel_id, batch_id, file_name, created_by, created_at) VALUES (:h, :b, :f, :u, :t)',
            ['h' => Tenant::id(), 'b' => $batch, 'f' => mb_substr($fileName, 0, 190), 'u' => Auth::id(), 't' => now()]
        );
        return (array) DB::one('SELECT * FROM pdf_imports WHERE hotel_id = :h AND batch_id = :b', ['h' => Tenant::id(), 'b' => $batch]);
    }

    /** "brochure.pdf" → "brochure" (clean, max 150 characters). */
    public static function baseName(mixed $name): string
    {
        $n = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name) ?? '') : '';
        $n = (string) preg_replace('/\.(pdf|pptx?|key|odp)$/i', '', $n);
        $n = mb_substr($n, 0, 150);
        return $n !== '' ? $n : 'PDF';
    }

    /**
     * Store one rendered PDF page as an image item "<file> – page N". Idempotent per (batch, page):
     * a retry returns the item created the first time.
     *
     * @return array{id:int, created:bool, title:string}
     */
    public static function pdfPage(mixed $batch, mixed $fileName, int $page, int $duration, mixed $file): array
    {
        $batch = self::cleanBatch($batch);
        if ($page < 1 || $page > self::MAX_PDF_PAGES) {
            throw new RuntimeException(__('A PDF can have at most :n pages.', ['n' => self::MAX_PDF_PAGES]), 422);
        }
        $base = self::baseName($fileName);
        $import = self::import($batch, $base);
        $importId = (int) $import['id'];
        $title = mb_substr(__(':f – page :n', ['f' => $base, 'n' => $page]), 0, 190);

        $existing = self::pageItem($importId, $page);
        if ($existing) {
            return ['id' => $existing, 'created' => false, 'title' => $title];
        }
        $file = self::checkImage($file, ['image/png', 'image/jpeg']);
        $up = Uploader::handle($file, 'image');
        $id = DB::insert('content_items', [
            'title' => $title, 'type' => 'image', 'duration' => max(0, min(86400, $duration)), 'settings' => json_out(['pdf_page' => $page]),
            'file_path' => $up['path'], 'thumb_path' => $up['thumb'], 'mime_type' => $up['mime'], 'file_size' => $up['size'],
            'created_by' => Auth::id(), 'created_at' => now(),
        ]);
        $ins = DB::query(
            'INSERT IGNORE INTO pdf_import_pages (hotel_id, import_id, page, content_id, created_at) VALUES (:h, :i, :p, :c, :t)',
            ['h' => Tenant::id(), 'i' => $importId, 'p' => $page, 'c' => $id, 't' => now()]
        );
        if ($ins->rowCount() === 0) {
            // A parallel retry of the same page won the race: keep its item, drop ours.
            DB::delete('content_items', 'id = :id', ['id' => $id]);
            Uploader::delete($up['path'], $up['thumb']);
            return ['id' => (int) self::pageItem($importId, $page), 'created' => false, 'title' => $title];
        }
        Settings::bumpContentVersion();
        if ($page === 1) {
            ActivityLog::add('content_create', 'content', $id, 'PDF import: ' . $base);
        }
        return ['id' => $id, 'created' => true, 'title' => $title];
    }

    private static function pageItem(int $importId, int $page): int
    {
        return (int) DB::value(
            'SELECT p.content_id FROM pdf_import_pages p JOIN content_items c ON c.id = p.content_id AND c.hotel_id = :h2 WHERE p.hotel_id = :h AND p.import_id = :i AND p.page = :p',
            ['h' => Tenant::id(), 'h2' => Tenant::id(), 'i' => $importId, 'p' => $page]
        );
    }

    /**
     * Playlist of all uploaded pages of a batch in page order (created once; a retry returns it).
     *
     * @return array{id:int, created:bool, items:int}
     */
    public static function pdfPlaylist(mixed $batch, string $name, ?int $duration, string $transition = 'fade'): array
    {
        $batch = self::cleanBatch($batch);
        $import = DB::one('SELECT * FROM pdf_imports WHERE hotel_id = :h AND batch_id = :b', ['h' => Tenant::id(), 'b' => $batch]);
        if (!$import) {
            throw new RuntimeException(__('Upload the pages first.'), 404);
        }
        if ($import['playlist_id'] && ContentManager::findOwnPlaylist((int) $import['playlist_id'])) {
            $n = (int) DB::value('SELECT COUNT(*) FROM playlist_items WHERE playlist_id = :p', ['p' => $import['playlist_id']]);
            return ['id' => (int) $import['playlist_id'], 'created' => false, 'items' => $n];
        }
        $pages = DB::column(
            'SELECT p.content_id FROM pdf_import_pages p JOIN content_items c ON c.id = p.content_id AND c.hotel_id = :h2 WHERE p.hotel_id = :h AND p.import_id = :i ORDER BY p.page',
            ['h' => Tenant::id(), 'h2' => Tenant::id(), 'i' => $import['id']]
        );
        if (!$pages) {
            throw new RuntimeException(__('Upload the pages first.'), 422);
        }
        $name = self::cleanTitle($name, (string) $import['file_name']);
        $transition = in_array($transition, ['none', 'fade', 'slide'], true) ? $transition : 'fade';
        $pid = DB::transaction(function () use ($name, $transition, $pages, $duration, $import) {
            $pid = DB::insert('content_playlists', ['name' => $name, 'description' => mb_substr(__('Imported from :f', ['f' => $import['file_name']]), 0, 500), 'transition' => $transition, 'created_by' => Auth::id(), 'created_at' => now()]);
            foreach (array_values($pages) as $i => $cid) {
                DB::insert('playlist_items', ['playlist_id' => $pid, 'content_id' => (int) $cid, 'sort_order' => $i, 'duration' => $duration !== null ? max(1, min(86400, $duration)) : null]);
            }
            DB::update('pdf_imports', ['playlist_id' => $pid], 'id = :id', ['id' => $import['id']]);
            return $pid;
        });
        Settings::bumpContentVersion();
        ActivityLog::add('playlist_create', 'playlist', (int) $pid, $name . ' (PDF, ' . count($pages) . ' items)');
        return ['id' => (int) $pid, 'created' => true, 'items' => count($pages)];
    }

    // ------------------------------------------------------------------ page data

    /** Same-origin URL of an upload for the editor canvas (a CDN URL would taint the export). */
    public static function localUrl(?string $relPath): ?string
    {
        if ($relPath === null || $relPath === '' || str_contains($relPath, '..')) {
            return null;
        }
        return '../uploads/' . ltrim($relPath, '/');
    }

    /** The hotel's uploaded images for the picker (newest first). */
    public static function libraryImages(int $limit = 300): array
    {
        $rows = DB::all(
            "SELECT id, title, file_path, thumb_path FROM content_items WHERE hotel_id = :h AND type = 'image' AND file_path IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT " . max(1, $limit),
            ['h' => Tenant::id()]
        );
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'title' => (string) $r['title'],
            'url' => self::localUrl($r['file_path']), 'thumb' => self::localUrl($r['thumb_path'] ?: $r['file_path']),
        ], $rows);
    }

    /** Unicode ranges of the font subsets (from @fontsource). */
    private const RANGES = [
        'gujarati' => 'U+0951-0952,U+0964-0965,U+0A80-0AFF,U+200C-200D,U+20B9,U+25CC,U+A830-A839',
        'devanagari' => 'U+0900-097F,U+1CD0-1CF9,U+200C-200D,U+20A8,U+20B9,U+20F0,U+25CC,U+A830-A839,U+A8E0-A8FF,U+11B00-11B09',
        'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
        'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
    ];
    private const WEIGHTS = ['thin' => 100, 'extralight' => 200, 'light' => 300, 'regular' => 400, 'medium' => 500, 'semibold' => 600, 'bold' => 700, 'extrabold' => 800, 'black' => 900];

    /**
     * Web fonts found under assets/fonts (any sub-folder): @fontsource names
     * ("noto-sans-gujarati-gujarati-700-normal.woff2") or Google names ("NotoSansGujarati-Bold.woff2").
     *
     * @return list<array{family:string, weight:int, url:string, range:?string}>
     */
    public static function fontFaces(): array
    {
        $root = HC_ROOT . '/assets/fonts';
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $name = $f->getFilename();
            if (!str_ends_with(strtolower($name), '.woff2')) {
                continue;
            }
            $rel = 'fonts/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if (preg_match('/^([a-z0-9-]+?)-(gujarati|devanagari|latin-ext|latin)-(\d{3})-normal\.woff2$/', $name, $m)) {
                $family = ucwords(str_replace('-', ' ', $m[1]));
                $out[] = ['family' => $family, 'weight' => (int) $m[3], 'url' => $rel, 'range' => self::RANGES[$m[2]]];
            } elseif (preg_match('/^([A-Za-z0-9]+)-([A-Za-z]+)\.woff2$/', $name, $m) && isset(self::WEIGHTS[strtolower($m[2])])) {
                $family = trim((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $m[1]));
                $out[] = ['family' => $family, 'weight' => self::WEIGHTS[strtolower($m[2])], 'url' => $rel, 'range' => null];
            }
        }
        usort($out, static fn ($a, $b) => [$a['family'], $a['weight'], $a['url']] <=> [$b['family'], $b['weight'], $b['url']]);
        return $out;
    }

    /** @font-face rules for fontFaces() (URLs from asset()). */
    public static function fontCss(array $faces): string
    {
        $css = '';
        foreach ($faces as $f) {
            $css .= '@font-face{font-family:"' . str_replace(['"', '\\', '<'], '', $f['family']) . '";font-style:normal;font-display:swap;font-weight:' . (int) $f['weight']
                . ';src:url("' . str_replace(['"', '\\', '<', ')'], '', asset($f['url'])) . '") format("woff2")'
                . ($f['range'] ? ';unicode-range:' . $f['range'] : '') . '}';
        }
        return $css;
    }

    /**
     * Font choices for the text tool: label => CSS font stack. Every stack ends with the Indic fonts so
     * Gujarati / Hindi text renders whatever font is chosen.
     */
    public static function fontOptions(array $faces): array
    {
        $indic = '"Noto Sans Gujarati", "Hind Vadodara", "Noto Sans Devanagari", "Mukta", "Nirmala UI", "Shruti", "Mangal"';
        $families = array_values(array_unique(array_column($faces, 'family')));
        $opts = [];
        foreach ($families as $fam) {
            $opts[$fam] = '"' . $fam . '", ' . $indic . ', sans-serif';
        }
        $opts += [
            'Sans (Arial)' => 'Arial, ' . $indic . ', sans-serif',
            'Serif (Georgia)' => 'Georgia, "Times New Roman", ' . $indic . ', serif',
            'Bold display (Impact)' => 'Impact, "Arial Black", ' . $indic . ', sans-serif',
            'Monospace' => '"Courier New", monospace',
        ];
        return $opts;
    }
}
