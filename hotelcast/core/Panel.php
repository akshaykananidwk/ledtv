<?php
declare(strict_types=1);

/**
 * 2.6 panels (docs/modules/panels.md): which of the three admin panels the current request renders.
 *
 *   platform  Super Admin console — platform_admin outside a customer
 *   reseller  Reseller panel      — reseller outside a customer
 *   customer  Customer workspace  — customer roles; and a platform admin / reseller / chain admin who
 *                                   opened a customer (Auth::enterHotel, shown with the impersonation banner)
 *   chain     Chain dashboard     — chain_admin outside a customer
 *
 * Nothing is stored: the panel follows the role and Auth::inEnteredHotel(). Permissions stay in Auth::can();
 * this class only decides the shell (theme, sidebar sections, home page).
 */
final class Panel
{
    public const PANELS = ['platform', 'reseller', 'customer', 'chain'];

    /** Nav sections (admin/partials/nav.d) shown per panel. */
    public const SECTIONS = [
        'platform' => ['platform'],
        'reseller' => ['reseller'],
        'customer' => ['hotel', 'chain'],
        'chain' => ['chain'],
    ];

    /** Sidebar order per panel (nav keys; unknown keys follow in file order). */
    public const ORDER = [
        // docs/SPEC_SAAS.md §34: Dashboard, Clients, Plans & modules, Subscriptions, Devices, Content overview, Reports,
        // Notifications, Audit logs, System settings (+ resellers / chains / marketplace / support / update / QR).
        'platform' => ['platform_overview', 'platform_hotels', 'platform_resellers', 'platform_chains',
            'platform_plans', 'platform_invoices', 'platform_licenses', 'platform_signups', 'platform_demo', 'platform_marketplace',
            'platform_screens', 'platform_content', 'qr_setup',
            'platform_reports', 'push', 'platform_audit', 'platform_support',
            'platform_settings', 'update'],
        'reseller' => ['reseller_overview', 'reseller', 'platform_screens', 'reseller_plans', 'reseller_invoices', 'reseller_support',
            'platform_demo', 'platform_chains', 'qr_setup', 'push'],
        // §34 client menu (Dashboard, Screens, Locations / groups, Content, Playlists, Schedule, …, Users, Settings, Subscription):
        // already the nav.d file order, so modules keep their ['after' => …] positions — no explicit order here.
    ];

    /** Sidebar headings per panel: heading label key => nav keys. */
    public const GROUPS = [
        'platform' => [
            'Manage' => ['platform_overview', 'platform_hotels', 'platform_resellers', 'platform_chains'],
            'Plans & subscriptions' => ['platform_plans', 'platform_invoices', 'platform_licenses', 'platform_signups', 'platform_demo', 'platform_marketplace'],
            'Devices & content' => ['platform_screens', 'platform_content', 'qr_setup'],
            'Insight' => ['platform_reports', 'push', 'platform_audit', 'platform_support'],
            'System' => ['platform_settings', 'update'],
        ],
        'reseller' => [
            'Manage' => ['reseller_overview', 'reseller', 'platform_screens', 'reseller_support'],
            'Commercial' => ['reseller_plans', 'reseller_invoices', 'platform_demo', 'platform_chains'],
            'Tools' => ['qr_setup', 'push'],
        ],
    ];

    /** Test hook: force a panel (null = detect). Never set in production code. */
    public static ?string $override = null;

    public static function current(): string
    {
        if (self::$override !== null && in_array(self::$override, self::PANELS, true)) {
            return self::$override;
        }
        $role = Auth::role();
        if ($role === '') {
            return 'customer';
        }
        if (Auth::inEnteredHotel() && !self::consolePage()) {
            return 'customer';
        }
        return match ($role) {
            'platform_admin' => 'platform',
            'reseller' => 'reseller',
            'chain_admin' => 'chain',
            default => 'customer',
        };
    }

    public static function is(string $panel): bool
    {
        return self::current() === $panel;
    }

    /** True when a platform admin / reseller / chain admin works inside a customer they opened. */
    public static function impersonating(): bool
    {
        return Auth::inEnteredHotel() && Tenant::has() && !self::consolePage();
    }

    /**
     * 2.6.1: console-only pages (Super Admin / reseller: platform_*.php, reseller*.php, update.php) always
     * render in the console, also while a customer workspace is open — never with the customer's name,
     * banner, plan notice or footer. $script: file name (default: the current request).
     */
    public static function consolePage(?string $script = null): bool
    {
        $script ??= basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $role = Auth::role();
        if (!in_array($role, Auth::PLATFORM_ROLES, true)) {
            return false;
        }
        return $script === 'update.php' || str_starts_with($script, 'platform_') || str_starts_with($script, 'reseller');
    }

    /** Role word for badges / the impersonation banner. */
    public static function actorLabel(): string
    {
        return match (Auth::role()) {
            'platform_admin' => __('Super Admin'),
            'reseller' => __('Reseller'),
            'chain_admin' => __('Chain Admin'),
            default => Auth::roleName(),
        };
    }

