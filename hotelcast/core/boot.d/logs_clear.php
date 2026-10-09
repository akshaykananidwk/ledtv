<?php
/**
 * 2.6.1 — clearing logs (admin/logs.php, Super Admin console → Audit logs).
 * A customer's Admin may clear the customer's own logs (never the Super Admin's audit rows, which they
 * do not see either); server-wide error / update logs and the platform audit log are Super Admin only.
 */
declare(strict_types=1);

Auth::registerPermission('logs.clear', 'super_admin');
