<?php
/**
 * 2.9.2 public TV app download — short link for the TV's Downloader / browser: https://<site>/app
 * Sends the newest Super Admin release rolled out to all customers (AppReleases::publicLatest), with
 * resume support. No login needed. Without such a release: a small page pointing to the support contact.
 */
declare(strict_types=1);
require __DIR__ . '/../core/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$apk = AppReleases::publicLatest();
if ($apk && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="' . AppReleases::fileName($apk) . '"');
        header('Content-Length: ' . (int) filesize(HC_ROOT . '/storage/' . $apk['file_path']));
        exit;
    }
    AppReleases::send($apk);
}

$lang = Landing::language($_GET, $_COOKIE, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
I18n::setLang($lang);
$brand = Branding::get(0);
http_response_code($apk ? 405 : 404);
header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(__('TV app download')) ?> — <?= e($brand['product']) ?></title>
</head>
<body style="font-family:system-ui,sans-serif;max-width:560px;margin:48px auto;padding:0 16px;line-height:1.6">
<h1><?= e(__('TV app download')) ?></h1>
<p><?= e(__('The TV app is not available for download right now. Please contact support.')) ?></p>
<?php if (!empty($brand['support_phone'])): ?><p><?= e($brand['support_phone']) ?></p><?php endif; ?>
<p><a href="<?= e(base_url()) ?>"><?= e($brand['product']) ?></a></p>
</body>
</html>
