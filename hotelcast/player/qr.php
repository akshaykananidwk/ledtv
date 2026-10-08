<?php
declare(strict_types=1);
/**
 * QR code image for the web player's setup screen: player/qr.php?code=K7P2QX → SVG of the claim URL
 * (admin/claim.php?code=K7P2QX), the same address the Android app shows as a QR code. Only valid code
 * syntax is accepted (6 characters of Provisioning's alphabet), so this cannot be used as a general QR
 * generator. The code itself is not secret (the TV's secret never leaves the TV).
 */
require __DIR__ . '/../core/bootstrap.php';

$code = Provisioning::normalizeCode($_GET['code'] ?? null);
if ($code === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=900');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
echo QrCode::svg(admin_url('claim.php', ['code' => $code]));
