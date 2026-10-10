<?php
declare(strict_types=1);

/**
 * Public card page in the login look (forgot password / reset password, 2.8). White-label: platform
 * branding, or a customer's / reseller's branding with ?b=<customer-slug> (same as login.php).
 */

/** Customer of ?b=<slug> (id, name, slug) or null. */
function auth_brand_hotel(): ?array
{
    $b = $_GET['b'] ?? $_POST['b'] ?? null;
    return is_string($b) && preg_match('/^[a-z0-9-]{1,80}$/', $b)
        ? DB::one('SELECT id, name, slug FROM hotels WHERE slug = :s AND archived_at IS NULL', ['s' => $b]) : null;
}

function auth_page(string $title, callable $body, ?array $brandHotel): never
{
    $brand = Branding::get($brandHotel ? (int) $brandHotel['id'] : 0);
    $name = $brandHotel ? (string) $brandHotel['name'] : $brand['product'];
    $lang = I18n::lang();
    $logo = $brand['logo_url'];
    header('Cache-Control: no-store');
    ?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($title) ?> · <?= e($name) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<style>:root{--hc-accent:<?= e($brand['color']) ?>;--hc-accent-dark:<?= e(Branding::shade($brand['color'])) ?>}.hc-login{background:radial-gradient(circle at 20% 20%,<?= e($brand['color']) ?>,#0f172a 65%)}</style>
</head>
<body class="hc-admin lang-<?= e($lang) ?>">
<div class="hc-login">
  <div class="card shadow-lg">
    <div class="card-body p-4 p-sm-5">
      <div class="text-center mb-4">
        <?php if ($logo): ?>
          <img src="<?= e($logo) ?>" alt="" style="max-height:72px;max-width:200px" class="mb-2">
        <?php else: ?>
          <div class="hc-brand-icon text-white mx-auto mb-2" style="width:56px;height:56px;font-size:1.7rem"><i class="bi bi-tv"></i></div>
        <?php endif; ?>
        <h1 class="h4 mb-0"><?= e($title) ?></h1>
        <div class="text-muted small"><?= e($name) ?></div>
      </div>
      <?php $body(); ?>
      <?php if ($brand['support_phone'] !== '' || $brand['support_email'] !== ''): ?>
        <div class="text-center text-muted small mt-3"><i class="bi bi-headset"></i> <?= e(__('Support')) ?>: <?= e(trim($brand['support_phone'] . ' ' . $brand['support_email'])) ?></div>
      <?php endif; ?>
      <div class="text-center mt-4 small">
        <?php foreach (I18n::LANGUAGES as $code => $lname): ?>
          <a href="?<?= e(http_build_query(array_filter(['lang' => $code, 'b' => $brandHotel['slug'] ?? null]))) ?>" class="mx-2<?= $code === $lang ? ' fw-bold' : '' ?>"><?= e($lname) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}
