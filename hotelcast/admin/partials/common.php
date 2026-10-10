<?php
declare(strict_types=1);

/**
 * Shared helpers for admin pages (loaded by header.php / pages / ajax.php).
 * Every helper here only reads data or renders escaped HTML — permission checks
 * stay in the pages themselves.
 */

require_once __DIR__ . '/flash.php';

if (defined('HC_ADMIN_COMMON')) {
    return;
}
define('HC_ADMIN_COMMON', true);

/**
 * Sidebar items from admin/partials/nav.d/*.php grouped by section and filtered for the current
 * user (permission, hotel context, SaaS-only flag, page file present). Later files override
 * earlier items with the same key.
 */
function hc_nav_sections(): array
{
    $sections = ['hotel' => [], 'reseller' => [], 'platform' => []];
    $files = glob(__DIR__ . '/nav.d/*.php') ?: [];
    sort($files);
    foreach ($files as $f) {
        $items = require $f;
        foreach ((array) $items as $item) {
            if (!is_array($item) || count($item) < 5) {
                continue;
            }
            [$key, $file, $perm, $icon, $label] = $item;
            $section = $item[5] ?? 'hotel';
            $flags = (array) ($item[6] ?? []);
            if (!empty($flags['saas']) && License::mode() !== 'saas') {
                continue;
            }
            if ($section === 'hotel' && !Tenant::has()) {
                continue;
            }
            if (!Auth::can((string) $perm) || !is_file(HC_ROOT . '/admin/' . $file)) {
                continue;
            }
            // 2.5 plans: pages of features outside the customer's plan are hidden (also for the platform admin).
            if ($section === 'hotel' && !Features::pageVisible((string) $file)) {
                continue;
            }
            $sections[$section] ??= [];
            $entry = [$key, $file, $perm, $icon, $label];
            $after = (string) ($flags['after'] ?? '');
            if ($after !== '' && isset($sections[$section][$after]) && !isset($sections[$section][$key])) {
                // Optional ['after' => 'broadcast']: place the item right after an earlier one.
                $pos = array_search($after, array_keys($sections[$section]), true) + 1;
                $sections[$section] = array_slice($sections[$section], 0, $pos, true) + [$key => $entry] + array_slice($sections[$section], $pos, null, true);
                continue;
            }
            $sections[$section][$key] = $entry;
        }
    }
    return array_filter($sections);
}

/** Send a JSON response and stop. */
function ajax_json(array $payload, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_out($payload);
    exit;
}

function ajax_ok(array $data = []): never
{
    ajax_json(['ok' => true, 'data' => (object) $data]);
}

