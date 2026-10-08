<?php
/** Sidebar item: video walls (2.4, #36) right after Groups — video_walls.manage (manager+). See 10_hotel.php. */
declare(strict_types=1);

return [
    ['video_walls', 'video_walls.php', 'video_walls.manage', 'bi-grid-3x3', __('Video walls'), 'hotel', ['after' => 'groups']],
];
