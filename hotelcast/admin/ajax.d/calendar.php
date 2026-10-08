<?php
/**
 * AJAX actions "calendar_*" (2.4 calendar, admin/calendar.php, core/Calendar.php):
 *   calendar_events  GET  start, end            → {events: [FullCalendar event objects]}
 *   calendar_move    POST {kind, id, start, end, orig_start}  move / resize a schedule or holiday
 *   calendar_add     POST {title, source, target_type, target_ids, start, end, mode}  new schedule
 * Permission schedule.manage; users limited to some TVs only see / change their TVs' entries (Access).
 * Another hotel's ids → 404, other TVs → 403, invalid input → 422.
 */
declare(strict_types=1);

require_can('schedule.manage');

try {
    if ($action === 'calendar_events') {
        Scheduler::tick();
        ajax_ok(['events' => Calendar::events((string) ($_GET['start'] ?? ''), (string) ($_GET['end'] ?? ''))]);
    }
    if ($action === 'calendar_move') {
        $needPost();
        ajax_ok(Calendar::move($in));
    }
    if ($action === 'calendar_add') {
        $needPost();
        ajax_ok(['id' => Calendar::add($in)]);
    }
} catch (DomainException $e) {
    $code = $e->getCode() === 403 ? 403 : 404;
    ajax_error($e->getMessage(), $code, $code === 403 ? 'FORBIDDEN' : 'NOT_FOUND');
}
