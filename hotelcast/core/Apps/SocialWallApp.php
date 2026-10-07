<?php
declare(strict_types=1);

/**
 * Social wall (#16) without API tokens: (a) the official Facebook Page Plugin (page timeline) for a
 * page URL and / or (b) a rotating list of public Instagram post / reel and Facebook post / video URLs
 * shown through their official embed iframes (instagram.com/p/<code>/embed/, facebook.com/plugins/
 * post.php|video.php?href=). Only facebook.com / instagram.com links are accepted, rebuilt from their
 * parts. The server never fetches them: the TV loads the embeds itself, so it needs internet, posts
 * must be public, autoplay may be blocked and there is no automatic "latest posts" (that needs an
 * API token — not supported). X / Twitter is not supported. See docs/modules/content_apps.md.
 */
final class SocialWallApp extends DisplayApp
{
    public const MAX_POSTS = 30;
    private const FB_HOSTS = ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com', 'mbasic.facebook.com'];
    private const IG_HOSTS = ['instagram.com', 'www.instagram.com', 'm.instagram.com'];
    private const FB_RESERVED = ['plugins', 'sharer', 'sharer.php', 'login', 'login.php', 'dialog', 'groups', 'events', 'watch', 'reel', 'share', 'photo', 'photo.php', 'permalink.php', 'story.php', 'home.php', 'profile.php', 'pages', 'hashtag', 'search', 'marketplace', 'gaming', 'l.php'];

    public function key(): string
    {
        return 'social_wall';
    }

    public function label(): string
    {
        return __('Social wall');
    }

    public function description(): string
    {
        return __('Your Facebook page timeline and chosen public Instagram / Facebook posts, shown with their official embeds. Needs internet on the TV.');
    }

    public function icon(): string
    {
        return 'bi-instagram';
    }

    public function category(): string
    {
        return 'content';
    }

    public function defaults(): array
    {
        return [
            'heading' => '',
            'page_url' => '',
            'posts' => '',
            'per_screen' => 2,
            'rotate_sec' => 20,
            'captions' => true,
        ];
    }

    private static function parts(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 1000 || preg_match('/[\s<>"\'\\\\]/', $url)) {
            return null;
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $p = parse_url($url);
        if (!is_array($p) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) {
            return null;
        }
        $p['host'] = strtolower((string) ($p['host'] ?? ''));
        $p['path'] = (string) ($p['path'] ?? '/');
        parse_str((string) ($p['query'] ?? ''), $q);
        $p['q'] = $q;
        return $p;
    }

    /** Canonical https://www.facebook.com/<page> (or profile.php?id=N) for a Facebook page link; null otherwise. */
    public static function normalizePage(string $url): ?string
    {
        $p = self::parts($url);
        if (!$p || !in_array($p['host'], self::FB_HOSTS, true)) {
            return null;
        }
        if (preg_match('#^/profile\.php/?$#', $p['path']) && is_string($p['q']['id'] ?? null) && ctype_digit($p['q']['id']) && strlen($p['q']['id']) <= 20) {
            return 'https://www.facebook.com/profile.php?id=' . $p['q']['id'];
        }
        if (preg_match('#^/people/([A-Za-z0-9.\-]{1,80})/(\d{5,20})/?$#', $p['path'], $m)) {
            return 'https://www.facebook.com/people/' . $m[1] . '/' . $m[2] . '/';
        }
        if (preg_match('#^/([A-Za-z0-9.\-]{2,80})/?$#', $p['path'], $m) && !in_array(strtolower($m[1]), self::FB_RESERVED, true)) {
            return 'https://www.facebook.com/' . $m[1];
        }
        return null;
    }