    /** Theme of a panel: css key, display name, icon, accent colours. */
    public static function theme(?string $panel = null): array
    {
        $panel ??= self::current();
        return match ($panel) {
            'platform' => ['key' => 'platform', 'name' => __('Super Admin console'), 'badge' => __('Super Admin'), 'icon' => 'bi-shield-lock-fill', 'accent' => '#4f46e5', 'accent_dark' => '#3730a3'],
            'reseller' => ['key' => 'reseller', 'name' => __('Reseller panel'), 'badge' => __('Reseller'), 'icon' => 'bi-briefcase-fill', 'accent' => '#0d9488', 'accent_dark' => '#0f766e'],
            'chain' => ['key' => 'chain', 'name' => __('Chain dashboard'), 'badge' => __('Chain'), 'icon' => 'bi-diagram-3-fill', 'accent' => '#475569', 'accent_dark' => '#334155'],
            default => ['key' => 'customer', 'name' => __('Customer workspace'), 'badge' => '', 'icon' => 'bi-tv', 'accent' => null, 'accent_dark' => null],
        };
    }

    /** Home page of a panel (file in admin/). */
    public static function home(?string $panel = null): string
    {
        $panel ??= self::current();
        return match ($panel) {
            'platform' => is_file(HC_ROOT . '/admin/platform_overview.php') ? 'platform_overview.php' : 'platform_hotels.php',
            'reseller' => is_file(HC_ROOT . '/admin/reseller_overview.php') ? 'reseller_overview.php' : 'reseller.php',
            'chain' => Chains::enabled() ? 'chain.php' : 'profile.php',
            default => 'index.php',
        };
    }

    /** Does the current user have a console / panel of their own to go back to (besides the customer workspace)? */
    public static function consoleOf(?array $user = null): ?string
    {
        $role = $user['role'] ?? Auth::role();
        return match ($role) {
            'platform_admin' => 'platform',
            'reseller' => 'reseller',
            'chain_admin' => 'chain',
            default => null,
        };
    }

    /**
     * Sidebar of the current panel: [section => [key => [key, file, perm, icon, label]]] from hc_nav_sections(),
     * limited to the panel's sections and ordered by self::ORDER.
     */
    public static function navSections(?array $all = null): array
    {
        $all ??= function_exists('hc_nav_sections') ? hc_nav_sections() : [];
        $panel = self::current();
        $out = [];
        foreach (self::SECTIONS[$panel] ?? [] as $section) {
            if (empty($all[$section])) {
                continue;
            }
            $items = $all[$section];
            $order = self::ORDER[$panel] ?? [];
            if ($order) {
                uksort($items, static function (string $a, string $b) use ($order, $items): int {
                    $ia = array_search($a, $order, true);
                    $ib = array_search($b, $order, true);
                    $ia = $ia === false ? PHP_INT_MAX : $ia;
                    $ib = $ib === false ? PHP_INT_MAX : $ib;
                    return $ia <=> $ib ?: array_search($a, array_keys($items), true) <=> array_search($b, array_keys($items), true);
                });
            }
            $out[$section] = $items;
        }
        return $out;
    }

    /** Sidebar groups for the current panel: [heading => [key => item]] (items without a group under ''). */
    public static function navGroups(array $items): array
    {
        $groups = self::GROUPS[self::current()] ?? [];
        if (!$groups) {
            return ['' => $items];
        }
        $out = [];
        $seen = [];
        foreach ($groups as $heading => $keys) {
            foreach ($keys as $k) {
                if (isset($items[$k])) {
                    $out[$heading][$k] = $items[$k];
                    $seen[$k] = true;
                }
            }
        }
        foreach ($items as $k => $item) {
            if (!isset($seen[$k])) {
                $out[''][$k] = $item;
            }
        }
        return $out;
    }

    /** Subscription state of a customer (docs/SPEC_SAAS.md §29): TRIAL | ACTIVE | EXPIRING | EXPIRED | SUSPENDED | ARCHIVED. */
    public static function subscriptionState(?int $hotelId = null): array
    {
        $h = Tenant::hotel($hotelId);
        if (!$h) {
            return ['key' => 'SUSPENDED', 'label' => __('Suspended'), 'tone' => 'danger', 'days' => null];
        }
        $days = !empty($h['expires_at']) ? (int) ceil((strtotime((string) $h['expires_at']) - time()) / 86400) : null;
        if (!empty($h['archived_at'])) {
            return ['key' => 'ARCHIVED', 'label' => __('Archived'), 'tone' => 'secondary', 'days' => $days];
        }
        $state = Tenant::state($hotelId);
        if ($state === 'suspended') {
            return ['key' => 'SUSPENDED', 'label' => __('Suspended'), 'tone' => 'danger', 'days' => $days];
        }
        if ($state === 'expired') {
            return ['key' => 'EXPIRED', 'label' => __('Expired'), 'tone' => 'danger', 'days' => $days];
        }
        if (!empty($h['is_trial'])) {
            return ['key' => 'TRIAL', 'label' => __('Trial'), 'tone' => 'info', 'days' => $days];
        }
        if ($days !== null && $days <= 15) {
            return ['key' => 'EXPIRING', 'label' => __('Expiring soon'), 'tone' => 'warning', 'days' => $days];
        }
        return ['key' => 'ACTIVE', 'label' => __('Active'), 'tone' => 'success', 'days' => $days];
    }

    /** Flat list of nav keys visible in the current panel (tests, header). */
    public static function navKeys(): array
    {
        $keys = [];
        foreach (self::navSections() as $items) {
            $keys = array_merge($keys, array_keys($items));
        }
        return $keys;
    }
}
