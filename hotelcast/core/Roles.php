<?php
declare(strict_types=1);

/**
 * Custom roles / RBAC (docs/modules/roles.md).
 *
 * What a USER may do inside their customer account. Effective permission = role allows (this class /
 * Auth::can) AND the plan includes the feature (core/Features.php, Auth::planAllows). Per-user screen
 * access (core/Access.php) then limits WHICH screens.
 *
 *  - Built-in roles (Admin = super_admin, Manager, Staff, Reception) are read-only definitions computed
 *    from the permission registry in core/Auth.php (Auth::roleCan), never stored copies — permissions of
 *    new modules keep working automatically.
 *  - Custom roles live in the tenant table `roles` (permissions = JSON list). A user with users.role_id
 *    uses that list; users.role keeps the role's base_level (manager / staff / reception) for code that
 *    still compares levels (e.g. Access: such users can always be limited to some screens).
 *
 * Role "specs" used by forms: 'super_admin' | 'manager' | 'staff' | 'reception' | 'role:<id>'.
 */
final class Roles
{
    public const BUILT_IN = ['super_admin', 'manager', 'staff', 'reception'];
    /** Possible base levels of a custom role, lowest first. Never super_admin. */
    public const BASE_LEVELS = ['reception', 'staff', 'manager'];
    public const MAX_NAME = 80;
    public const MAX_DESCRIPTION = 255;

    /** Permissions only an Admin may put into a custom role. */
    public const ADMIN_ONLY = ['roles.manage'];

    /**
     * Fallback groups (used when Features::all() does not list a permission), in display order.
     * key => [label, description]
     */
    private static function groups(): array
    {
        return [
            'overview' => [__('Overview'), __('Dashboard and getting started.')],
            'screens' => [__('Screens'), __('Screens & TVs, groups, video walls and TV tools.')],
            'content' => [__('Content'), __('Content library, playlists, designs and templates.')],
            'apps' => [__('Apps & widgets'), __('Notice board, menu board, tickers and other display apps.')],
            'broadcast' => [__('Broadcast'), __('Send content, messages, emergencies and commands to screens.')],
            'schedule' => [__('Scheduling'), __('Schedules, TV power, holidays and device schedules.')],
            'guests' => [__('Guests'), __('Front desk, orders, requests and feedback.')],
            'ads' => [__('Advertising'), __('Ads, sponsors and the ad marketplace.')],
            'reports' => [__('Reports'), __('Analytics, proof of play and logs.')],
            'admin' => [__('Administration'), __('Users, roles, settings and billing.')],
            'other' => [__('Other'), __('Permissions of other modules.')],
        ];
    }