function ajax_error(string $message, int $status = 400, string $code = 'ERROR'): never
{
    ajax_json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

/** Positive integer from GET/POST (0 when missing/invalid). */
function req_int(string $key, ?array $src = null): int
{
    $src ??= $_POST + $_GET;
    $v = $src[$key] ?? 0;
    if (is_int($v)) {
        return max(0, $v);
    }
    return is_string($v) && ctype_digit($v) && strlen($v) < 10 ? (int) $v : 0;
}

function req_str(string $key, ?array $src = null, int $max = 1000): string
{
    $src ??= $_POST + $_GET;
    $v = $src[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

/** Array of unique positive ints. */
function int_ids(mixed $v): array
{
    $out = [];
    foreach ((array) $v as $x) {
        if (is_int($x) || (is_string($x) && ctype_digit($x) && strlen($x) < 10)) {
            $x = (int) $x;
            if ($x > 0) {
                $out[$x] = $x;
            }
        }
    }
    return array_values($out);
}

/** Current admin page URL with (merged) query parameters. */
function self_url(array $query = [], bool $merge = true): string
{
    // Path relative to /admin/ (supports pages in sub-folders).
    $script = (string) parse_url($_SERVER['SCRIPT_NAME'] ?? 'index.php', PHP_URL_PATH);
    $page = ($pos = strpos($script, '/admin/')) !== false ? substr($script, $pos + 7) : basename($script);
    $q = $merge ? array_merge($_GET, $query) : $query;
    $q = array_filter($q, fn ($v) => $v !== null && $v !== '');
    return admin_url($page, $q);
}

/** Shortcut: ['hid' => current hotel id] for scoped queries. */
function hid(): array
{
    return ['hid' => Tenant::id()];
}

/**
 * Rooms of the current hotel the user may see, natural-ish order. Users limited to some TVs
 * (core/Access.php) get only their rooms; hc_groups() / hc_floors() / hc_devices_by_room() likewise.
 */
function hc_rooms(): array
{
    static $rooms = [];
    if (!isset($rooms[Tenant::id()])) {
        [$acc, $ap] = Access::roomSql('id');
        $rooms[Tenant::id()] = DB::all('SELECT * FROM rooms WHERE hotel_id = :hid' . $acc . ' ORDER BY LENGTH(floor), floor, LENGTH(room_number), room_number', hid() + $ap);
    }
    return $rooms[Tenant::id()];
}

/** Groups of the current hotel (users limited to some TVs: only their assigned groups). */
function hc_groups(): array
{
    static $groups = [];
    return $groups[Tenant::id()] ??= array_values(array_filter(
        DB::all('SELECT * FROM room_groups WHERE hotel_id = :hid ORDER BY type, name', hid()),
        static fn ($g) => Access::canGroup((int) $g['id'])
    ));
}

/** Floors of the current hotel (users limited to some TVs: only floors whose rooms are all theirs). */
function hc_floors(): array
{
    static $floors = [];
    return $floors[Tenant::id()] ??= array_values(array_filter(
        array_map('strval', DB::column("SELECT DISTINCT floor FROM rooms WHERE hotel_id = :hid AND floor IS NOT NULL AND floor <> '' ORDER BY LENGTH(floor), floor", hid())),
        static fn ($f) => Access::canFloor($f)
    ));
}

function hc_content_list(bool $activeOnly = false): array
{
    return DB::all('SELECT id, title, type, is_active, approval_status FROM content_items WHERE hotel_id = :hid' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY title', hid());
}

function hc_playlist_list(): array
{
    return DB::all('SELECT p.id, p.name, (SELECT COUNT(*) FROM playlist_items i WHERE i.playlist_id = p.id) AS item_count FROM content_playlists p WHERE p.hotel_id = :hid ORDER BY p.name', hid());
}

/** "c:12" / "p:3" / "" for a content/playlist pair. */
function source_value(mixed $contentId, mixed $playlistId): string
{
    if ($playlistId) {
        return 'p:' . (int) $playlistId;
    }
    return $contentId ? 'c:' . (int) $contentId : '';
}

/** Parse "c:12" / "p:3" → [contentId|null, playlistId|null], verifying the row exists in this hotel (404 if another hotel's). */
function parse_source(mixed $value): array
{
    $value = is_string($value) ? $value : '';
    if (preg_match('/^c:(\d{1,9})$/', $value, $m) && ($c = Tenant::find('content_items', (int) $m[1]))) {
        // 2.4 approvals (#32): content waiting for approval cannot be assigned, pushed or scheduled.
        return ContentRules::approved($c) ? [(int) $m[1], null] : [null, null];
    }
    if (preg_match('/^p:(\d{1,9})$/', $value, $m) && Tenant::find('content_playlists', (int) $m[1])) {
        return [null, (int) $m[1]];
    }
    return [null, null];
}

/** Grouped <select> of content + playlists. */
function source_select(string $name, string $selected = '', ?string $noneLabel = null, array $attrs = []): string
{
    $attrHtml = '';
    foreach ($attrs + ['class' => 'form-select'] as $k => $v) {
        $attrHtml .= ' ' . e($k) . '="' . e($v) . '"';
    }
    $html = '<select name="' . e($name) . '"' . $attrHtml . '>';
    if ($noneLabel !== null) {
        $html .= '<option value="">' . e($noneLabel) . '</option>';
    }
    $playlists = hc_playlist_list();
    if ($playlists) {
        $html .= '<optgroup label="' . e(__('Playlists')) . '">';
        foreach ($playlists as $p) {
            $v = 'p:' . $p['id'];
            $html .= '<option value="' . e($v) . '"' . ($v === $selected ? ' selected' : '') . '>▶ ' . e($p['name']) . ' (' . (int) $p['item_count'] . ')</option>';
        }
        $html .= '</optgroup>';
    }
    $byType = [];
    foreach (hc_content_list() as $c) {
        $byType[$c['type']][] = $c;
    }
    foreach (ContentManager::TYPES as $type => $label) {
        if (empty($byType[$type])) {
            continue;
        }
        $html .= '<optgroup label="' . e(__($label)) . '">';
        foreach ($byType[$type] as $c) {
            $v = 'c:' . $c['id'];
            $approved = ($c['approval_status'] ?? 'approved') === 'approved'; // 2.4: pending content cannot be chosen
            $html .= '<option value="' . e($v) . '"' . ($v === $selected ? ' selected' : '') . ($approved ? '' : ' disabled') . '>' . e($c['title']) . ((int) $c['is_active'] ? '' : ' (' . e(__('inactive')) . ')') . ($approved ? '' : ' (' . e(__('waiting for approval')) . ')') . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html . '</select>';
}

/** Human label for a content/playlist assignment. */
function source_label(mixed $contentId, mixed $playlistId): string
{
    if ($playlistId) {
        $n = DB::value('SELECT name FROM content_playlists WHERE id = :id AND hotel_id = :hid', ['id' => (int) $playlistId] + hid());
        return $n !== null ? '▶ ' . $n : '-';
    }
    if ($contentId) {
        $t = DB::value('SELECT title FROM content_items WHERE id = :id AND hotel_id = :hid', ['id' => (int) $contentId] + hid());
        return $t !== null ? (string) $t : '-';
    }
    return '';
}

/**
 * Target chooser: All / Rooms / Groups / Floors, posting target_type + room_ids[] / group_ids[] / floors[]
 * (the names Broadcaster::parseTarget() reads).
 */
function target_picker(string $uid, array $opts = []): string
{
    $type = $opts['type'] ?? 'all';
    $ids = array_map('strval', (array) ($opts['ids'] ?? []));
    $rooms = hc_rooms();
    $groups = hc_groups();
    $floors = hc_floors();
    $types = ['all' => __('All screens'), 'rooms' => __('Selected screens'), 'groups' => __('Groups'), 'floors' => __('Floors')];
    if (Access::restricted()) {
        // Limited users never target the whole hotel; floors only when every room of the floor is theirs.
        unset($types['all']);
        if (!$floors) {
            unset($types['floors']);
        }
        if (!$groups) {
            unset($types['groups']);
        }
        if (!isset($types[$type])) {
            $type = 'rooms';
        }
    }

    $h = '<div class="target-picker" data-target-picker>';
    $h .= '<div class="btn-group flex-wrap mb-2" role="group">';
    foreach ($types as $t => $label) {
        $id = e($uid . '_t_' . $t);
        $h .= '<input type="radio" class="btn-check" name="target_type" value="' . e($t) . '" id="' . $id . '"' . ($type === $t ? ' checked' : '') . ' autocomplete="off">'
            . '<label class="btn btn-outline-primary btn-sm" for="' . $id . '">' . e($label) . '</label>';
    }
    $h .= '</div>';

    // Rooms
    $h .= '<div class="target-panel border rounded p-2" data-panel="rooms"' . ($type === 'rooms' ? '' : ' hidden') . '>';
    if (!$rooms) {
        $h .= '<div class="text-muted small">' . e(__('No screens yet.')) . '</div>';
    } else {
        $h .= '<div class="d-flex flex-wrap gap-1 mb-2 align-items-center">'
            . '<button type="button" class="btn btn-sm btn-light border" data-select="all">' . e(__('Select all')) . '</button>'
            . '<button type="button" class="btn btn-sm btn-light border" data-select="none">' . e(__('Clear')) . '</button>';
        foreach ($floors as $f) {
            $h .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-select-floor="' . e($f) . '">' . e(__('Floor')) . ' ' . e($f) . '</button>';
        }
        $h .= '</div><div class="room-check-grid">';
        foreach ($rooms as $r) {
            $id = e($uid . '_r_' . $r['id']);
            $checked = $type === 'rooms' && in_array((string) $r['id'], $ids, true);
            $h .= '<input type="checkbox" class="btn-check" name="room_ids[]" value="' . (int) $r['id'] . '" id="' . $id . '" data-floor="' . e((string) $r['floor']) . '"' . ($checked ? ' checked' : '') . ' autocomplete="off">'
                . '<label class="btn btn-sm btn-outline-secondary" for="' . $id . '">' . e($r['room_number']) . '</label>';
        }
        $h .= '</div>';
    }
    $h .= '</div>';

    // Groups
    $h .= '<div class="target-panel border rounded p-2" data-panel="groups"' . ($type === 'groups' ? '' : ' hidden') . '>';
    if (!$groups) {
        $h .= '<div class="text-muted small">' . e(__('No groups yet. Create groups on the Groups page.')) . '</div>';
    }
    foreach ($groups as $g) {
        $id = e($uid . '_g_' . $g['id']);
        $checked = $type === 'groups' && in_array((string) $g['id'], $ids, true);
        $h .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="group_ids[]" value="' . (int) $g['id'] . '" id="' . $id . '"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . e($g['name']) . '</label></div>';
    }
    $h .= '</div>';

    // Floors
    $h .= '<div class="target-panel border rounded p-2" data-panel="floors"' . ($type === 'floors' ? '' : ' hidden') . '>';
    if (!$floors) {
        $h .= '<div class="text-muted small">' . e(__('No area / floor set on screens yet.')) . '</div>';
    }
    foreach ($floors as $f) {
        $id = e($uid . '_f_' . md5($f));
        $checked = $type === 'floors' && in_array($f, $ids, true);
        $h .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="floors[]" value="' . e($f) . '" id="' . $id . '"' . ($checked ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . e(__('Floor')) . ' ' . e($f) . '</label></div>';
    }
    $h .= '</div></div>';
    return $h;
}

/** Non-revoked devices grouped by room id (best device first: online, then most recent ping). */
function hc_devices_by_room(): array
{
    $map = [];
    [$acc, $ap] = Access::roomSql('room_id');
    foreach (DB::all('SELECT * FROM devices WHERE hotel_id = :hid AND is_revoked = 0 AND room_id IS NOT NULL' . $acc . ' ORDER BY last_ping DESC', hid() + $ap) as $d) {
        $map[(int) $d['room_id']][] = $d;
    }
    foreach ($map as &$list) {
        usort($list, fn ($a, $b) => (int) DeviceManager::isOnline($b) <=> (int) DeviceManager::isOnline($a));
    }
    return $map;
}

/** online | offline | none for a list of room devices. */
function hc_room_status(array $devices): string
{
    if (!$devices) {
        return 'none';
    }
    return DeviceManager::isOnline($devices[0]) ? 'online' : 'offline';
}

function status_badge(string $status): string
{
    return match ($status) {
        'online' => '<span class="badge rounded-pill text-bg-success"><i class="bi bi-wifi"></i> ' . e(__('Online')) . '</span>',
        'offline' => '<span class="badge rounded-pill text-bg-danger"><i class="bi bi-wifi-off"></i> ' . e(__('Offline')) . '</span>',
        default => '<span class="badge rounded-pill text-bg-secondary"><i class="bi bi-dash-circle"></i> ' . e(__('No TV')) . '</span>',
    };
}

/** Live status rows for every room (dashboard grid / ajax room_status). */
function hc_room_status_list(): array
{
    $devices = hc_devices_by_room();
    $out = [];
    foreach (hc_rooms() as $room) {
        $list = $devices[(int) $room['id']] ?? [];
        $status = hc_room_status($list);
        try {
            $content = ContentResolver::forRoom($room);
            $showing = ContentResolver::describe($content);
            $mode = $content['mode'];
        } catch (Throwable $e) {
            Logger::error('room status build failed: ' . $e->getMessage(), ['room' => $room['id']]);
            $showing = '-';
            $mode = 'error';
        }
        // TV polls but reports its screen off → standby (switched off with the remote or by schedule).
        $standby = $status === 'online' && isset($list[0]['screen_on']) && !(int) $list[0]['screen_on'];
        if ($standby && $mode !== 'emergency') {
            $showing = __('TV in standby (screen off)');
        }
        $out[] = [
            'id' => (int) $room['id'],
            'standby' => $standby,
            'number' => (string) $room['room_number'],
            'name' => (string) ($room['name'] ?? ''),
            'floor' => (string) ($room['floor'] ?? ''),
            'enabled' => (bool) (int) $room['is_enabled'],
            'status' => $status,
            'status_label' => match ($status) { 'online' => __('Online'), 'offline' => __('Offline'), default => __('No TV') },
            'last_seen' => $list ? time_ago($list[0]['last_ping']) : __('never'),
            'devices' => count($list),
            'showing' => $showing,
            'mode' => $mode,
            'mode_label' => mode_label($mode),
        ];
    }
    return $out;
}

/** Active emergencies the user sees: all, or (limited users) those reaching at least one of their TVs. */
function hc_visible_emergencies(): array
{
    return array_values(array_filter(Broadcaster::activeEmergencies(), [Access::class, 'touchesBroadcast']));
}

/** Activity log rows for the dashboard (limited users: only their own actions). */
function hc_recent_activity(int $limit = 10): array
{
    $mine = Access::restricted() ? ' AND user_id = :me' : '';
    $p = hid() + ($mine ? ['me' => (int) Auth::id()] : []);
    return DB::all('SELECT * FROM activity_logs WHERE hotel_id = :hid' . ActivityLog::customerFilter() . $mine . ' ORDER BY id DESC LIMIT ' . max(1, $limit), $p);
}

function mode_label(string $mode): string
{
    return match ($mode) {
        'emergency' => __('Emergency'),
        'scheduled' => __('Scheduled'),
        'assigned' => __('Assigned'),
        'group' => __('Group'),
        'default' => __('Default'),
        'off' => __('Screen off'),
        'suspended' => __('Service paused'),
        'empty' => __('Welcome screen'),
        'wall' => __('Video wall'), // 2.4, core/VideoWalls.php
        default => '-',
    };
}

/** Dashboard counters. */
function hc_dashboard_stats(): array
{
    // Users limited to some TVs (core/Access.php): counts of their rooms / TVs only.
    [$accR, $apR] = Access::roomSql('id');
    [$accD, $apD] = Access::roomSql('room_id');
    [$accN, $apN] = Access::roomSql('r.id');
    $total = (int) DB::value('SELECT COUNT(*) FROM rooms WHERE hotel_id = :hid' . $accR, hid() + $apR);
    $devices = DB::all('SELECT status, last_ping FROM devices WHERE hotel_id = :hid AND is_revoked = 0 AND room_id IS NOT NULL' . $accD, hid() + $apD);
    $online = 0;
    foreach ($devices as $d) {
        if (DeviceManager::isOnline($d)) {
            $online++;
        }
    }
    $neverRegistered = (int) DB::value('SELECT COUNT(*) FROM rooms r WHERE r.hotel_id = :hid' . $accN . ' AND NOT EXISTS (SELECT 1 FROM devices d WHERE d.room_id = r.id AND d.is_revoked = 0)', hid() + $apN);
    $h = Tenant::id();
    $last = DB::value('SELECT GREATEST(
        COALESCE((SELECT MAX(updated_at) FROM content_items WHERE hotel_id = :h1), \'1970-01-01\'),
        COALESCE((SELECT MAX(updated_at) FROM content_playlists WHERE hotel_id = :h2), \'1970-01-01\'),
        COALESCE((SELECT MAX(created_at) FROM broadcast_commands WHERE hotel_id = :h3 AND command = \'SHOW_CONTENT\'), \'1970-01-01\'))', ['h1' => $h, 'h2' => $h, 'h3' => $h]);
    $last = ($last && !str_starts_with((string) $last, '1970')) ? (string) $last : null;
    $emergencies = hc_visible_emergencies();
    return [
        'rooms' => $total,
        'devices' => count($devices),
        'online' => $online,
        'offline' => count($devices) - $online,
        'never_registered' => $neverRegistered,
        'emergencies' => count($emergencies),
        'emergency_titles' => array_map(fn ($b) => ['id' => (int) $b['id'], 'title' => (string) $b['title']], $emergencies),
        'last_update' => $last,
        'last_update_ago' => $last ? time_ago($last) : __('never'),
    ];
}

/** Bootstrap pagination for the current page. */
function paginate(int $total, int $page, int $perPage, string $param = 'page'): string
{
    $pages = (int) max(1, ceil($total / $perPage));
    if ($pages <= 1) {
        return '';
    }
    $h = '<nav aria-label="' . e(__('Pages')) . '"><ul class="pagination pagination-sm flex-wrap mb-0">';
    $link = function (int $p, string $label, bool $disabled = false, bool $active = false) use ($param): string {
        $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
        return '<li class="' . $cls . '"><a class="page-link" href="' . e(self_url([$param => $p])) . '">' . $label . '</a></li>';
    };
    $h .= $link(max(1, $page - 1), '&laquo;', $page <= 1);
    $start = max(1, $page - 3);
    $end = min($pages, $page + 3);
    if ($start > 1) {
        $h .= $link(1, '1') . ($start > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
    }
    for ($p = $start; $p <= $end; $p++) {
        $h .= $link($p, (string) $p, false, $p === $page);
    }
    if ($end < $pages) {
        $h .= ($end < $pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $link($pages, (string) $pages);
    }
    $h .= $link(min($pages, $page + 1), '&raquo;', $page >= $pages);
    return $h . '</ul></nav>';
}

/** Stream a CSV download and stop. Values starting with = + - @ are prefixed (CSV injection). */
function csv_download(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Gujarati correctly
    fputcsv($out, $header);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static function ($v) {
            $v = (string) ($v ?? '');
            return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
        }, array_values($row)));
    }
    fclose($out);
    exit;
}

/** Day names 1=Mon..7=Sun. */
function day_names(bool $short = true): array
{
    return $short
        ? [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')]
        : [1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'), 5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday')];
}

/** Friendly description of a schedule row. */
function schedule_summary(array $b): string
{
    $fmt = fn (?string $d) => $d ? date('d M Y, h:i A', (int) strtotime($d)) : '';
    if ($b['mode'] === 'once') {
        return __('Once at :t', ['t' => $fmt($b['start_at'])]);
    }
    $parts = [];
    if ($b['start_at'] || $b['end_at']) {
        $parts[] = ($b['start_at'] ? $fmt($b['start_at']) : '…') . ' → ' . ($b['end_at'] ? $fmt($b['end_at']) : '…');
    }
    if ($b['daily_start'] && $b['daily_end']) {
        $parts[] = __('daily :a–:b', ['a' => date('h:i A', (int) strtotime($b['daily_start'])), 'b' => date('h:i A', (int) strtotime($b['daily_end']))]);
    }
    if ($b['repeat_days']) {
        $names = day_names();
        $days = array_map(fn ($d) => $names[(int) $d] ?? $d, explode(',', (string) $b['repeat_days']));
        $parts[] = count($days) === 7 ? __('every day') : implode(', ', $days);
    }
    return implode(' · ', $parts);
}

function broadcast_status_badge(string $status): string
{
    $cls = match ($status) {
        'active' => 'text-bg-success',
        'scheduled' => 'text-bg-primary',
        'completed' => 'text-bg-secondary',
        'cancelled' => 'text-bg-warning',
        default => 'text-bg-light',
    };
    $label = match ($status) {
        'active' => __('Active'),
        'scheduled' => __('Scheduled'),
        'completed' => __('Completed'),
        'cancelled' => __('Cancelled'),
        default => $status,
    };
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

function command_label(string $cmd): string
{
    return match ($cmd) {
        'SHOW_CONTENT' => __('Show content / refresh'),
        'EMERGENCY' => __('Emergency'),
        'REBOOT' => __('Reboot TV'),
        'CLEAR_CACHE' => __('Clear cache'),
        'UPDATE_APP' => __('Update app'),
        'SCREEN_OFF' => __('Screen off'),
        'SCREEN_ON' => __('Screen on'),
        'RELOAD' => __('Restart app'),
        'PING' => __('Ping (test)'),
        'SET_VOLUME' => __('Set volume'),
        'MUTE' => __('Mute'),
        'UNMUTE' => __('Unmute'),
        'SCREENSHOT' => __('Take screenshot'),
        'UPLOAD_LOGS' => __('Upload logs'),
        'OPEN_INPUT' => __('Switch TV input'),
        'SHOW_WELCOME' => __('Show welcome again'),
        'SHOW_MESSAGE' => __('Show message'),
        'SPEAK' => __('Spoken announcement'),
        'PLAY_SOUND' => __('Play sound'),
        'LIVE_VIEW' => __('Live view'), // 2.4 (core/LiveView.php)
        default => $cmd,
    };
}

function cmd_status_badge(?string $status): string
{
    if (!$status) {
        return '<span class="text-muted">-</span>';
    }
    $cls = match ($status) {
        'acked' => 'text-bg-success',
        'delivered' => 'text-bg-info',
        'pending' => 'text-bg-warning',
        'failed' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
    $label = match ($status) {
        'acked' => __('Done'),
        'delivered' => __('Delivered'),
        'pending' => __('Waiting'),
        'failed' => __('Failed'),
        'expired' => __('Expired'),
        default => $status,
    };
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/** Content object for the preview simulator (content_id | playlist_id | room_id). Null when not found. */
function hc_preview_object(int $contentId, int $playlistId, int $roomId): ?array
{
    if ($roomId) {
        $room = Tenant::find('rooms', $roomId);
        if ($room) {
            Access::requireRoom($roomId); // users limited to some TVs: 403 for other rooms
        }
        return $room ? ContentResolver::build($room) : null;
    }
    $base = [
        'mode' => 'preview',
        'screen_on' => true,
        'room' => ['id' => 0, 'number' => '', 'name' => __('Preview'), 'floor' => ''],
        'hotel' => ['id' => Tenant::id(), 'name' => (string) Settings::get('hotel_name', ''), 'logo_url' => media_url((string) Settings::get('hotel_logo', ''))],
        'playlist' => null,
        'items' => [],
        'overlay' => ContentResolver::overlay(),
        'emergency' => null,
        'branding' => Branding::forTv(),
    ];
    if ($playlistId) {
        $pl = ContentManager::findPlaylist($playlistId);
        if (!$pl) {
            return null;
        }
        foreach (ContentManager::playlistItems($playlistId) as $row) {
            $base['items'][] = ContentManager::toTvItem($row, $row['pli_duration'] !== null ? (int) $row['pli_duration'] : null);
        }
        $base['playlist'] = ['id' => (int) $pl['id'], 'name' => $pl['name'], 'transition' => $pl['transition'], 'loop' => true];
    } elseif ($contentId) {
        $item = ContentManager::find($contentId);
        if (!$item) {
            return null;
        }
        $tv = ContentManager::toTvItem($item);
        $tv['duration'] = 0;
        $base['items'] = [$tv];
    } else {
        return null;
    }
    // Hotel-wide ticker bar (room previews get the room's own via ContentResolver / TickerExtension).
    $base['overlay']['ticker'] = Tickers::forTarget('all');
    $base['hash'] = sha1(json_out($base));
    $base['generated_at'] = date('c');
    return $base;
}

/** JSON safe to embed inside <script type="application/json">. */
function json_embed(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';
}

/** True if the request was a POST. */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Abort a POST action without permission (server-side enforcement). */
function require_can(string $permission): void
{
    if (!Auth::can($permission)) {
        if (Auth::isAjax()) {
            ajax_error(__('You do not have permission for this action.'), 403, 'FORBIDDEN');
        }
        http_response_code(403);
        require __DIR__ . '/forbidden.php';
        exit;
    }
}

/** Detect POST bodies dropped because they exceeded post_max_size (CSRF would fail confusingly). */
function post_too_large(): bool
{
    return is_post() && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
}

/** Effective server upload limit in bytes. */
function upload_limit(): int
{
    $a = ini_bytes((string) ini_get('upload_max_filesize'));
    $b = ini_bytes((string) ini_get('post_max_size'));
    $lims = array_filter([$a, $b], fn ($x) => $x > 0);
    return $lims ? min($lims) : 0;
}

// 2.5 plans (core/Features.php): an admin page / ajax action of a feature outside the customer's plan
// answers 403 "Not included in your plan" before the page runs (platform admins pass).
Features::guardAdminRequest();

/**
 * 2.8 "Email an invite link" checkbox for user-creation forms (PasswordReset::invite()): ticked, the
 * password field may stay empty and the new user chooses the password from a 72 h link.
 */
function invite_checkbox(string $id, string $passwordFieldId, bool $checked = true): string
{
    return '<div class="form-check"><input class="form-check-input" type="checkbox" id="' . e($id) . '" name="send_invite" value="1"' . ($checked ? ' checked' : '')
        . ' data-invite-toggle="' . e($passwordFieldId) . '" onchange="var p=document.getElementById(this.getAttribute(\'data-invite-toggle\'));if(p){p.required=!this.checked;}">'
        . '<label class="form-check-label small" for="' . e($id) . '">' . e(__('Email an invite link — the user chooses the password (leave the password empty)')) . '</label></div>';
}
