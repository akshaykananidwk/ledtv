-- HotelCast 2.4 — display apps #26–#30 (docs/modules/widgets_26_30.md).
-- festivals    = tenant table: festival calendar of a hotel (admin/festivals.php, core/Apps/FestivalsApp.php).
--                A starter list of 2026–2027 festivals can be imported (dates marked "please verify").
-- celebrations = tenant table: birthdays / anniversaries / work anniversaries (admin/celebrations.php,
--                core/Apps/CelebrationsApp.php). Month + day, year optional; only rows with consent = 1 are
--                ever shown on TVs.
-- Both registered as tenant tables in core/boot.d/widgets_26_30.php.
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS festivals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name_en VARCHAR(120) NOT NULL,
  name_gu VARCHAR(120) NOT NULL DEFAULT '',
  name_hi VARCHAR(120) NOT NULL DEFAULT '',
  starts_on DATE NOT NULL,
  ends_on DATE NULL,
  description TEXT NULL,
  theme VARCHAR(30) NOT NULL DEFAULT '',
  image_path VARCHAR(255) NULL,
  thumb_path VARCHAR(255) NULL,
  is_starter TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_festivals_hotel (hotel_id, is_active, starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS celebrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  type ENUM('birthday','anniversary','work_anniversary') NOT NULL DEFAULT 'birthday',
  month TINYINT UNSIGNED NOT NULL,
  day TINYINT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NULL,
  group_label VARCHAR(80) NOT NULL DEFAULT '',
  photo_path VARCHAR(255) NULL,
  thumb_path VARCHAR(255) NULL,
  consent TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_celebrations_hotel (hotel_id, is_active, month, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
