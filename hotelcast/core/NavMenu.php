<?php
declare(strict_types=1);

/**
 * 2.9 Menu settings (docs/modules/menu_settings.md): custom order and hidden items of the sidebar.
 *
 *   Business default  Settings key "nav_menu" of the customer (Settings → Menu, settings.manage) — every user
 *                     of the customer workspace.
 *   Personal menu     users.menu_prefs (migration 036), one entry per panel (Profile → My menu) — overrides
 *                     the business default for that user; in the Super Admin console / reseller panel / chain
 *                     dashboard it is the only customisation.
 *
 * Config shape: ['order' => [nav keys], 'hidden' => [nav keys], 'more' => bool]. The config only reorders /
 * hides items the user can already see (hc_nav_sections() filters by permission, plan and mode first), so it can
 * never widen access; hidden pages keep working by URL. Unknown keys are ignored, items that are not in the saved
 * order (new modules) appear at their built-in position, required items (REQUIRED) are never hidden.
 */
final class NavMenu
{
    public const SETTING = 'nav_menu';

    /** Items that can be moved but never hidden (Profile / Logout / My menu live in the top bar). */
    public const REQUIRED = [
        'customer' => ['index', 'settings'],
        'platform' => ['platform_overview', 'platform_settings'],
        'reseller' => ['reseller_overview'],
        'chain' => ['chain'],
    ];

    private const KEY_RE = '/^[a-z0-9_]{1,60}$/';
    private const MAX_KEYS = 200;

    public static function required(string $panel): array
    {
        return self::REQUIRED[$panel] ?? [];
    }

