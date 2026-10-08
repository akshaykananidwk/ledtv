<?php
/**
 * Sidebar items (2.4, docs/modules/device_schedules.md): Device schedules (timed volume / input / restart /
 * bell / announcement, sounds, presence sensors) after TV Power — staff see it for "Announce now" — and the
 * Proof of play report after Logs & History (manager+).
 */
declare(strict_types=1);

return [
    ['device_schedules', 'device_schedules.php', 'announce.send', 'bi-alarm', __('Device schedules'), 'hotel', ['after' => 'power']],
    ['play_report', 'play_report.php', 'play_report.view', 'bi-clipboard-data', __('Proof of play'), 'hotel', ['after' => 'logs']],
];
