-- HotelCast 2.2 — ticker bar per TV / group (tickers) and per-user TV access (user_access).
-- Both are tenant tables (hotel_id). Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.

-- Scrolling text bar. target_type all = every TV of the hotel; group / room = only those TVs.
-- Messages of all matching tickers are joined; the style of the most specific one (room > group > all,
-- then priority) is used. override = hide less specific tickers on the TVs this ticker targets.
CREATE TABLE IF NOT EXISTS tickers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  target_type ENUM('all','group','room') NOT NULL DEFAULT 'all',
  target_id INT UNSIGNED NULL,
  text_color VARCHAR(7) NOT NULL DEFAULT '#FFD700',
  bg_color VARCHAR(7) NOT NULL DEFAULT '#000000',
  speed TINYINT UNSIGNED NOT NULL DEFAULT 5,
  font_size TINYINT UNSIGNED NOT NULL DEFAULT 26,
  height SMALLINT UNSIGNED NOT NULL DEFAULT 56,
  position ENUM('bottom','top') NOT NULL DEFAULT 'bottom',
  reserve_space TINYINT(1) NOT NULL DEFAULT 1,
  override_lower TINYINT(1) NOT NULL DEFAULT 0,
  priority INT NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  time_from TIME NULL,
  time_to TIME NULL,
  days VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tickers_hotel (hotel_id, is_active),
  KEY idx_tickers_target (hotel_id, target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which TVs a hotel user (manager / staff / reception) may control. No rows = all TVs (as before).
-- Super admins and platform users always have all TVs.
CREATE TABLE IF NOT EXISTS user_access (
  hotel_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  target_type ENUM('group','room') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, target_type, target_id),
  KEY idx_ua_hotel (hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
