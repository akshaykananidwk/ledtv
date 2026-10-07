<?php
/**
 * 2.3: display apps (core/DisplayApps.php, core/Apps/*App.php, /display/) — notice board table and
 * permission. App items use the content permissions (content.view / content.manage).
 */
declare(strict_types=1);

Tenant::registerTable('notices');

// Notice board (#3): staff may post notices (admin/notices.php).
Auth::registerPermission('notices.manage', 'staff');
