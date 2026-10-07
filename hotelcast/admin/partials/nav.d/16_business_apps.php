<?php
/**
 * Sidebar items of the business display apps (docs/modules/business_apps.md), after the Notice board.
 */
declare(strict_types=1);

return [
    ['offers', 'offers.php', 'offers.manage', 'bi-tags', __('Offers'), 'hotel', ['after' => 'notices']],
    ['class_schedule', 'class_schedule.php', 'class_schedule.manage', 'bi-calendar-week', __('Class schedule'), 'hotel', ['after' => 'offers']],
    ['departures', 'departures.php', 'departures.manage', 'bi-signpost-split', __('Departures board'), 'hotel', ['after' => 'class_schedule']],
    ['kpi', 'kpi.php', 'kpi.manage', 'bi-speedometer2', __('KPI dashboard'), 'hotel', ['after' => 'departures']],
];
