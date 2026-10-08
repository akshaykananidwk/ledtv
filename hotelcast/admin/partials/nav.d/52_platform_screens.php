<?php
/**
 * Sidebar item: Platform → All screens (docs/modules/platform_screens.md). Platform admins see it in
 * the platform menu (every customer), resellers in their own menu (their customers only).
 */
declare(strict_types=1);

return [
    ['platform_screens', 'platform_screens.php', 'platform.screens', 'bi-tv', Auth::role() === 'reseller' ? __('Screens') : __('All screens'),
        Auth::role() === 'reseller' ? 'reseller' : 'platform', ['after' => Auth::role() === 'reseller' ? 'reseller' : 'platform_hotels']],
];
