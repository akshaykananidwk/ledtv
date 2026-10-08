<?php
/**
 * Custom roles / RBAC (docs/modules/roles.md): core/Roles.php, admin/roles.php, migrations/029_roles.sql.
 * Built-in roles stay computed from the permission registry; a customer may add its own roles with an
 * explicit permission list (users.role_id).
 */
declare(strict_types=1);

Tenant::registerTable('roles');

// Create / edit / copy / delete custom roles (Admin only by default; can be given to a custom role only by an Admin).
Auth::registerPermission('roles.manage', 'super_admin');
