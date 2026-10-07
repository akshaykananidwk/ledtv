<?php
/**
 * AJAX actions "designer_*" — slide designer (#11) and PDF → slides import (#12), core/Designer.php.
 * Multipart POSTs (FormData) with the X-CSRF-Token header; all need content.manage.
 *   designer_save         id, title, duration, design (JSON ≤ 1 MB), png (file ≤ 8 MB)
 *                         → {id, created, url, thumb, edit_url}  create / update the image item + design
 *   designer_image        file (JPG/PNG/GIF/WEBP) → {id, url, title}  upload a picture into the library
 *   designer_template     name, design → {id}            save as the hotel's template
 *   designer_tpldelete    id → {}                         delete a hotel template
 *   designer_pdfpage      batch, file_name, page, duration, image (PNG/JPG ≤ 8 MB) → {id, created, title}
 *                         idempotent per (batch, page)
 *   designer_pdfplaylist  batch, name, duration, transition → {id, created, items, edit_url}  (playlists.manage)
 */
declare(strict_types=1);

if (!str_starts_with($action, 'designer_')) {
    return;
}
$needPost();
if (post_too_large()) {
    ajax_error(__('The file is larger than the server upload limit (:s).', ['s' => human_bytes(upload_limit())]), 413, 'TOO_LARGE');
}
require_can('content.manage');

$dsStr = static fn (string $k, int $max = 1000): string => is_string($_POST[$k] ?? null) ? mb_substr(trim($_POST[$k]), 0, $max) : '';
$dsInt = static fn (string $k, int $default = 0): int => isset($_POST[$k]) && is_string($_POST[$k]) && preg_match('/^\d{1,6}$/', $_POST[$k]) ? (int) $_POST[$k] : $default;
// The design may arrive as a form field or as a file part (keeps large JSON out of max_input_vars limits).
$dsJson = static function (): string {
    if (isset($_FILES['design']['tmp_name']) && is_string($_FILES['design']['tmp_name']) && ($_FILES['design']['error'] ?? 1) === UPLOAD_ERR_OK) {
        if ((int) $_FILES['design']['size'] > Designer::MAX_JSON_BYTES) {
            throw new RuntimeException(__('The design is too large (maximum :m MB). Use fewer or smaller pictures.', ['m' => 1]), 413);
        }
        return (string) file_get_contents($_FILES['design']['tmp_name']);
    }
    return is_string($_POST['design'] ?? null) ? $_POST['design'] : '';
};

try {
    switch ($action) {
        case 'designer_save':
            $r = Designer::save($dsInt('id'), $dsStr('title', 300), $dsInt('duration', 10), $_FILES['png'] ?? null, $dsJson(),
                isset($_POST['is_active']) ? $_POST['is_active'] === '1' : null);
            ajax_ok($r + ['edit_url' => admin_url('designer.php', ['id' => $r['id']]), 'message' => __('":t" saved.', ['t' => $dsStr('title', 190)])]);

        case 'designer_image':
            $file = $_FILES['file'] ?? null;
            if (!is_array($file)) {
                throw new RuntimeException(__('No file was selected.'), 422);
            }
            $up = Uploader::handle($file, 'image');
            $title = mb_substr(trim((string) preg_replace('/\.[A-Za-z0-9]{2,4}$/', '', is_string($file['name'] ?? null) ? $file['name'] : '')), 0, 190) ?: __('Image');
            $id = DB::insert('content_items', [
                'title' => $title, 'type' => 'image', 'duration' => 10, 'settings' => '{}',
                'file_path' => $up['path'], 'thumb_path' => $up['thumb'], 'mime_type' => $up['mime'], 'file_size' => $up['size'],
                'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            ActivityLog::add('content_create', 'content', $id, 'image (designer upload): ' . $title);
            ajax_ok(['id' => $id, 'title' => $title, 'url' => Designer::localUrl($up['path']), 'thumb' => Designer::localUrl($up['thumb'] ?: $up['path'])]);

        case 'designer_template':
            $id = Designer::saveTemplate($dsStr('name', 300), $dsJson());
            ajax_ok(['id' => $id, 'message' => __('Template saved.')]);

        case 'designer_tpldelete':
            Designer::deleteTemplate($dsInt('id'));
            ajax_ok([]);

        case 'designer_pdfpage':
            $r = Designer::pdfPage($_POST['batch'] ?? null, $dsStr('file_name', 300), $dsInt('page'), $dsInt('duration', 10), $_FILES['image'] ?? null);
            ajax_ok($r);

        case 'designer_pdfplaylist':
            require_can('playlists.manage');
            $dur = $dsInt('duration', 0);
            $r = Designer::pdfPlaylist($_POST['batch'] ?? null, $dsStr('name', 300), $dur > 0 ? $dur : null, $dsStr('transition', 10));
            ajax_ok($r + ['edit_url' => admin_url('playlists.php', ['action' => 'edit', 'id' => $r['id']])]);
    }
} catch (RuntimeException $e) {
    if ($e instanceof PDOException) {
        throw $e; // logged as a server error by ajax.php
    }
    $code = $e->getCode();
    if (!in_array($code, [404, 413, 422], true)) {
        // Uploader errors (no code) are user-friendly validation messages.
        $code = $e instanceof TenantException ? 404 : 422;
    }
    ajax_error($e->getMessage(), $code, match ($code) { 404 => 'NOT_FOUND', 413 => 'TOO_LARGE', default => 'VALIDATION_ERROR' });
}
ajax_error('Unknown action', 404, 'UNKNOWN_ACTION');