    /** Clean a stored (JSON) or posted config; null = no customisation. */
    public static function normalize(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = trim($raw) === '' ? null : json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return null;
        }
        $clean = static function (mixed $list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $k) {
                if (is_string($k) && preg_match(self::KEY_RE, $k) && !in_array($k, $out, true)) {
                    $out[] = $k;
                }
                if (count($out) >= self::MAX_KEYS) {
                    break;
                }
            }
            return $out;
        };
        return [
            'order' => $clean($raw['order'] ?? []),
            'hidden' => $clean($raw['hidden'] ?? []),
            'more' => !array_key_exists('more', $raw) || !empty($raw['more']),
        ];
    }

    public static function encode(array $cfg): string
    {
        $cfg = self::normalize($cfg) ?? ['order' => [], 'hidden' => [], 'more' => true];
        return (string) json_encode(['order' => $cfg['order'], 'hidden' => $cfg['hidden'], 'more' => $cfg['more'] ? 1 : 0], JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ storage

    /** Business default of a customer (null = built-in menu). */
    public static function business(?int $hotelId = null): ?array
    {
        $hid = $hotelId ?? Tenant::current();
        if (!$hid) {
            return null;
        }
        return self::normalize((string) Settings::getFor($hid, self::SETTING, ''));
    }

    public static function setBusiness(int $hotelId, ?array $cfg): void
    {
        Settings::setFor($hotelId, self::SETTING, $cfg === null ? '' : self::encode($cfg));
    }

    /** All personal menus of a user row: [panel => config]. */
    private static function personalAll(?array $user): array
    {
        $raw = $user['menu_prefs'] ?? null;
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }

    /** Personal menu of a user for a panel (null = use the business default / built-in). */
    public static function personal(?array $user, string $panel): ?array
    {
        $all = self::personalAll($user);
        return isset($all[$panel]) ? self::normalize($all[$panel]) : null;
    }

    public static function setPersonal(int $userId, string $panel, ?array $cfg): void
    {
        if (!in_array($panel, Panel::PANELS, true)) {
            throw new InvalidArgumentException('Unknown panel');
        }
        $all = self::personalAll(DB::one('SELECT menu_prefs FROM users WHERE id = :id', ['id' => $userId]) ?: null);
        $all = array_intersect_key($all, array_flip(Panel::PANELS));
        if ($cfg === null) {
            unset($all[$panel]);
        } else {
            $all[$panel] = json_decode(self::encode($cfg), true);
        }
        DB::update('users', ['menu_prefs' => $all ? json_encode($all, JSON_UNESCAPED_SLASHES) : null], 'id = :id', ['id' => $userId]);
    }

    /**
     * Does a personal menu apply in this panel? Not while a Super Admin / reseller / chain admin works inside a
     * customer they opened: there they see the customer's own menu (what the customer sees).
     */
    public static function personalAllowed(string $panel): bool
    {
        return !($panel === 'customer' && Panel::impersonating());
    }

    /**
     * Effective config of the current user in a panel: user override → business default (customer workspace)
     * → built-in. Returns [config|null, source 'user'|'business'|'default'].
     */
    public static function effective(string $panel, ?array $user = null): array
    {
        $user ??= Auth::user();
        try {
            if ($user && self::personalAllowed($panel) && ($cfg = self::personal($user, $panel)) !== null) {
                return [$cfg, 'user'];
            }
            if ($panel === 'customer' && Tenant::has() && ($cfg = self::business()) !== null) {
                return [$cfg, 'business'];
            }
        } catch (Throwable $e) {
            // Before migration 036 (update in progress): built-in menu.
        }
        return [null, 'default'];
    }

    // ------------------------------------------------------------------ applying

    /**
     * Order items (key => item): keys of the saved order first, in that order; keys that are not in the saved
     * order (e.g. a module added by a later version) keep their built-in position — right after the nearest
     * built-in predecessor that is placed already (or first). Unknown saved keys are ignored.
     */
    public static function order(array $items, array $order): array
    {
        $default = array_keys($items);
        $keys = array_values(array_filter($order, static fn ($k) => isset($items[$k])));
        foreach ($default as $i => $k) {
            if (in_array($k, $keys, true)) {
                continue;
            }
            $pos = 0;
            for ($j = $i - 1; $j >= 0; $j--) {
                $p = array_search($default[$j], $keys, true);
                if ($p !== false) {
                    $pos = $p + 1;
                    break;
                }
            }
            array_splice($keys, $pos, 0, [$k]);
        }
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $items[$k];
        }
        return $out;
    }

    /** Keys of a config that are really hidden in a panel (required items never). */
    public static function hiddenKeys(?array $cfg, string $panel): array
    {
        return $cfg ? array_values(array_diff($cfg['hidden'], self::required($panel))) : [];
    }

    /**
     * Apply a config to the panel's sections ([section => [key => item]], already filtered by permission / plan):
     * ['sections' => ordered, visible items; 'hidden' => [key => item] (in menu order); 'more' => bool].
     */
    public static function apply(array $sections, ?array $cfg, string $panel): array
    {
        if ($cfg === null) {
            return ['sections' => $sections, 'hidden' => [], 'more' => true];
        }
        $hide = array_flip(self::hiddenKeys($cfg, $panel));
        $out = [];
        $hidden = [];
        foreach ($sections as $section => $items) {
            foreach (self::order($items, $cfg['order']) as $k => $item) {
                if (isset($hide[$k])) {
                    $hidden[$k] = $item;
                } else {
                    $out[$section][$k] = $item;
                }
            }
        }
        return ['sections' => $out, 'hidden' => $hidden, 'more' => $cfg['more']];
    }

    /**
     * Build a config from a posted form: order[] (every listed key, in the new order) and show[] (keys shown).
     * Only keys in $allowed (what the editor can see) are kept; hidden keys the editor cannot see are carried
     * over from $previous so a restricted editor never un-hides them by accident.
     */
    public static function fromPost(array $post, array $allowed, string $panel, ?array $previous = null): array
    {
        $order = self::normalize(['order' => $post['order'] ?? []])['order'];
        $order = array_values(array_filter($order, static fn ($k) => in_array($k, $allowed, true)));
        $show = self::normalize(['order' => $post['show'] ?? []])['order'];
        $hidden = array_values(array_diff($order, $show, self::required($panel)));
        foreach ($previous['hidden'] ?? [] as $k) {
            if (!in_array($k, $allowed, true) && !in_array($k, $hidden, true)) {
                $hidden[] = $k;
            }
        }
        return ['order' => $order, 'hidden' => $hidden, 'more' => !empty($post['more'])];
    }
}
