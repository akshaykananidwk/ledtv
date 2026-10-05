<?php
/** Brand colour for the guest app as a stylesheet (the app's CSP forbids inline styles). */
declare(strict_types=1);
$c = is_string($_GET['c'] ?? null) && preg_match('/^[0-9a-fA-F]{6}$/', $_GET['c']) ? strtoupper($_GET['c']) : '7B1FA2';
$rgb = array_map('hexdec', str_split($c, 2));
$dark = vsprintf('%02X%02X%02X', array_map(fn ($v) => (int) round($v * 0.78), $rgb));
header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo ':root{--brand:#' . $c . ';--brand-dark:#' . $dark . ';--brand-rgb:' . implode(',', $rgb) . '}';
