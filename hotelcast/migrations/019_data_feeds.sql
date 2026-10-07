-- HotelCast 2.3 — data feeds layer (core/DataFeeds.php) + gold / market / cricket / currency / travel widgets.
-- data_feeds   = platform table (no hotel_id scoping): one row per external feed (provider + params + key owner),
--                last good value, "as of" time, last error. Filled by DataFeedsTask in the background.
-- metal_rates  = tenant table: manual gold / silver rates of a hotel / jeweller (admin/rates.php); every save is a
--                new row, the newest row is the current rate, older rows are the change history.
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.
CREATE TABLE IF NOT EXISTS data_feeds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  feed_key CHAR(40) NOT NULL,
  provider VARCHAR(30) NOT NULL,
  params TEXT NULL,
  key_hotel_id INT UNSIGNED NOT NULL DEFAULT 0,
  data MEDIUMTEXT NULL,
  fetched_at INT UNSIGNED NULL,
  attempted_at INT UNSIGNED NULL,
  error VARCHAR(255) NULL,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  retry_at INT UNSIGNED NULL,
  demand_at INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_data_feeds_key (feed_key),
  KEY idx_data_feeds_due (demand_at, provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metal_rates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  gold_24k DECIMAL(12,2) NULL,
  gold_22k DECIMAL(12,2) NULL,
  gold_18k DECIMAL(12,2) NULL,
  silver_kg DECIMAL(12,2) NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_metal_rates_hotel (hotel_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
