<?php
declare(strict_types=1);

/**
 * Advertiser portal (/advertise/) — session, guards and the mobile-first layout.
 *
 * Separate session namespace: cookie HCADVSESSID with path /advertise/, so the browser never sends it to
 * /admin/ and the admin cookie (HCSESSID, Auth) is never started here. The session only stores an opaque
 * token checked against mkt_advertiser_sessions; it never carries hc_token / hc_uid, so an advertiser
 * session can not authenticate in the admin panel. Csrf (which calls Auth::startSession()) reuses the
 * session started here.
 */
final class MarketplacePortal
{
    public const COOKIE = 'HCADVSESSID';
    /** Advertiser UI languages. */
    public const LANGUAGES = ['en' => 'English', 'gu' => 'ગુજરાતી'];

    private static ?array $adv = null;
    private static bool $resolved = false;

    public static function boot(): void
    {
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");
        self::startSession();
        $lang = $_GET['lang'] ?? null;
        if (is_string($lang) && isset(self::LANGUAGES[$lang])) {
            $_SESSION['lang'] = $lang;
            if ($a = self::advertiser()) {
                DB::update('mkt_advertisers', ['language' => $lang], 'id = :id', ['id' => $a['id']]);
            }
        }
        $l = $_SESSION['lang'] ?? 'en';
        I18n::setLang(isset(self::LANGUAGES[$l]) ? $l : 'en');
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== self::COOKIE) {
                throw new LogicException('Another session is already active');
            }
            return;
        }
        $path = (parse_url(base_url('advertise/'), PHP_URL_PATH) ?: '/advertise/');
        session_name(self::COOKIE);
        session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public static function login(array $adv): void
    {
        session_regenerate_id(true);
        $token = random_token(32);
        DB::insert('mkt_advertiser_sessions', [
            'advertiser_id' => (int) $adv['id'], 'session_hash' => hash('sha256', $token), 'ip_address' => client_ip(),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at' => now(), 'last_activity' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + Marketplace::ABSOLUTE_TIMEOUT),
        ]);
        $_SESSION = ['mkt_token' => $token, 'lang' => isset(self::LANGUAGES[$adv['language'] ?? '']) ? $adv['language'] : ($_SESSION['lang'] ?? 'en')];
        self::$adv = null;
        self::$resolved = false;
    }

    public static function logout(): void
    {
        if (!empty($_SESSION['mkt_token'])) {
            DB::query('UPDATE mkt_advertiser_sessions SET revoked = 1 WHERE session_hash = :h', ['h' => hash('sha256', (string) $_SESSION['mkt_token'])]);
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_destroy();
        self::$adv = null;
        self::$resolved = true;
    }

    /** Logged-in advertiser (any status except suspended) or null. */
    public static function advertiser(): ?array
    {
        if (self::$resolved) {
            return self::$adv;
        }
        self::$resolved = true;
        $token = $_SESSION['mkt_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $row = DB::one(
            'SELECT s.id AS sid, s.last_activity, s.expires_at, s.revoked, a.* FROM mkt_advertiser_sessions s
             JOIN mkt_advertisers a ON a.id = s.advertiser_id WHERE s.session_hash = :h',
            ['h' => hash('sha256', $token)]
        );
        if (!$row || (int) $row['revoked'] || $row['status'] === 'suspended' || strtotime((string) $row['expires_at']) < time()
            || strtotime((string) $row['last_activity']) < time() - Marketplace::IDLE_TIMEOUT) {
            unset($_SESSION['mkt_token']);
            return null;
        }
        if (strtotime((string) $row['last_activity']) < time() - 60) {
            DB::query('UPDATE mkt_advertiser_sessions SET last_activity = :n WHERE id = :id', ['n' => now(), 'id' => $row['sid']]);
        }
        unset($row['password_hash'], $row['otp_hash']);
        self::$adv = $row;
        return $row;
    }

    /** Guard: logged-in advertiser; $active = account must be verified and approved. */
    public static function require(bool $active = true): array
    {
        $a = self::advertiser();
        if (!$a) {
            redirect(self::url('login.php', ['next' => (string) ($_SERVER['REQUEST_URI'] ?? '')]));
        }
        if ($a['status'] === 'unverified') {
            redirect(self::url('verify.php'));
        }
        if ($active && $a['status'] !== 'active') {
            redirect(self::url('index.php'));
        }
        return $a;
    }

    public static function url(string $page = '', array $q = []): string
    {
        $u = base_url('advertise/' . ltrim($page, '/'));
        return $q ? $u . '?' . http_build_query($q) : $u;
    }

    /** Only local /advertise/ paths are accepted as "next" after login (no open redirect). */
    public static function safeNext(string $next): string
    {
        $path = (string) parse_url(base_url('advertise/'), PHP_URL_PATH);
        return $next !== '' && str_starts_with($next, $path) && !str_contains($next, '//') && !str_contains($next, '\\') ? $next : self::url('index.php');
    }

    public static function flash(string $type, string $msg): void
    {
        $_SESSION['mkt_flash'][] = ['t' => in_array($type, ['success', 'danger', 'warning', 'info'], true) ? $type : 'info', 'm' => $msg];
    }

    public static function flashHtml(): string
    {
        $out = '';
        foreach ($_SESSION['mkt_flash'] ?? [] as $f) {
            $out .= '<div class="alert alert-' . e($f['t']) . '" role="alert">' . nl2br(e($f['m'])) . '</div>';
        }
        unset($_SESSION['mkt_flash']);
        return $out;
    }

    /** Rate limit by IP (and key); on excess: flash + redirect back. */
    public static function throttle(string $key, int $max, int $window, string $back): void
    {
        if (RateLimiter::hit('mkt_' . $key . ':' . client_ip(), $max, $window) > 0) {
            self::flash('danger', __('Too many attempts. Please wait a few minutes.'));
            redirect($back);
        }
    }

    public static function header(string $title, string $active = ''): void
    {
        $a = self::advertiser();
        $brand = Branding::get(0);
        $lang = I18n::lang();
        $nav = $a && $a['status'] === 'active' ? [
            'index' => ['index.php', 'bi-speedometer2', __('Dashboard')],
            'hotels' => ['hotels.php', 'bi-buildings', __('Hotels')],
            'book' => ['book.php', 'bi-plus-circle', __('Book an ad')],
            'creatives' => ['creatives.php', 'bi-images', __('My ads')],
        ] : ['hotels' => ['hotels.php', 'bi-buildings', __('Hotels')]];
        ?><!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<title><?= e($title) ?> · <?= e(__('Advertise')) ?> · <?= e($brand['product']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Gujarati:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('advertise/advertise.css')) ?>">
<script src="<?= e(asset('advertise/advertise.js')) ?>" defer></script>
</head>
<body class="mkt lang-<?= e($lang) ?>">
<header class="mkt-top">
  <a class="mkt-brand" href="<?= e(self::url('index.php')) ?>"><i class="bi bi-tv"></i> <span><?= e($brand['product']) ?> <small><?= e(__('Advertise')) ?></small></span></a>
  <div class="mkt-top-right">
    <?php foreach (self::LANGUAGES as $code => $name): if ($code !== $lang): ?>
      <a class="btn btn-sm btn-outline-light" href="?<?= e(http_build_query(array_merge(array_diff_key($_GET, ['lang' => 1]), ['lang' => $code]))) ?>"><?= e($name) ?></a>
    <?php endif; endforeach; ?>
    <?php if ($a): ?>
      <a class="btn btn-sm btn-light" href="<?= e(self::url('logout.php')) ?>" title="<?= e(__('Log out')) ?>"><i class="bi bi-box-arrow-right"></i><span class="d-none d-sm-inline"> <?= e(__('Log out')) ?></span></a>
    <?php else: ?>
      <a class="btn btn-sm btn-light" href="<?= e(self::url('login.php')) ?>"><?= e(__('Log in')) ?></a>
    <?php endif; ?>
  </div>
</header>
<?php if ($a): ?>
<nav class="mkt-nav" aria-label="<?= e(__('Menu')) ?>">
  <?php foreach ($nav as $k => [$href, $icon, $label]): ?>
    <a href="<?= e(self::url($href)) ?>"<?= $active === $k ? ' class="active" aria-current="page"' : '' ?>><i class="bi <?= e($icon) ?>"></i><span><?= e($label) ?></span></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="mkt-main">
<?= self::flashHtml() ?>
<?php
    }

    public static function footer(): void
    {
        $brand = Branding::get(0);
        ?>
</main>
<footer class="mkt-foot">&copy; <?= date('Y') ?> <?= e($brand['product']) ?><?php if ($brand['support_phone'] !== '' || $brand['support_email'] !== ''): ?> · <?= e(__('Support')) ?>: <?= e(trim($brand['support_phone'] . ' ' . $brand['support_email'])) ?><?php endif; ?></footer>
</body>
</html>
<?php
    }

    /** TV-simulator frame (16:9) showing a creative as the TV would. */
    public static function tvFrame(array $c, string $hotel = ''): string
    {
        $p = Marketplace::previewData($c);
        $inner = match ($p['type']) {
            'image' => '<img src="' . e((string) $p['url']) . '" alt="' . e($p['title']) . '">',
            'video' => '<video src="' . e((string) $p['url']) . '" muted autoplay loop playsinline></video>',
            default => '<div class="tv-text" style="background:' . e($p['bg']) . ';color:' . e($p['fg']) . '"><strong>' . e($p['text']) . '</strong>'
                . ($p['subtitle'] !== '' ? '<span>' . e($p['subtitle']) . '</span>' : '') . '</div>',
        };
        return '<div class="tv-sim" role="img" aria-label="' . e(__('TV preview')) . '"><div class="tv-screen">' . $inner
            . '<div class="tv-badge">' . e(__('Ad')) . '</div>' . ($hotel !== '' ? '<div class="tv-hotel">' . e($hotel) . '</div>' : '') . '</div><div class="tv-stand"></div></div>';
    }
}
