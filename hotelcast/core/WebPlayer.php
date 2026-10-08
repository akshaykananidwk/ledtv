<?php
declare(strict_types=1);

/**
 * Web player (2.4, #45): a browser version of the TV app at /player/ for Samsung Tizen / LG webOS
 * browsers, Fire TV Silk, kiosk PCs and Raspberry Pi Chromium. It uses the normal device API
 * (register, command poll, heartbeat, ack) with device type "web".
 *
 * This class holds what the PHP side needs: version, page headers (CSP), the JS config and the
 * player's UI strings (translated with __() tables: lang/gu_webplayer.php, lang/hi_webplayer.php).
 * See docs/modules/web_player.md.
 */
final class WebPlayer
{
    /** Shown as app_version "web-2.4.1". */
    public const VERSION = '2.4.1';
    /**
     * Feature level, sent as app_version_code: the 2.4 feature level (11), so the server sends split screen
     * layouts (Layouts::MIN_APP_CODE = 10) and every 2.4 field. The 2.4.1 emergency alarm needs no newer level
     * (`emergency.alarm` is sent to every TV; older apps ignore it).
     */
    public const VERSION_CODE = 11;

    /** Public URL of the player (what the "Web player link" button shows). */
    public static function url(): string
    {
        return base_url('player/');
    }

    /**
     * Content-Security-Policy of the player page. The page itself only loads its own scripts, but the
     * content it shows is open by design: media from any server / CDN, web pages and YouTube in iframes,
     * and HTML items in sandboxed srcdoc iframes, which inherit this policy (so inline scripts / styles of
     * those documents must stay allowed). No framing restriction: kiosk shells may embed the player.
     */
    public static function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https: http: blob:",
            "style-src 'self' 'unsafe-inline' https: http:",
            'img-src * data: blob:',
            'media-src * data: blob:',
            'font-src * data:',
            'connect-src *',
            'frame-src * data: blob:',
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            'frame-ancestors *',
        ]);
    }

    /** Send the player page headers (replaces the global X-Frame-Options / Permissions-Policy). */
    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Frame-Options');
        header('Content-Type: text/html; charset=utf-8');
        // Always revalidated, so a reload (RELOAD / REBOOT command) picks up a new player version.
        header('Cache-Control: no-cache, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Security-Policy: ' . self::csp());
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), autoplay=*, fullscreen=*, screen-wake-lock=*');
    }

    /**
     * Asset URL relative to /player/ (with a cache-busting version), so the page works under any host name
     * or IP address the screen uses, even when it differs from the configured base_url.
     */
    public static function asset(string $path): string
    {
        $file = HC_ROOT . '/assets/' . ltrim($path, '/');
        $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '1';
        return '../assets/' . ltrim($path, '/') . '?v=' . $v;
    }

    /** JSON safe to put inside a <script type="application/json"> element (no "</script>" breakout). */
    public static function embed(mixed $v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
    }

    /** UI language for the first screen: ?lang=, else the browser's Accept-Language (gu / hi), else en. */
    public static function language(array $get = [], string $acceptLanguage = ''): string
    {
        $l = is_string($get['lang'] ?? null) ? strtolower(substr($get['lang'], 0, 2)) : '';
        if (isset(I18n::GUEST_LANGUAGES[$l])) {
            return $l;
        }
        foreach (explode(',', strtolower($acceptLanguage)) as $part) {
            $code = substr(trim($part), 0, 2);
            if (in_array($code, ['gu', 'hi'], true)) {
                return $code;
            }
            if ($code === 'en') {
                return 'en';
            }
        }
        return 'en';
    }

    /** English keys of every string the player shows (translated in lang/gu_webplayer.php / hi_webplayer.php). */
    public static function strings(): array
    {
        return [
            'Set up this screen', 'Scan with your phone',
            'Open the camera on your phone, scan the code and choose the screen. This screen starts by itself.',
            'Setup code', 'Getting a setup code…', 'QR setup is not available. Use the manual setup.',
            'The code expired. Getting a new one…', 'Assigned to screen :room. Connecting…',
            'Or enter the details', 'Server address', 'Screen name / ID', 'Registration key', 'Connect', 'Connecting…',
            'Find the registration key in Admin → Settings → Devices.',
            'Please fill in the screen name / ID and the registration key.',
            'The registration key is wrong.', 'Screen :room does not exist. Add it in the admin panel first.',
            'TV limit reached. Remove an old TV in the admin panel or upgrade your plan.',
            'Cannot reach the server. Check the address and the network.',
            'Too many attempts. Please try again in a minute.',
            'Welcome', 'Welcome to :name', 'Screen :room', 'Service paused', 'Please contact the administrator.', 'Support: :s',
            'Emergency', 'Press OK to enable sound', 'Offline — showing the last content',
            'This stream cannot play in a web browser.', 'This video cannot play in this browser.',
            'This web page does not allow being shown inside another page.',
            'Settings', 'Enter the settings PIN', 'Wrong PIN', 'Reload', 'Re-pair this screen', 'Reset the player',
            'Language', 'Close', 'Room', 'Device ID', 'Server', 'Version', 'Press OK again to confirm',
            'Web player', 'Sound on', 'Full screen', 'Message',
            'This screen was removed from the server. Please set it up again.',
            // 2.4.1 emergency alarm
            'Tap or press OK to enable the alarm sound',
        ];
    }

    /** {en: {key: text}, gu: {…}, hi: {…}} for the player's JS. */
    public static function dictionary(): array
    {
        $out = [];
        foreach (array_keys(I18n::GUEST_LANGUAGES) as $lang) {
            foreach (self::strings() as $key) {
                $out[$lang][$key] = $lang === 'en' ? $key : I18n::translate($key, $lang);
            }
        }
        return $out;
    }

    /** JSON config embedded in player/index.php (read by assets/player/player.js). */
    public static function config(array $get = [], string $acceptLanguage = ''): array
    {
        $brand = Branding::get(0);
        $hls = HC_ROOT . '/assets/vendor/hlsjs/hls.min.js'; // vendored hls.js (optional): loaded only when needed
        return [
            'version' => self::VERSION,
            'versionCode' => self::VERSION_CODE,
            // Relative to /player/: works under any host name / IP the screen uses to open the player.
            'api' => '../api/index.php?r=',
            'qr' => 'qr.php',
            'hls' => is_file($hls) ? self::asset('vendor/hlsjs/hls.min.js') : null,
            'lang' => self::language($get, $acceptLanguage),
            'product' => (string) $brand['product'],
            'color' => (string) $brand['color'],
            'logo' => $brand['logo_url'],
            'strings' => self::dictionary(),
        ];
    }
}
