<?php
/**
 * Admin sidebar items — hotel section. Every admin/partials/nav.d/*.php returns a list of
 *   [key, file, permission, icon, label, section]  (+ optional 7th element: ['saas' => true, 'after' => 'broadcast'])
 * section: 'hotel' (needs a hotel context) | 'reseller' | 'platform'.
 * Files are loaded in name order; a module adds its menu entry by adding its own file here.
 */
declare(strict_types=1);

return [
    ['index', 'index.php', 'dashboard.view', 'bi-speedometer2', __('Dashboard'), 'hotel'],
    ['rooms', 'rooms.php', 'rooms.view', 'bi-tv', __('Rooms & TVs'), 'hotel'],
    ['groups', 'groups.php', 'groups.manage', 'bi-collection', __('Groups'), 'hotel'],
    ['content', 'content.php', 'content.view', 'bi-images', __('Content Library'), 'hotel'],
    ['playlists', 'playlists.php', 'playlists.manage', 'bi-collection-play', __('Playlists'), 'hotel'],
    ['broadcast', 'broadcast.php', 'broadcast.send', 'bi-broadcast-pin', __('Broadcast'), 'hotel'],
    ['schedule', 'schedule.php', 'schedule.manage', 'bi-calendar-week', __('Schedule'), 'hotel'],
    ['power', 'power.php', 'schedule.manage', 'bi-power', __('TV Power'), 'hotel'],
    ['apk', 'apk.php', 'apk.manage', 'bi-android2', __('APK Manager'), 'hotel'],
    ['logs', 'logs.php', 'logs.view', 'bi-journal-text', __('Logs & History'), 'hotel'],
    ['users', 'users.php', 'users.manage', 'bi-people', __('Users'), 'hotel'],
    ['settings', 'settings.php', 'settings.manage', 'bi-gear', __('Settings'), 'hotel'],
    ['billing', 'billing.php', 'billing.view', 'bi-receipt', __('Billing'), 'hotel', ['saas' => true]],
];
