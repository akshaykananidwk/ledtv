-- HotelCast 2.3 — business display apps (core/Apps/OffersApp.php, ClassScheduleApp.php,
-- DeparturesApp.php, KpiDashboardApp.php; docs/modules/business_apps.md).
-- All four are tenant tables (hotel_id, registered in core/boot.d/business_apps.php).
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.

-- #4 Shop / mall offers (admin/offers.php).
CREATE TABLE IF NOT EXISTS offers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  price DECIMAL(12,2) NULL,
  old_price DECIMAL(12,2) NULL,
  discount_pct TINYINT UNSIGNED NULL,
  badge VARCHAR(40) NOT NULL DEFAULT '',
  valid_from DATETIME NULL,
  valid_to DATETIME NULL,
  sort INT NOT NULL DEFAULT 0,
  image_path VARCHAR(255) NULL,
  thumb_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_offers_hotel (hotel_id, is_active, sort),
  KEY idx_offers_valid (hotel_id, valid_from, valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- #6 Gym / yoga / school / OPD schedule (admin/class_schedule.php). days = ISO weekdays "1,3,5" (1 = Monday).
CREATE TABLE IF NOT EXISTS class_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  trainer VARCHAR(120) NOT NULL DEFAULT '',
  days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  room VARCHAR(80) NOT NULL DEFAULT '',
  level VARCHAR(20) NOT NULL DEFAULT '',
  color CHAR(7) NOT NULL DEFAULT '#1565C0',
  photo_path VARCHAR(255) NULL,
  thumb_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_class_sessions_hotel (hotel_id, is_active, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- #7 Bus / railway / airport departures board (admin/departures.php).
-- service_date NULL = runs daily; status_date = the day the status applies to (daily rows reset to on time).
CREATE TABLE IF NOT EXISTS departures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  kind ENUM('departure','arrival') NOT NULL DEFAULT 'departure',
  sched_time TIME NOT NULL,
  service_date DATE NULL,
  number VARCHAR(40) NOT NULL DEFAULT '',
  destination VARCHAR(120) NOT NULL,
  destination_gu VARCHAR(120) NOT NULL DEFAULT '',
  destination_hi VARCHAR(120) NOT NULL DEFAULT '',
  platform VARCHAR(20) NOT NULL DEFAULT '',
  status ENUM('on_time','delayed','boarding','departed','cancelled','arrived') NOT NULL DEFAULT 'on_time',
  delay_min SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  remark VARCHAR(190) NOT NULL DEFAULT '',
  status_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_departures_hotel (hotel_id, is_active, sched_time),
  KEY idx_departures_date (hotel_id, service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- #8 Factory / office KPI dashboard (admin/kpi.php, push API api/routes/kpi.php).
-- push_token_hash = SHA-256 of the tile's machine token (the token itself is shown once).
CREATE TABLE IF NOT EXISTS kpi_tiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  type ENUM('counter','percent','text','days_since') NOT NULL DEFAULT 'counter',
  value_num DECIMAL(18,2) NOT NULL DEFAULT 0,
  value_text VARCHAR(190) NOT NULL DEFAULT '',
  since_date DATE NULL,
  target DECIMAL(18,2) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT '',
  good_at DECIMAL(18,2) NULL,
  bad_at DECIMAL(18,2) NULL,
  sort INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  push_token_hash CHAR(64) NULL,
  push_token_hint VARCHAR(8) NOT NULL DEFAULT '',
  pushed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_kpi_tiles_token (push_token_hash),
  KEY idx_kpi_tiles_hotel (hotel_id, is_active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
