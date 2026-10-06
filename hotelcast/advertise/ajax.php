<?php
/**
 * Advertiser portal JSON endpoint (logged-in, active advertisers; CSRF header for POST).
 *   ?action=quote  POST {hotel_ids[], pricing_model, start_date, end_date, impressions, category}
 * Prices are always computed on the server (Marketplace::quote).
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';
MarketplacePortal::boot();

header('Content-Type: application/json; charset=utf-8');
$out = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_out($payload);
    exit;
};
$adv = MarketplacePortal::advertiser();
if (!$adv || $adv['status'] !== 'active') {
    $out(['ok' => false, 'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Please log in again']], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Csrf::valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    $out(['ok' => false, 'error' => ['code' => 'CSRF', 'message' => 'Security token expired. Reload the page.']], 419);
}
if (RateLimiter::hit('mkt_ajax:' . $adv['id'], 240, 3600) > 0) {
    $out(['ok' => false, 'error' => ['code' => 'RATE_LIMIT', 'message' => __('Too many requests. Please wait a moment.')]], 429);
}
$in = request_json();
$action = (string) ($_GET['action'] ?? '');
if ($action === 'quote') {
    $model = in_array($in['pricing_model'] ?? '', Marketplace::MODELS, true) ? (string) $in['pricing_model'] : 'per_day';
    $start = Ads::date($in['start_date'] ?? null);
    $end = Ads::date($in['end_date'] ?? null);
    $cat = in_array($in['category'] ?? '', Marketplace::categories(), true) ? (string) $in['category'] : 'other';
    $ids = array_slice(array_map('intval', (array) ($in['hotel_ids'] ?? [])), 0, Marketplace::MAX_HOTELS_PER_BOOKING);
    if (!$start || !$end || $end < $start || !$ids) {
        $out(['ok' => true, 'data' => null]);
    }
    $q = Marketplace::quote($ids, $model, $start, $end, $model === 'cpm' ? max(0, min(10000000, (int) ($in['impressions'] ?? 0))) : null, $cat, (int) ($in['id'] ?? 0) > 0 && Marketplace::booking((int) $adv['id'], (int) $in['id']) ? (int) $in['id'] : 0);
    $q['lines'] = array_map(fn ($l) => $l + ['amount_label' => Marketplace::money($l['amount']), 'unit_label' => Marketplace::money($l['unit_price'])], $q['lines']);
    $q['labels'] = ['subtotal' => Marketplace::money($q['subtotal']), 'tax' => Marketplace::money($q['tax']), 'total' => Marketplace::money($q['total']),
        'tax_name' => (string) Settings::platform('billing_tax_label', 'GST') . ' ' . rtrim(rtrim($q['tax_percent'], '0'), '.') . '%',
        'subtotal_text' => __('Subtotal'), 'total_text' => __('Total'), 'days' => __(':n days', ['n' => $q['days']]),
        'per_day' => __(':d days × :t TVs × :p'), 'cpm' => __(':i impressions × :p / 1000')];
    $out(['ok' => true, 'data' => $q]);
}
$out(['ok' => false, 'error' => ['code' => 'UNKNOWN_ACTION', 'message' => 'Unknown action']], 404);