    /**
     * A public post link → ['network' => instagram|facebook, 'kind' => post|video, 'url' => canonical,
     * 'embed' => official embed iframe src], or null when not an accepted Instagram / Facebook link.
     */
    public static function normalizePost(string $url, bool $captions = true): ?array
    {
        $p = self::parts($url);
        if (!$p) {
            return null;
        }
        if (in_array($p['host'], self::IG_HOSTS, true)) {
            if (!preg_match('#^/(?:[A-Za-z0-9._]{1,30}/)?(p|reels?|tv)/([A-Za-z0-9_-]{5,40})/?(?:embed/?(?:captioned/?)?)?$#', $p['path'], $m)) {
                return null;
            }
            $kind = $m[1] === 'p' ? 'p' : ($m[1] === 'tv' ? 'tv' : 'reel');
            $canon = 'https://www.instagram.com/' . $kind . '/' . $m[2] . '/';
            return ['network' => 'instagram', 'kind' => $kind === 'p' ? 'post' : 'video', 'url' => $canon, 'embed' => $canon . 'embed/' . ($captions ? 'captioned/' : '')];
        }
        if (!in_array($p['host'], self::FB_HOSTS, true)) {
            return null;
        }
        $id = '[A-Za-z0-9_-]{5,120}';
        $canon = null;
        $kind = 'post';
        $q = $p['q'];
        if (preg_match('#^/([A-Za-z0-9.\-]{2,80})/posts/(' . $id . ')/?$#', $p['path'], $m)) {
            $canon = 'https://www.facebook.com/' . $m[1] . '/posts/' . $m[2];
        } elseif (preg_match('#^/([A-Za-z0-9.\-]{2,80})/videos/(?:[A-Za-z0-9.\-]{1,80}/)?(\d{5,30})/?$#', $p['path'], $m)) {
            $canon = 'https://www.facebook.com/' . $m[1] . '/videos/' . $m[2] . '/';
            $kind = 'video';
        } elseif (preg_match('#^/reel/(\d{5,30})/?$#', $p['path'], $m)) {
            $canon = 'https://www.facebook.com/reel/' . $m[1];
            $kind = 'video';
        } elseif (preg_match('#^/watch/?$#', $p['path']) && is_string($q['v'] ?? null) && preg_match('/^\d{5,30}$/', $q['v'])) {
            $canon = 'https://www.facebook.com/watch/?v=' . $q['v'];
            $kind = 'video';
        } elseif (preg_match('#^/(photo|photo\.php)/?$#', $p['path']) && is_string($q['fbid'] ?? null) && preg_match('/^\d{5,30}$/', $q['fbid'])) {
            $canon = 'https://www.facebook.com/photo/?fbid=' . $q['fbid'];
        } elseif (preg_match('#^/(permalink|story)\.php$#', $p['path']) && is_string($q['story_fbid'] ?? null) && preg_match('/^' . $id . '$/', $q['story_fbid'])
            && is_string($q['id'] ?? null) && preg_match('/^\d{1,30}$/', $q['id'])) {
            $canon = 'https://www.facebook.com/permalink.php?story_fbid=' . $q['story_fbid'] . '&id=' . $q['id'];
        }
        if ($canon === null) {
            return null;
        }
        $embed = 'https://www.facebook.com/plugins/' . ($kind === 'video' ? 'video.php' : 'post.php') . '?href=' . rawurlencode($canon)
            . ($kind === 'video' ? '&show_text=' . ($captions ? 'true' : 'false') . '&autoplay=true&mute=true' : '&show_text=' . ($captions ? 'true' : 'false')) . '&width=500';
        return ['network' => 'facebook', 'kind' => $kind, 'url' => $canon, 'embed' => $embed];
    }

    /** Official Page Plugin iframe src for a canonical page URL. */
    public static function pageEmbed(string $page): string
    {
        return 'https://www.facebook.com/plugins/page.php?href=' . rawurlencode($page) . '&tabs=timeline&width=500&height=1000&small_header=false&adapt_container_width=true&hide_cover=false&show_facepile=false';
    }

    public function validate(array $in): array
    {
        $errors = [];
        $pageRaw = self::str($in, 'page_url', 1000);
        $page = '';
        if ($pageRaw !== '') {
            $page = self::normalizePage($pageRaw) ?? '';
            if ($page === '') {
                $errors[] = __('Facebook page: enter a link like https://www.facebook.com/yourpage');
            }
        }
        $posts = [];
        $bad = [];
        foreach (ContentApps::lines(self::text($in, 'posts', 20000), 100, 1000) as $line) {
            $n = self::normalizePost($line);
            if ($n === null) {
                $bad[] = $line;
            } elseif (!in_array($n['url'], $posts, true) && count($posts) < self::MAX_POSTS) {
                $posts[] = $n['url'];
            }
        }
        foreach ($bad as $b) {
            $errors[] = preg_match('#(^|//|\.)(twitter\.com|x\.com)(/|$)#i', $b)
                ? __('X / Twitter posts are not supported: :u', ['u' => mb_substr($b, 0, 100)])
                : __('Not a public Instagram or Facebook post link: :u', ['u' => mb_substr($b, 0, 100)]);
        }
        if ($page === '' && !$posts && !$errors) {
            $errors[] = __('Enter a Facebook page and / or at least one Instagram or Facebook post link.');
        }
        return [[
            'heading' => self::str($in, 'heading', 120),
            'page_url' => $page,
            'posts' => implode("\n", $posts),
            'per_screen' => self::int($in, 'per_screen', 1, 3, 2),
            'rotate_sec' => self::int($in, 'rotate_sec', 5, 300, 20),
            'captions' => self::bool($in, 'captions'),
        ], $errors];
    }

