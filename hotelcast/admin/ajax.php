<?php
declare(strict_types=1);
/**
 * Admin AJAX endpoint: admin/ajax.php?action=<name>
 * Session auth; every request must send X-Requested-With: XMLHttpRequest and every
 * non-GET request an X-CSRF-Token header. Responses: {ok:true,data:{}} / {ok:false,error:{code,message}}.
 */
require __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/partials/common.php';

if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    ajax_error('This endpoint only accepts AJAX requests.', 400, 'BAD_REQUEST');
}

$user = Auth::require();   // 401 JSON when not logged in
Csrf::check();             // 419 JSON on bad token for POST
// Release the session lock early: nothing below writes to $_SESSION except set_language.
$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
if ($action !== 'set_language') {
    session_write_close();
}

// ---------------------------------------------------------------------------
// Auto-update actions (update_check, update_run, rollback, …) live in ajax_update.php.
// ---------------------------------------------------------------------------
if (str_starts_with($action, 'update_') || $action === 'rollback') {
    if (!is_file(__DIR__ . '/ajax_update.php')) {
        ajax_error('Updater is not available.', 404, 'NOT_FOUND');
    }
    require __DIR__ . '/ajax_update.php';
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = $method === 'GET' ? $_GET : request_json();

$needPost = function () use ($method): void {
    if ($method !== 'POST') {
        ajax_error('POST required', 405, 'METHOD_NOT_ALLOWED');
    }
};

/** Target from JSON input: target_type + target_ids[] (or room_ids/group_ids/floors). */
$parseTarget = function (array $in): array {
    [$type, $ids] = Broadcaster::parseTarget($in);
    if ($type !== 'all' && !$ids) {
        ajax_error(__('Select at least one target.'), 422, 'VALIDATION_ERROR');
    }
    return [$type, $ids];
};

try {
    switch ($action) {
        case 'dashboard_stats':
            require_can('dashboard.view');
            ajax_ok(hc_dashboard_stats() + [
                'activity' => array_map(fn ($a) => [
                    'user' => $a['username'], 'action' => $a['action'], 'details' => $a['details'], 'ago' => time_ago($a['created_at']),
                ], DB::all('SELECT * FROM activity_logs ORDER BY id DESC LIMIT 10')),
            ]);

        case 'room_status':
            require_can('rooms.view');
            Scheduler::tick();
            ajax_ok(['rooms' => hc_room_status_list(), 'stats' => hc_dashboard_stats(), 'time' => date('c')]);

        case 'send_command':
            $needPost();
            $command = strtoupper((string) ($in['command'] ?? ''));
            if (!in_array($command, Broadcaster::DEVICE_COMMANDS, true) || $command === 'UPDATE_APP') {
                ajax_error(__('Unknown command.'), 422, 'VALIDATION_ERROR');
            }
            require_can($command === 'SHOW_CONTENT' ? 'broadcast.send' : 'broadcast.device_commands');
            [$type, $ids] = $parseTarget($in);
            [$bid, $count] = Broadcaster::sendCommand($command, $type, $ids, [], Auth::id());
            ActivityLog::add('device_command', 'broadcast', $bid, $command . ' → ' . Broadcaster::describeTarget($type, $ids) . " ($count TVs)");
            ajax_ok(['broadcast_id' => $bid, 'devices' => $count]);

        case 'emergency_start':
            $needPost();
            require_can('broadcast.emergency');
            $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 190);
            $message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 1000);
            if ($title === '' && $message === '') {
                ajax_error(__('Enter a title or message.'), 422, 'VALIDATION_ERROR');
            }
            [$type, $ids] = $parseTarget($in);
            $bid = Broadcaster::emergencyStart($title, $message, $type, $ids, Auth::id(),
                clean_color((string) ($in['bg_color'] ?? ''), '#B00020'), clean_color((string) ($in['text_color'] ?? ''), '#FFFFFF'));
            ActivityLog::add('emergency_start', 'broadcast', $bid, $title . ' → ' . Broadcaster::describeTarget($type, $ids));
            ajax_ok(['broadcast_id' => $bid]);

        case 'emergency_stop':
            $needPost();
            require_can('broadcast.emergency');
            $id = isset($in['id']) && is_numeric($in['id']) ? (int) $in['id'] : 0;
            $n = Broadcaster::emergencyStop($id > 0 ? $id : null);
            ActivityLog::add('emergency_stop', 'broadcast', $id > 0 ? $id : null, "Stopped $n emergency broadcast(s)");
            ajax_ok(['stopped' => $n]);

        case 'preview_content':
            require_can('content.view');
            $roomId = req_int('room_id', $_GET);
            if ($roomId) {
                require_can('rooms.view');
            }
            $obj = hc_preview_object(req_int('content_id', $_GET), req_int('playlist_id', $_GET), $roomId);
            if (!$obj) {
                ajax_error(__('Not found.'), 404, 'NOT_FOUND');
            }
            ajax_ok($obj);

        case 'schedule_events':
            require_can('schedule.manage');
            ajax_ok(['events' => schedule_events((string) ($_GET['start'] ?? ''), (string) ($_GET['end'] ?? ''))]);

        case 'set_language':
            $needPost();
            $lang = (string) ($in['lang'] ?? '');
            if (!isset(I18n::LANGUAGES[$lang])) {
                ajax_error('Unknown language', 422, 'VALIDATION_ERROR');
            }
            $_SESSION['lang'] = $lang;
            DB::update('users', ['language' => $lang], 'id = :id', ['id' => Auth::id()]);
            ajax_ok(['lang' => $lang]);

        default:
            ajax_error('Unknown action', 404, 'UNKNOWN_ACTION');
    }
} catch (InvalidArgumentException $e) {
    ajax_error($e->getMessage(), 422, 'VALIDATION_ERROR');
} catch (Throwable $e) {
    Logger::error('ajax ' . $action . ' failed: ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
    ajax_error((bool) Config::get('debug', false) ? $e->getMessage() : __('Something went wrong'), 500, 'SERVER_ERROR');
}

/**
 * Expand scheduled broadcasts into FullCalendar events for [start, end).
 * 'once' → one event; 'window' → one event per matching day (daily times / repeat days) or one span.
 */
function schedule_events(string $start, string $end): array
{
    $rs = strtotime($start) ?: strtotime('monday this week');
    $re = strtotime($end) ?: $rs + 42 * 86400;
    if ($re - $rs > 400 * 86400) {
        $re = $rs + 400 * 86400;
    }
    $rows = DB::all(
        "SELECT * FROM broadcast_commands
         WHERE mode IN ('once','window') AND is_emergency = 0 AND status <> 'cancelled'
           AND (start_at IS NULL OR start_at < :re) AND (end_at IS NULL OR end_at >= :rs)
         ORDER BY id DESC LIMIT 500",
        ['re' => date('Y-m-d H:i:s', $re), 'rs' => date('Y-m-d H:i:s', $rs)]
    );
    $colors = ['scheduled' => '#2563eb', 'active' => '#16a34a', 'completed' => '#6b7280'];
    $events = [];
    foreach ($rows as $b) {
        $base = [
            'title' => $b['title'] . ' · ' . Broadcaster::describeTarget($b['target_type'], $b['target_ids']),
            'url' => admin_url('schedule.php', ['action' => 'edit', 'id' => $b['id']]),
            'color' => $colors[$b['status']] ?? '#6b7280',
            'extendedProps' => ['status' => $b['status'], 'id' => (int) $b['id']],
        ];
        if ($b['mode'] === 'once') {
            if ($b['start_at']) {
                $s = strtotime($b['start_at']);
                $events[] = $base + ['start' => date('c', $s), 'end' => date('c', $s + 1800)];
            }
            continue;
        }
        $bs = $b['start_at'] ? (int) strtotime($b['start_at']) : null;
        $be = $b['end_at'] ? (int) strtotime($b['end_at']) : null;
        $days = $b['repeat_days'] ? array_map('intval', explode(',', (string) $b['repeat_days'])) : null;
        $hasDaily = $b['daily_start'] && $b['daily_end'];

        if (!$hasDaily && !$days) {
            // Plain date range.
            $events[] = $base + ['start' => date('c', $bs ?? $rs), 'end' => date('c', $be ?? $re)];
            continue;
        }
        $day = strtotime(date('Y-m-d', max($rs, $bs ?? $rs)) . ' 00:00:00');
        $last = min($re, $be ?? $re);
        $n = 0;
        while ($day < $last && $n < 400) {
            $n++;
            $dow = (int) date('N', $day);
            $dateStr = date('Y-m-d', $day);
            $next = strtotime($dateStr . ' +1 day');
            if ($days === null || in_array($dow, $days, true)) {
                if ($hasDaily) {
                    $es = strtotime($dateStr . ' ' . $b['daily_start']);
                    $ee = strtotime($dateStr . ' ' . $b['daily_end']);
                    if ($ee <= $es) {
                        $ee += 86400; // overnight window
                    }
                } else {
                    $es = $day;
                    $ee = $next;
                }
                $es = max($es, $bs ?? $es);
                $ee = min($ee, $be ?? $ee);
                if ($ee > $es) {
                    $ev = $base + ['start' => date('c', $es), 'end' => date('c', $ee)];
                    if (!$hasDaily) {
                        $ev['allDay'] = true;
                        $ev['start'] = $dateStr;
                        unset($ev['end']);
                    }
                    $events[] = $ev;
                }
            }
            $day = $next;
        }
    }
    return $events;
}
