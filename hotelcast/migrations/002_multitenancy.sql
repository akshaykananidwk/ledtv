-- HotelCast 2.0 — multi-hotel foundation (SaaS): hotels, plans, resellers, licenses, invoices.
-- New tables only; the in-place conversion of existing tables (hotel_id columns, per-hotel unique
-- keys, per-hotel settings, roles) is done by 002_multitenancy_upgrade.php, which runs right after
-- this file and is safe to resume. MySQL 8 / MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS plans (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  price_per_tv_month DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_tvs INT UNSIGNED NULL,
  features JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_plans_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resellers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  contact_name VARCHAR(120) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  commission_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  max_hotels INT UNSIGNED NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  brand_name VARCHAR(120) NULL,
  brand_logo VARCHAR(255) NULL,
  brand_color VARCHAR(7) NULL,
  support_phone VARCHAR(40) NULL,
  support_email VARCHAR(190) NULL,
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  status ENUM('active','suspended','expired') NOT NULL DEFAULT 'active',
  suspend_reason VARCHAR(30) NULL,
  plan_id INT UNSIGNED NULL,
  reseller_id INT UNSIGNED NULL,
  max_tvs INT UNSIGNED NULL,
  expires_at DATETIME NULL,
  registration_key VARCHAR(64) NULL,
  contact_name VARCHAR(120) NULL,
  contact_email VARCHAR(190) NULL,
  contact_phone VARCHAR(40) NULL,
  address VARCHAR(500) NULL,
  city VARCHAR(80) NULL,
  gstin VARCHAR(20) NULL,
  brand_name VARCHAR(120) NULL,
  brand_logo VARCHAR(255) NULL,
  brand_color VARCHAR(7) NULL,
  notes VARCHAR(1000) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hotels_slug (slug),
  UNIQUE KEY uq_hotels_regkey (registration_key),
  KEY idx_hotels_status (status),
  KEY idx_hotels_reseller (reseller_id),
  CONSTRAINT fk_hotels_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
  CONSTRAINT fk_hotels_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS licenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  license_key VARCHAR(64) NOT NULL,
  hotel_id INT UNSIGNED NULL,
  customer_name VARCHAR(150) NOT NULL DEFAULT '',
  max_tvs INT UNSIGNED NULL,
  expires_at DATETIME NULL,
  features JSON NULL,
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  bound_domain VARCHAR(190) NULL,
  last_check_at DATETIME NULL,
  last_ip VARCHAR(45) NULL,
  last_version VARCHAR(30) NULL,
  last_tv_count INT UNSIGNED NULL,
  check_count INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_licenses_key (license_key),
  KEY idx_licenses_hotel (hotel_id),
  CONSTRAINT fk_licenses_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  number VARCHAR(40) NOT NULL,
  period_from DATE NOT NULL,
  period_to DATE NOT NULL,
  plan_name VARCHAR(120) NULL,
  tv_count INT UNSIGNED NOT NULL DEFAULT 0,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  status ENUM('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  issued_at DATE NOT NULL,
  due_date DATE NOT NULL,
  paid_at DATETIME NULL,
  payment_ref VARCHAR(120) NULL,
  payment_method VARCHAR(40) NULL,
  notes VARCHAR(1000) NULL,
  bill_to JSON NULL,
  reminders_sent INT UNSIGNED NOT NULL DEFAULT 0,
  last_reminder_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_invoices_number (number),
  KEY idx_invoices_hotel (hotel_id, status),
  KEY idx_invoices_due (status, due_date),
  KEY idx_invoices_period (hotel_id, period_from),
  CONSTRAINT fk_invoices_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_sequences (
  seq_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
  last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example plans (prices per TV per month, INR). Edit in Platform → Plans.
INSERT IGNORE INTO plans (name, description, price_per_tv_month, max_tvs, features) VALUES
  ('Basic', 'Content, broadcast, schedules', 99.00, 25, NULL),
  ('Standard', 'Everything in Basic + guest services', 149.00, 100, NULL),
  ('Premium', 'All modules, unlimited TVs', 199.00, NULL, NULL);

-- Hotel 1 = the existing single-hotel installation (name taken from the old settings).
INSERT IGNORE INTO hotels (id, name, slug, status)
SELECT 1, COALESCE(NULLIF((SELECT s.setting_value FROM system_settings s WHERE s.setting_key = 'hotel_name' LIMIT 1), ''), 'My Hotel'), 'hotel-1', 'active'
FROM DUAL;