    /**
     * Friendly label / description / kind (view | manage | action) and fallback group per permission.
     * Unknown permissions (new modules) get a label made from their key, group "other".
     *
     * @return array<string, array{0:string,1:string,2:string,3:string}> perm => [group, kind, label, description]
     */
    private static function meta(): array
    {
        return [
            'dashboard.view' => ['overview', 'view', __('Dashboard'), __('See the dashboard with screen status and recent activity.')],
            'rooms.view' => ['screens', 'view', __('View screens'), __('See the screens list and the status of each TV.')],
            'rooms.manage' => ['screens', 'manage', __('Manage screens'), __('Add, edit and delete screens and pair new TVs.')],
            'groups.manage' => ['screens', 'manage', __('Manage groups'), __('Create and edit groups of screens.')],
            'video_walls.manage' => ['screens', 'manage', __('Manage video walls'), __('Combine several TVs into one video wall.')],
            'devices.controls' => ['screens', 'manage', __('TV controls'), __('Volume rules, guest menu and remote commands for TVs.')],
            'devices.setup' => ['screens', 'manage', __('TV setup files'), __('Download setup files for new TVs.')],
            'support.view' => ['screens', 'view', __('TV support'), __('Screenshots, logs and live view of a TV.')],
            'tv_health.view' => ['screens', 'view', __('TV health'), __('Health dashboard of the TVs.')],
            'apk.manage' => ['screens', 'manage', __('TV app updates'), __('Upload the TV app and push updates to TVs.')],
            'content.view' => ['content', 'view', __('View content library'), __('See and preview content items.')],
            'content.manage' => ['content', 'manage', __('Manage content'), __('Upload, edit, design and delete content and apps.')],
            'content.submit' => ['content', 'action', __('Submit content for approval'), __('Add content that waits for approval.')],
            'content.approve' => ['content', 'manage', __('Approve content'), __('Approve or reject submitted content.')],
            'playlists.manage' => ['content', 'manage', __('Manage playlists'), __('Create and edit playlists.')],
            'templates.manage' => ['content', 'manage', __('Templates'), __('Screen templates and themes.')],
            'albums.manage' => ['apps', 'manage', __('Photo albums'), __('Photo albums and guest photo uploads.')],
            'notices.manage' => ['apps', 'manage', __('Notice board'), __('Notices shown on the notice board app.')],
            'tickers.manage' => ['apps', 'manage', __('Ticker bar'), __('Scrolling ticker messages.')],
            'menu_board.manage' => ['apps', 'manage', __('Menu board'), __('Restaurant menu and sold-out switch.')],
            'queue.operate' => ['apps', 'action', __('Operate token queue'), __('Call and issue tokens.')],
            'queue.manage' => ['apps', 'manage', __('Set up token queue'), __('Counters and queue settings.')],
            'offers.manage' => ['apps', 'manage', __('Offers'), __('Offers and promotions app.')],
            'class_schedule.manage' => ['apps', 'manage', __('Class schedule'), __('Class / session timetable app.')],
            'departures.manage' => ['apps', 'manage', __('Departures board'), __('Departures and arrivals board.')],
            'kpi.manage' => ['apps', 'manage', __('KPI dashboard'), __('KPI numbers shown on screens.')],
            'festivals.manage' => ['apps', 'manage', __('Festivals'), __('Festival calendar widget.')],
            'celebrations.manage' => ['apps', 'manage', __('Birthdays & anniversaries'), __('Birthday and anniversary wall.')],
            'rates.manage' => ['apps', 'manage', __('Rates'), __('Gold, silver and market rates.')],
            'broadcast.send' => ['broadcast', 'action', __('Send to screens'), __('Push content and messages to screens now.')],
            'broadcast.emergency' => ['broadcast', 'action', __('Emergency messages'), __('Start and stop emergency messages.')],
            'broadcast.device_commands' => ['broadcast', 'action', __('TV commands'), __('Restart, power and other commands to TVs.')],
            'announce.send' => ['broadcast', 'action', __('Announcements'), __('Play announcements and bells now.')],
            'schedule.manage' => ['schedule', 'manage', __('Schedules & TV power'), __('Schedules, calendar and TV power times.')],
            'holidays.manage' => ['schedule', 'manage', __('Holidays'), __('Holiday dates (TVs off or special content).')],
            'device_schedules.manage' => ['schedule', 'manage', __('Device schedules'), __('Timed volume, input, restart and bells.')],
            'guests.manage' => ['guests', 'manage', __('Front desk'), __('Check-in, check-out and guest messages.')],
            'services.manage' => ['guests', 'manage', __('Orders & requests'), __('Guest orders and service requests.')],
            'guests.setup' => ['guests', 'manage', __('Guest services setup'), __('Menus, services and guest portal settings.')],
            'guests.feedback' => ['guests', 'view', __('Guest feedback'), __('Read guest feedback and ratings.')],
            'ads.manage' => ['ads', 'manage', __('Ads & sponsors'), __('Ad campaigns, sponsors and sponsor reports.')],
            'marketplace.manage' => ['ads', 'manage', __('Ad marketplace'), __('Sell and accept ads in the marketplace.')],
            'marketplace.settings' => ['ads', 'manage', __('Ad marketplace settings'), __('Prices and payout settings of the marketplace.')],
            'analytics.view' => ['reports', 'view', __('Analytics'), __('Play and screen statistics.')],
            'play_report.view' => ['reports', 'view', __('Proof of play'), __('Proof of play reports.')],
            'logs.view' => ['reports', 'view', __('Logs & history'), __('Activity, broadcast and TV logs.')],
            'users.manage' => ['admin', 'manage', __('Manage users'), __('Add, edit and delete users and their screen access.')],
            'roles.manage' => ['admin', 'manage', __('Manage roles'), __('Create and edit custom roles. Only an Admin can give this.')],
            'settings.manage' => ['admin', 'manage', __('Settings'), __('Customer settings, data feeds and API keys.')],
            'billing.view' => ['admin', 'view', __('Billing'), __('Plan, invoices and payments.')],
        ];
    }

