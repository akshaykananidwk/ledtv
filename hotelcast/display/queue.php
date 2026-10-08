<?php
declare(strict_types=1);
/**
 * Public self-service token page (QR code on the Token display, core/Queue.php):
 *   display/queue.php?h=<hotel>&q=<service>&s=<sig>            → "Get my token" (POST, rate-limited per IP)
 *   display/queue.php?h=&q=&s=&t=<token>&k=<token sig>          → my token, its status and people ahead
 * No login: s = HMAC("queue:<hotel>:<service>"), k = HMAC("queue-token:<hotel>:<token>") (APP_KEY).
 * Only services with self-service switched on; wrong signature / other hotel / closed → 404;
 * suspended hotel → 403; more than Queue::SELF_LIMIT tokens per IP in SELF_WINDOW → 429.
 */
require __DIR__ . '/../core/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

$digits = static fn (mixed $v): int => is_string($v) && ctype_digit($v) && strlen($v) <= 9 ? (int) $v : 0;

/** Small mobile page. */
$page = static function (string $title, string $body, int $refresh = 0): never {
    $langs = '';
    foreach (I18n::GUEST_LANGUAGES as $code => $name) {
        $q = $_GET;
        $q['lang'] = $code;
        $langs .= '<a href="?' . e(http_build_query($q)) . '"' . (I18n::lang() === $code ? ' class="on"' : '') . '>' . e($name) . '</a>';
    }
    echo '<!DOCTYPE html><html lang="' . e(I18n::lang()) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">' . ($refresh ? '<meta http-equiv="refresh" content="' . $refresh . '">' : '')
        . '<title>' . e($title) . '</title><style>'
        . 'body{margin:0;font-family:"Noto Sans","Noto Sans Gujarati","Noto Sans Devanagari",Arial,sans-serif;background:#0A2540;color:#fff;text-align:center}'
        . '.w{max-width:26rem;margin:0 auto;padding:1.5rem 1rem 2rem}.h{font-size:1.1rem;opacity:.8}.s{font-size:1.5rem;font-weight:700;margin:.3rem 0 1.2rem}'
        . '.n{font-size:5rem;font-weight:800;line-height:1;margin:.5rem 0;color:#FFD54F;font-variant-numeric:tabular-nums}.m{font-size:1.15rem;margin:.6rem 0}'
        . '.b{display:block;width:100%;padding:1.1rem;font-size:1.4rem;font-weight:700;border:0;border-radius:.8rem;background:#00B4D8;color:#00121f;margin-top:1rem}'
        . 'input{width:100%;box-sizing:border-box;padding:.8rem;font-size:1.1rem;border-radius:.6rem;border:0;margin-top:.6rem}'
        . '.st{display:inline-block;padding:.3rem 1rem;border-radius:2rem;background:#12467A;font-weight:700}.e{background:#7F1D1D;padding:1rem;border-radius:.8rem}'
        . '.l{margin-top:2rem;font-size:.9rem}.l a{color:#A9C4E2;margin:0 .5rem;text-decoration:none}.l a.on{color:#fff;font-weight:700}'
        . '</style></head><body><div class="w">' . $body . '<div class="l">' . $langs . '</div></div></body></html>';
    exit;
};
$notFound = static function () use ($page): never {
    http_response_code(404);
    $page(__('Not found'), '<p class="m e">' . e(__('This link is not valid or self-service tokens are switched off.')) . '</p>');
};