    public function form(array $config): string
    {
        return '<div class="col-12"><div class="alert alert-info small mb-0">'
            . e(__('Works without a token: posts must be public and the TV needs internet. Videos may not autoplay. New posts are not added automatically — paste the links of the posts you want to show.')) . '</div></div>'
            . self::input('heading', __('Heading (optional)'), $config['heading'], 'text', ['maxlength' => 120, 'placeholder' => __('Follow us @yourhotel')])
            . self::input('page_url', __('Facebook page (timeline)'), $config['page_url'], 'url', ['maxlength' => 1000, 'placeholder' => 'https://www.facebook.com/yourpage'], __('Optional. Shows the page\'s latest public posts with the official Facebook Page Plugin.'))
            . self::textarea('posts', __('Instagram / Facebook post links (one per line)'), (string) $config['posts'], 6,
                __('Public posts or reels, e.g. https://www.instagram.com/p/ABC123/ or https://www.facebook.com/yourpage/posts/123… (at most :n).', ['n' => self::MAX_POSTS]), 'col-12', 20000)
            . self::input('per_screen', __('Posts side by side'), $config['per_screen'], 'number', ['min' => 1, 'max' => 3], '', 'col-md-4')
            . self::input('rotate_sec', __('Next posts after (seconds)'), $config['rotate_sec'], 'number', ['min' => 5, 'max' => 300], '', 'col-md-4')
            . self::checkbox('captions', __('Show post texts'), (bool) $config['captions'], 'col-md-4');
    }

    /** Embeds for the TV. */
    private function embeds(array $config): array
    {
        $out = [];
        foreach (ContentApps::lines((string) $config['posts'], self::MAX_POSTS, 1000) as $line) {
            $n = self::normalizePost($line, (bool) $config['captions']);
            if ($n) {
                $out[] = ['network' => $n['network'], 'kind' => $n['kind'], 'embed' => $n['embed']];
            }
        }
        return $out;
    }

    public function data(array $config, array $ctx): ?array
    {
        $page = $config['page_url'] !== '' ? self::normalizePage((string) $config['page_url']) : null;
        return [
            'posts' => $this->embeds($config),
            'page' => $page ? self::pageEmbed($page) : null,
            'per_screen' => (int) $config['per_screen'],
            'rotate_sec' => (int) $config['rotate_sec'],
        ];
    }

    /** Same markup as assets/display/apps/social_wall.js → frame(). */
    public static function frame(string $src, string $cls): string
    {
        return '<div class="sw-card ' . e($cls) . '"><div class="sw-scale"><iframe src="' . e($src) . '" width="500" height="880" scrolling="no" frameborder="0" allowfullscreen'
            . ' allow="autoplay; encrypted-media; picture-in-picture" sandbox="allow-scripts allow-same-origin allow-popups allow-presentation" referrerpolicy="strict-origin-when-cross-origin"></iframe></div></div>';
    }

    public function render(array $config, array $ctx): string
    {
        $d = $this->data($config, $ctx);
        $h = '<div class="sw-wrap' . ($d['page'] ? ' sw-has-page' : '') . '">';
        if ($config['heading'] !== '') {
            $h .= '<div class="hc-header"><div class="hc-title"><h1>' . e($config['heading']) . '</h1></div></div>';
        }
        $h .= '<div class="hc-body sw-body">';
        if ($d['page']) {
            $h .= '<div class="sw-page">' . self::frame($d['page'], 'sw-page-card') . '</div>';
        }
        if ($d['posts']) {
            $h .= '<div class="sw-posts" id="swPosts">';
            foreach (array_slice($d['posts'], 0, $d['page'] ? min(2, $d['per_screen']) : $d['per_screen']) as $p) {
                $h .= self::frame($p['embed'], 'sw-' . $p['network']);
            }
            $h .= '</div>';
        }
        if (!$d['page'] && !$d['posts']) {
            $h .= '<div class="hc-empty">' . e(__('Add a Facebook page or Instagram / Facebook post links in the app settings.')) . '</div>';
        }
        return $h . '</div></div>';
    }
}
