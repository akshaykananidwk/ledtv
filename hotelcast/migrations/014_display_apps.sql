-- HotelCast 2.3 — display apps framework (core/DisplayApps.php, core/Apps/*App.php).
-- App items themselves are content_items rows (type 'app', 013_apps_layouts.sql).
-- notices = Notice board app (#3): school / college / office notices, tenant table (hotel_id).
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.
CREATE TABLE IF NOT EXISTS notices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  body TEXT NULL,
  category ENUM('exam','holiday','result','event','general') NOT NULL DEFAULT 'general',
  starts_on DATE NULL,
  ends_on DATE NULL,
  priority INT NOT NULL DEFAULT 0,
  image_path VARCHAR(255) NULL,
  thumb_path VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_notices_hotel (hotel_id, is_active, category),
  KEY idx_notices_dates (hotel_id, starts_on, ends_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
