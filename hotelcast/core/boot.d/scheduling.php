<?php
/**
 * 2.4: scheduling (docs/modules/scheduling.md) — calendar of everything scheduled (admin/calendar.php),
 * content approval workflow (admin/approvals.php, core/Approvals.php), content expiry / start dates and
 * playlist dayparting (core/ContentRules.php) and the holiday calendar (admin/holidays.php,
 * core/Holidays.php). Tables from migrations/022_scheduling.sql.
 */
declare(strict_types=1);

Tenant::registerTable('content_revisions');
Tenant::registerTable('holidays');

// Approve / reject content of staff (manager and up). Managers' own saves are approved directly.
Auth::registerPermission('content.approve', 'manager');
// While the hotel requires approval, staff and reception may add / edit content: it waits for a manager.
Auth::registerPermission('content.submit', 'reception');
// Holiday calendar (manager and up, like schedules and TV power).
Auth::registerPermission('holidays.manage', 'manager');

// Web push "content waiting for approval" (managers) and "your content was approved / rejected".
StaffAlerts::registerType('approvals', 'Content waiting for approval', 'content.approve');
StaffAlerts::registerType('content_expiry', 'Content expires soon', 'content.manage');
