<?php
/** Admin menu: TV health dashboard (2.4 #44) right after TV support. See 10_hotel.php for the format. */
declare(strict_types=1);

return [
    ['tv_health', 'tv_health.php', 'tv_health.view', 'bi-heart-pulse', __('TV health'), 'hotel', ['after' => 'support']],
];
