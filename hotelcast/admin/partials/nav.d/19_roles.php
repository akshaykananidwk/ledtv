<?php
/** Sidebar item: Roles (custom roles / RBAC, docs/modules/roles.md) right after Users — roles.manage (Admin). */
declare(strict_types=1);

return [
    ['roles', 'roles.php', 'roles.manage', 'bi-shield-lock', __('Roles'), 'hotel', ['after' => 'users']],
];