$hid = $digits($_GET['h'] ?? '');
$sid = $digits($_GET['q'] ?? '');
$lang = is_string($_GET['lang'] ?? null) && isset(I18n::GUEST_LANGUAGES[$_GET['lang']]) ? $_GET['lang'] : null;
if ($hid <= 0 || $sid <= 0 || !Queue::verify(Queue::serviceSignature($hid, $sid), $_GET['s'] ?? null) || !DB::value('SELECT id FROM hotels WHERE id = :id', ['id' => $hid])) {
    I18n::setLang($lang ?? 'en');
    $notFound();
}
Tenant::set($hid);
I18n::setLang($lang ?? (isset(I18n::GUEST_LANGUAGES[(string) Settings::get('default_language', 'en')]) ? (string) Settings::get('default_language', 'en') : 'en'));
if (!Tenant::isActive()) {
    http_response_code(403);
    $page(__('Service paused'), '<p class="m e">' . e(Tenant::suspendedMessage(I18n::lang())['title']) . '</p>');
}
$svc = Features::enabled('app_queue') ? DB::one('SELECT * FROM queue_services WHERE id = :id AND hotel_id = :h', ['id' => $sid, 'h' => $hid]) : null; // 2.5 plans
if (!$svc || !(int) $svc['is_active'] || !(int) $svc['self_service']) {
    $notFound();
}
$hotelName = (string) Settings::get('hotel_name', '');
$head = '<div class="h">' . e($hotelName) . '</div><div class="s">' . e($svc['name']) . '</div>';

// ---------------------------------------------------------------- take a token
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $wait = RateLimiter::hit('queue_self:' . $hid . ':' . client_ip(), Queue::SELF_LIMIT, Queue::SELF_WINDOW);
    if ($wait > 0) {
        http_response_code(429);
        header('Retry-After: ' . $wait);
        $page(__('Please wait'), $head . '<p class="m e">' . e(__('You have taken several tokens already. Please try again in :m minutes.', ['m' => (int) ceil($wait / 60)])) . '</p>');
    }
    try {
        $t = Queue::issue($sid, ['name' => is_string($_POST['name'] ?? null) ? $_POST['name'] : '', 'source' => 'self', 'ip' => client_ip()]);
    } catch (DomainException) {
        $notFound();
    }
    header('Location: ' . Queue::statusUrl($svc, $t) . ($lang ? '&lang=' . $lang : ''), true, 303);
    exit;
}

// ---------------------------------------------------------------- my token
if (isset($_GET['t'])) {
    $tid = $digits($_GET['t']);
    $t = $tid > 0 && Queue::verify(Queue::tokenSignature($hid, $tid), $_GET['k'] ?? null) ? Queue::findToken($tid) : null;
    if (!$t) {
        $notFound();
    }
    $status = (string) $t['status'];
    $open = $t['token_date'] === Queue::today() && in_array($status, ['waiting', 'called', 'serving'], true);
    $body = $head . '<div class="h">' . e(__('Your token')) . '</div><div class="n">' . e(Queue::label($t)) . '</div>'
        . '<div class="m"><span class="st">' . e(Queue::statusLabel($status)) . '</span></div>';
    if ($status === 'waiting' && $open) {
        $body .= '<p class="m">' . e(__(':n people before you', ['n' => Queue::ahead($t)])) . '</p>';
    } elseif (in_array($status, ['called', 'serving'], true) && $t['counter_id']) {
        $c = Queue::findCounter((int) $t['counter_id']);
        $body .= '<p class="m"><b>' . e(__('Please go to :c', ['c' => $c['name'] ?? ''])) . '</b>' . (!empty($c['room_text']) ? '<br>' . e($c['room_text']) : '') . '</p>';
    }
    $body .= '<p class="m" style="opacity:.75">' . e(__('Keep this page open. It updates by itself.')) . '</p>';
    $page(Queue::label($t), $body, $open ? 15 : 0);
}

// ---------------------------------------------------------------- start page
$body = $head . '<p class="m">' . e(__(':n people waiting', ['n' => Queue::waiting([(int) $svc['id']])])) . '</p>'
    . '<form method="post"><input name="name" maxlength="80" placeholder="' . e(__('Your name (optional)')) . '" autocomplete="name">'
    . '<button class="b" type="submit">' . e(__('Get my token')) . '</button></form>';
$page(__('Get my token'), $body);
