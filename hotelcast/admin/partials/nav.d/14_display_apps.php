<?php
/**
 * Sidebar items: Apps gallery (display apps, 2.3) right after the Content Library, and the Notice
 * board management page (#3) right after Apps.
 */
declare(strict_types=1);

return [
    ['apps', 'apps.php', 'content.manage', 'bi-grid-3x3-gap', __('Apps'), 'hotel', ['after' => 'content']],
    ['notices', 'notices.php', 'notices.manage', 'bi-pin-angle', __('Notice board'), 'hotel', ['after' => 'apps']],
];
