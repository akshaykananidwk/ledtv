-- Custom roles / RBAC (docs/modules/roles.md, core/Roles.php, admin/roles.php).
-- A customer (hotel) may define its own roles with an explicit permission list. The built-in roles
-- (Admin = super_admin, Manager, Staff, Reception) are NOT stored here: they are computed from the
-- permission registry in core/Auth.php, so permissions of new modules keep working automatically.
--   permissions  JSON list of hotel permission keys, e.g. ["content.view","content.manage"]
--   base_level   built-in role kept in users.role for code that still compares levels
--                (manager / staff / reception, never super_admin); computed from the permissions
--   is_system    reserved (always 0 for rows in this table)
-- users.role_id: NULL = built-in role (users.role as before), otherwise the custom role (same hotel).
-- Idempotent: CREATE TABLE IF NOT EXISTS; duplicate column / key errors are ignored by the Migrator.
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  base_level ENUM('manager','staff','reception') NOT NULL DEFAULT 'staff',
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  permissions TEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_hotel_name (hotel_id, name),
  KEY idx_roles_hotel (hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN role_id INT UNSIGNED NULL;
ALTER TABLE users ADD KEY idx_users_role_id (role_id);
