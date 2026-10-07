<?php
declare(strict_types=1);
/**
 * Public guest upload page of a photo album (#19), e.g. for a wedding: guests scan the QR code and
 * add photos from their phone.   album/?a=<album id>&s=<signature>[&lang=en|gu|hi]
 * No login: the HMAC signature (Albums::guestSignature, includes the album's guest_key) is the key.
 * Upload = POST op=upload (multipart "photo", optional caption / guest_name) → JSON.
 * Checks: signature, hotel active, link switched on, RateLimiter per IP + album, guest_max, image
 * rules of Uploader (HEIC refused). With moderation on, photos stay 'pending' until approved.
 */
require __DIR__ . '/../core/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; font-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$json = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_out($payload);
    exit;
};
$fail = static fn (string $msg, int $status, string $code): never => $json(['ok' => false, 'error' => ['code' => $code, 'message' => $msg]], $status);

$album = Albums::albumFromGuestRequest($_GET);
$lang = is_string($_GET['lang'] ?? null) && isset(I18n::GUEST_LANGUAGES[$_GET['lang']]) ? $_GET['lang'] : null;
if ($album) {
    $lang ??= (string) Settings::get('default_language', 'en');
}
I18n::setLang($lang !== null && isset(I18n::GUEST_LANGUAGES[$lang]) ? $lang : 'en');

/** Small standalone page (no admin layout). */
$page = static function (string $title, string $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="' . e(I18n::lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="' . e(asset('vendor/bootstrap/css/bootstrap.min.css')) . '">'
        . '<link rel="stylesheet" href="' . e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) . '">'
        . '<style>body{background:#f6f1ea}.ga-wrap{max-width:560px;margin:0 auto;padding:16px}.ga-head{text-align:center;padding:18px 0 8px}.ga-head h1{font-size:1.5rem;font-weight:700}</style>'
        . '</head><body><div class="ga-wrap">' . $body . '</div>'
        . '<script src="' . e(asset('js/album_upload.js')) . '" defer></script></body></html>';
    exit;
};

if (!$album) {
    $isPost ? $fail('Not found', 404, 'NOT_FOUND') : $page(__('Not found'), '<div class="ga-head"><h1>' . e(__('This link is not valid.')) . '</h1></div>', 404);
}
if (!Tenant::isActive()) {
    $isPost ? $fail('Service paused', 403, 'SUSPENDED') : $page(__('Service paused'), '<div class="ga-head"><h1>' . e(Tenant::suspendedMessage()['title']) . '</h1></div>', 403);
}
if (!(int) $album['guest_upload']) {
    $msg = __('Photo upload for this album is closed.');
    $isPost ? $fail($msg, 403, 'CLOSED') : $page($msg, '<div class="ga-head"><h1><i class="bi bi-lock"></i></h1><p class="lead">' . e($msg) . '</p></div>', 403);
}

if ($isPost) {
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $fail(__('The photo is too large for the server.'), 413, 'TOO_LARGE');
    }
    if (($_POST['op'] ?? '') !== 'upload') {
        $fail('Unknown action', 400, 'BAD_REQUEST');
    }
    $wait = RateLimiter::hit('album_guest:' . (int) $album['id'] . ':' . client_ip(), Albums::RATE_HITS, Albums::RATE_WINDOW);
    if ($wait > 0) {
        header('Retry-After: ' . $wait);
        $fail(__('Too many photos at once. Please wait :m minutes and try again.', ['m' => (int) ceil($wait / 60)]), 429, 'RATE_LIMITED');
    }
    if (Albums::guestRemaining($album) <= 0) {
        $fail(__('This album has reached its maximum number of guest photos.'), 403, 'LIMIT');
    }
    $file = $_FILES['photo'] ?? null;
    $moderated = (bool) (int) $album['guest_moderation'];
    try {
        if (!is_array($file)) {
            throw new RuntimeException(__('No file was selected.'));
        }
        $id = Albums::addPhoto((int) $album['id'], $file, [
            'caption' => is_string($_POST['caption'] ?? null) ? $_POST['caption'] : '',
            'guest_name' => is_string($_POST['guest_name'] ?? null) ? $_POST['guest_name'] : '',
            'source' => 'guest',
            'status' => $moderated ? 'pending' : 'approved',
        ]);
    } catch (RuntimeException $e) {
        $fail($e->getMessage(), 422, 'UPLOAD');
    }
    Logger::write('app', 'info', 'Guest photo uploaded', ['album' => (int) $album['id'], 'photo' => $id, 'ip' => client_ip()]);
    $json(['ok' => true, 'data' => [
        'id' => $id,
        'status' => $moderated ? 'pending' : 'approved',
        'message' => $moderated ? __('Thank you! The photo appears after approval.') : __('Thank you! The photo appears on the TV soon.'),
    ]]);
}

// ---------------------------------------------------------------- upload form
$hotel = (string) Settings::get('hotel_name', '');
$self = Albums::guestUrl($album);
$langLinks = '';
foreach (I18n::GUEST_LANGUAGES as $code => $label) {
    $langLinks .= $code === I18n::lang() ? '<strong class="mx-1">' . e($label) . '</strong>' : '<a class="mx-1" href="' . e($self . '&lang=' . $code) . '">' . e($label) . '</a>';
}
$i18n = [
    'uploading' => __('Uploading…'), 'done' => __('Uploaded'), 'failed' => __('Failed'), 'waiting' => __('Waiting…'),
    'heic' => __('HEIC photos (iPhone format) are not supported. On the iPhone choose Settings → Camera → Formats → Most Compatible, or share the photo as JPG.'),
    'not_image' => __('Only JPG, PNG, GIF or WEBP images are allowed.'), 'summary' => __(':ok of :n photos uploaded.'),
    'network' => __('Network error. Check the connection and try again.'),
];
$body = '<div class="text-end small">' . $langLinks . '</div>'
    . '<div class="ga-head"><div class="text-muted">' . e($hotel) . '</div><h1>' . e($album['name']) . '</h1>'
    . '<p class="mb-0">' . e(__('Share your photos! They are shown on the TV screens.')) . '</p></div>'
    . '<div class="card"><div class="card-body" data-album-uploader data-url="' . e($self) . '" data-i18n="' . e(json_out($i18n)) . '">'
    . '<div class="mb-2"><input class="form-control" data-album-name maxlength="80" placeholder="' . e(__('Your name (optional)')) . '"></div>'
    . '<div class="mb-3"><input class="form-control" data-album-caption maxlength="190" placeholder="' . e(__('Caption (optional)')) . '"></div>'
    . '<div class="row g-2"><div class="col-6"><label class="btn btn-primary btn-lg w-100 py-3"><i class="bi bi-images"></i> ' . e(__('Choose photos'))
    . '<input type="file" class="d-none" data-album-files accept="image/jpeg,image/png,image/gif,image/webp,image/*" multiple></label></div>'
    . '<div class="col-6"><label class="btn btn-outline-primary btn-lg w-100 py-3"><i class="bi bi-camera"></i> ' . e(__('Take a photo'))
    . '<input type="file" class="d-none" data-album-files accept="image/*" capture="environment"></label></div></div>'
    . '<div class="form-text mt-2">' . e((int) $album['guest_moderation'] ? __('Photos appear on the TV after the hosts approve them.') : __('Photos appear on the TV within a minute.'))
    . ' ' . e(__('JPG, PNG or WEBP. HEIC (iPhone) photos are not supported.')) . '</div>'
    . '<div class="mt-3" data-album-list></div></div></div>';
$page((string) $album['name'], $body);