    /** Is the roles table there (migration 029)? Cached per request. */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                DB::value('SELECT COUNT(*) FROM roles WHERE 1 = 0');
                $ok = true;
            } catch (Throwable) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Every hotel permission with its display info, in registry order.
     *
     * @return array<string, array{key:string, group:string, kind:string, label:string, description:string, min_role:string}>
     */
    public static function catalog(): array
    {
        $meta = self::meta();
        $out = [];
        foreach (Auth::hotelPermissions() as $perm => $minRole) {
            [$group, $kind, $label, $desc] = $meta[$perm] ?? ['other', self::guessKind($perm), self::guessLabel($perm), ''];
            $out[$perm] = ['key' => $perm, 'group' => $group, 'kind' => $kind, 'label' => $label, 'description' => $desc, 'min_role' => $minRole];
        }
        return $out;
    }

    private static function guessKind(string $perm): string
    {
        $verb = substr((string) strrchr($perm, '.'), 1);
        return $verb === 'view' ? 'view' : ($verb === 'manage' ? 'manage' : 'action');
    }

    private static function guessLabel(string $perm): string
    {
        return ucfirst(str_replace(['_', '.'], [' ', ': '], $perm));
    }

    /** Label of a permission kind. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'view' => __('View'),
            'manage' => __('Manage'),
            default => __('Action'),
        };
    }

    /**
     * Does the customer's plan include $perm? Like Auth::planAllows(), but without the platform admin's
     * bypass (core/Features.php): the matrix shows the CUSTOMER's plan even to a platform admin.
     */
    public static function planIncludes(string $perm): bool
    {
        if (Auth::$planOverride === null && Auth::role() === 'platform_admin' && Tenant::has() && class_exists('Features')
            && method_exists('Features', 'permissionOwners') && method_exists('Features', 'anyEnabled')) {
            try {
                $owners = Features::permissionOwners($perm);
                return !$owners || Features::anyEnabled($owners);
            } catch (Throwable) {
                return true;
            }
        }
        return Auth::planAllows($perm);
    }

    /** Is the custom roles feature in the plan (core/Features.php "custom_roles" owns roles.manage)? */
    public static function featureEnabled(): bool
    {
        return self::planIncludes('roles.manage');
    }

    /** Permissions the customer's plan includes (others are hidden in the matrix and always denied). */
    public static function visiblePermissions(): array
    {
        return array_values(array_filter(array_keys(self::catalog()), static fn ($p) => self::planIncludes($p)));
    }

    /**
     * The permission matrix: groups (by plan feature when core/Features.php lists the permission,
     * otherwise the fallback groups) with only the permissions the plan includes.
     *
     * @return array<string, array{label:string, description:string, perms: array<string, array>}>
     */
    public static function matrix(): array
    {
        $catalog = self::catalog();
        $visible = array_flip(self::visiblePermissions());
        $featureOf = [];
        $groups = [];
        foreach (self::features() as $fkey => $f) {
            $groups['f:' . $fkey] = ['label' => $f['label'], 'description' => $f['description'], 'perms' => []];
            foreach ($f['permissions'] as $p) {
                $featureOf[$p] ??= 'f:' . $fkey;
            }
        }
        foreach (self::groups() as $gkey => [$label, $desc]) {
            $groups[$gkey] = ['label' => $label, 'description' => $desc, 'perms' => []];
        }
        foreach ($catalog as $perm => $info) {
            if (!isset($visible[$perm])) {
                continue; // not in the plan: hidden, not just disabled
            }
            $g = $featureOf[$perm] ?? $info['group'];
            $groups[$g]['perms'][$perm] = $info;
        }
        return array_filter($groups, static fn ($g) => $g['perms'] !== []);
    }

    /**
     * Plan features from core/Features.php (parallel module), normalised to
     * [key => ['label', 'description', 'permissions' => string[]]]. [] while Features is not available.
     */
    private static function features(): array
    {
        if (!class_exists('Features') || !method_exists('Features', 'all')) {
            return [];
        }
        try {
            $all = (array) Features::all();
        } catch (Throwable) {
            return [];
        }
        $out = [];
        foreach ($all as $key => $f) {
            if (!is_array($f)) {
                continue;
            }
            $key = (string) ($f['key'] ?? $key);
            $perms = array_values(array_filter((array) ($f['permissions'] ?? []), 'is_string'));
            if (!$perms) {
                continue;
            }
            $label = (string) ($f['label'] ?? $f['name'] ?? $f['title'] ?? $key);
            $desc = (string) ($f['description'] ?? '');
            $out[$key] = [
                'label' => $label !== '' ? __($label) : $key,
                'description' => $desc !== '' ? __($desc) : '',
                'permissions' => $perms,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ built-in roles

    /** Default permissions of a built-in role, computed from the registry (not limited by the plan). */
    public static function builtInPermissions(string $role): array
    {
        if (!in_array($role, self::BUILT_IN, true)) {
            return [];
        }
        return array_values(array_filter(array_keys(Auth::hotelPermissions()), static fn ($p) => Auth::roleCan($role, $p)));
    }

    /** Read-only definitions of the built-in roles: key => [key, name, description, permissions, is_system]. */
    public static function builtIn(): array
    {
        $desc = [
            'super_admin' => __('everything of this customer, including users, settings and billing.'),
            'manager' => __('screens, content, playlists, schedules, TV commands, APK, logs.'),
            'staff' => __('view screens and content, send content and emergency messages.'),
            'reception' => __('front desk: guests check-in/out, service orders and requests, view screens.'),
        ];
        $out = [];
        foreach (self::BUILT_IN as $r) {
            $out[$r] = ['key' => $r, 'id' => 0, 'name' => role_label($r), 'description' => $desc[$r], 'permissions' => self::builtInPermissions($r), 'is_system' => true];
        }
        return $out;
    }

    // ------------------------------------------------------------------ custom roles (current hotel)

    private static function decode(array $row): array
    {
        $list = json_decode((string) ($row['permissions'] ?? '[]'), true);
        $row['permissions'] = is_array($list) ? array_values(array_unique(array_filter($list, 'is_string'))) : [];
        $row['is_system'] = false;
        $row['key'] = 'role:' . (int) $row['id'];
        return $row;
    }

    /** Custom roles of the current hotel (with user counts), by name. */
    public static function all(): array
    {
        if (!self::available()) {
            return [];
        }
        $rows = DB::all(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.hotel_id = r.hotel_id) AS user_count
             FROM roles r WHERE r.hotel_id = :h ORDER BY r.name',
            ['h' => Tenant::id()]
        );
        return array_map([self::class, 'decode'], $rows);
    }

    /** A custom role of the current hotel; null when missing; another hotel's id → 404 (Tenant::deny). */
    public static function find(int $id): ?array
    {
        if ($id <= 0 || !self::available()) {
            return null;
        }
        $row = Tenant::find('roles', $id);
        return $row ? self::decode($row) : null;
    }

    /** Users of the current hotel with a built-in role (role_id NULL) or a custom role, counted. */
    public static function userCounts(): array
    {
        $out = array_fill_keys(self::BUILT_IN, 0);
        $hasCol = self::available();
        $rows = DB::all(
            'SELECT role, ' . ($hasCol ? 'role_id' : 'NULL AS role_id') . ', COUNT(*) AS n FROM users WHERE hotel_id = :h AND role IN (\'super_admin\',\'manager\',\'staff\',\'reception\') GROUP BY role, role_id',
            ['h' => Tenant::id()]
        );
        foreach ($rows as $r) {
            $key = $r['role_id'] !== null ? 'role:' . (int) $r['role_id'] : (string) $r['role'];
            $out[$key] = ($out[$key] ?? 0) + (int) $r['n'];
        }
        return $out;
    }

    /** Users of a custom role in the current hotel. */
    public static function usersOf(int $roleId): array
    {
        return DB::all('SELECT id, username, full_name, role, is_active FROM users WHERE hotel_id = :h AND role_id = :r ORDER BY username', ['h' => Tenant::id(), 'r' => $roleId]);
    }

    /** Lowest built-in level whose default permissions cover $perms (manager when none does). */
    public static function baseLevel(array $perms): string
    {
        foreach (self::BASE_LEVELS as $lvl) {
            if (!array_diff($perms, self::builtInPermissions($lvl))) {
                return $lvl;
            }
        }
        return 'manager';
    }

    // ------------------------------------------------------------------ specs / escalation rules

    /** Spec of a user's role: 'role:<id>' for a custom role, else the built-in key. */
    public static function specOf(array $user): string
    {
        $rid = Auth::customRoleId($user);
        return $rid !== null ? 'role:' . $rid : (string) ($user['role'] ?? '');
    }

    /** Parse a spec from a form: ['manager', null] / ['staff', 12] (base level + role id), or null when invalid / foreign. */
    public static function parseSpec(string $spec): ?array
    {
        if (in_array($spec, self::BUILT_IN, true)) {
            return [$spec, null];
        }
        if (preg_match('/^role:(\d{1,9})$/', $spec, $m)) {
            $role = self::find((int) $m[1]); // another hotel's id → 404
            return $role ? [(string) $role['base_level'], (int) $role['id']] : null;
        }
        return null;
    }

    /** Permissions a spec grants (custom list, or the built-in defaults). */
    public static function permissionsOfSpec(string $spec): array
    {
        if (in_array($spec, self::BUILT_IN, true)) {
            return self::builtInPermissions($spec);
        }
        if (preg_match('/^role:(\d{1,9})$/', $spec, $m)) {
            $role = self::find((int) $m[1]);
            return $role ? $role['permissions'] : [];
        }
        return [];
    }

    /** Hotel permissions the current user holds (role AND plan). */
    public static function editorPermissions(): array
    {
        return array_values(array_filter(array_keys(Auth::hotelPermissions()), static fn ($p) => Auth::can($p)));
    }

    /**
     * May the current user give this role to someone (or manage a user who has it)?
     * Admin: every role. Others: never the Admin role, and only roles whose permissions they hold
     * themselves (only those the plan includes count — the rest is denied anyway).
     */
    public static function canAssign(string $spec): bool
    {
        if (Auth::isAdmin()) {
            return true;
        }
        if ($spec === 'super_admin') {
            return false;
        }
        $mine = self::editorPermissions();
        $needed = array_filter(self::permissionsOfSpec($spec), static fn ($p) => self::planIncludes($p));
        return !array_diff($needed, $mine);
    }

    /**
     * Final permission list of a role being saved by the current user:
     *  - only known hotel permissions;
     *  - permissions the plan hides keep their stored state (they come back with a bigger plan);
     *  - a non-Admin can only add / remove permissions they hold themselves; the others keep their state;
     *  - roles.manage (ADMIN_ONLY) can be changed only by an Admin.
     * Returns [permissions, notes] (notes = permissions that were kept unchanged, for a message).
     */
    public static function sanitize(array $requested, array $existing = []): array
    {
        $known = array_keys(Auth::hotelPermissions());
        $requested = array_values(array_intersect($known, array_filter($requested, 'is_string')));
        $existing = array_values(array_intersect($known, $existing));
        $isAdmin = Auth::isAdmin();
        $mine = $isAdmin ? $known : self::editorPermissions();
        $final = [];
        $kept = [];
        foreach ($known as $p) {
            $was = in_array($p, $existing, true);
            $want = in_array($p, $requested, true);
            $inPlan = self::planIncludes($p);
            $locked = !$inPlan
                || (!$isAdmin && (!in_array($p, $mine, true) || in_array($p, self::ADMIN_ONLY, true)));
            $on = $locked ? $was : $want;
            if ($locked && $want !== $was && $inPlan) {
                $kept[] = $p;
            }
            if ($on) {
                $final[] = $p;
            }
        }
        return [$final, $kept];
    }

    /** Validate name / description. Returns [data, errors]. */
    public static function validate(array $in, ?int $id = null): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')) ?? '');
        $desc = trim((string) ($in['description'] ?? ''));
        $errors = [];
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            $errors[] = __('Role name: 1–80 characters.');
        } elseif (in_array(mb_strtolower($name), array_map(static fn ($r) => mb_strtolower(role_label($r)), self::BUILT_IN), true)
            || in_array(mb_strtolower($name), self::BUILT_IN, true)) {
            $errors[] = __('This name is used by a built-in role. Choose another name.');
        } elseif (DB::value('SELECT id FROM roles WHERE hotel_id = :h AND name = :n AND id <> :id', ['h' => Tenant::id(), 'n' => $name, 'id' => (int) $id])) {
            $errors[] = __('A role with this name already exists.');
        }
        if (mb_strlen($desc) > self::MAX_DESCRIPTION) {
            $desc = mb_substr($desc, 0, self::MAX_DESCRIPTION);
        }
        return [['name' => $name, 'description' => $desc], $errors];
    }

    /** Insert / update a custom role of the current hotel. Users of the role get its new base level. */
    public static function save(array $data, array $perms, ?int $id = null): int
    {
        sort($perms);
        $row = [
            'name' => $data['name'],
            'description' => $data['description'],
            'permissions' => json_encode(array_values($perms)),
            'base_level' => self::baseLevel($perms),
            'is_system' => 0,
        ];
        if ($id) {
            DB::update('roles', $row, 'id = :id', ['id' => $id]);
            DB::query('UPDATE users SET role = :b WHERE hotel_id = :h AND role_id = :r', ['b' => $row['base_level'], 'h' => Tenant::id(), 'r' => $id]);
        } else {
            $id = DB::insert('roles', $row + ['created_by' => Auth::id(), 'created_at' => now()]);
        }
        Auth::forgetPermissions();
        return $id;
    }

    /**
     * Delete a custom role. Its users must be moved first: $reassign = spec of the new role (or null when
     * the role has no users). Returns the number of users moved.
     */
    public static function delete(array $role, ?string $reassign = null): int
    {
        $users = self::usersOf((int) $role['id']);
        $moved = 0;
        if ($users) {
            $target = $reassign !== null ? self::parseSpec($reassign) : null;
            if (!$target || $target[1] === (int) $role['id']) {
                throw new InvalidArgumentException(__('This role still has users. Choose the role they get instead.'));
            }
            [$base, $rid] = $target;
            foreach ($users as $u) {
                DB::query('UPDATE users SET role = :b, role_id = :r WHERE id = :id AND hotel_id = :h', ['b' => $base, 'r' => $rid, 'id' => $u['id'], 'h' => Tenant::id()]);
                if ($base === 'super_admin') {
                    DB::query('DELETE FROM user_access WHERE user_id = :u AND hotel_id = :h', ['u' => $u['id'], 'h' => Tenant::id()]);
                }
                $moved++;
            }
            Access::forget();
        }
        DB::delete('roles', 'id = :id', ['id' => (int) $role['id']]);
        Auth::forgetPermissions();
        return $moved;
    }

    /** Name of a spec: built-in label or the custom role's name. */
    public static function specLabel(string $spec): string
    {
        if (in_array($spec, self::BUILT_IN, true)) {
            return role_label($spec);
        }
        if (preg_match('/^role:(\d{1,9})$/', $spec, $m)) {
            $r = self::find((int) $m[1]);
            return $r ? (string) $r['name'] : __('Unknown role');
        }
        return role_label($spec);
    }

    /**
     * Short "This role can …" summary: per group the labels of the granted permissions the plan includes.
     *
     * @return array<int, array{group:string, items:string[]}>
     */
    public static function summary(array $perms): array
    {
        $out = [];
        foreach (self::matrix() as $g) {
            $items = [];
            foreach ($g['perms'] as $p => $info) {
                if (in_array($p, $perms, true)) {
                    $items[] = $info['label'];
                }
            }
            if ($items) {
                $out[] = ['group' => $g['label'], 'items' => $items];
            }
        }
        return $out;
    }
}
