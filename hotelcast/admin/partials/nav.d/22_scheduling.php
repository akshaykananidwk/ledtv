<?php
/**
 * Sidebar items: scheduling (2.4) — Calendar (after Schedule), Holidays (after TV Power) and Approvals
 * (after Content Library). Approvals shows while the hotel requires approval or something waits; its
 * label carries a count (managers: items waiting, staff: their rejected items). While approval is
 * required, staff / reception also get the Content Library entry (they may submit content).
 */
declare(strict_types=1);

$__items = [
    ['calendar', 'calendar.php', 'schedule.manage', 'bi-calendar3', __('Calendar'), 'hotel', ['after' => 'schedule']],
    ['holidays', 'holidays.php', 'holidays.manage', 'bi-balloon', __('Holidays'), 'hotel', ['after' => 'power']],
];
try {
    if (Tenant::has() && Auth::user()) {
        $__count = Auth::can('content.approve') ? Approvals::pendingCount() : Approvals::rejectedCount((int) Auth::id());
        if (Approvals::enabled() || $__count > 0) {
            if (Approvals::enabled() && !Auth::can('content.view')) {
                $__items[] = ['content', 'content.php', 'content.submit', 'bi-images', __('Content Library'), 'hotel', ['after' => 'rooms']];
            }
            $__items[] = ['approvals', 'approvals.php', 'content.submit', 'bi-patch-check', __('Approvals') . ($__count > 0 ? ' (' . $__count . ')' : ''), 'hotel', ['after' => 'content']];
        }
    }
} catch (Throwable $e) {
    // Before migration 022 (update in progress): no approvals entry.
}
return $__items;
