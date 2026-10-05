-- HotelCast 2.0 — sponsors & ad campaigns (#11), analytics (#17), template library (#12 / #7).
-- New tenant tables (hotel_id + FK hotels) and one column on broadcast_logs for ad impressions.
-- Idempotent: CREATE TABLE IF NOT EXISTS; "duplicate column / key" errors (1060 / 1061) are ignored
-- by the Migrator. MySQL 8 / MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS sponsors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  name VARCHAR(150) NOT NULL,
  contact_name VARCHAR(120) NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  contract_start DATE NULL,
  contract_end DATE NULL,
  notes VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_sponsors_hotel (hotel_id, name),
  CONSTRAINT fk_sponsors_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  sponsor_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  content_id INT UNSIGNED NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  daily_start TIME NULL,
  daily_end TIME NULL,
  target_type ENUM('all','rooms','groups','floors') NOT NULL DEFAULT 'all',
  target_ids JSON NULL,
  freq_items SMALLINT UNSIGNED NULL,
  freq_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  max_per_day INT UNSIGNED NULL,
  priority SMALLINT NOT NULL DEFAULT 0,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_adc_hotel_status (hotel_id, status, start_date),
  KEY idx_adc_sponsor (sponsor_id),
  CONSTRAINT fk_adc_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE,
  CONSTRAINT fk_adc_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE,
  CONSTRAINT fk_adc_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily roll-up of ad impressions per campaign and room. Kept after broadcast_logs are purged by the
-- log retention, so sponsor reports for billing stay available.
CREATE TABLE IF NOT EXISTS ad_stats_daily (
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  campaign_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  room_id INT UNSIGNED NOT NULL DEFAULT 0,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  seconds INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (campaign_id, day, room_id),
  KEY idx_ads_hotel_day (hotel_id, day),
  CONSTRAINT fk_ads_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TV usage samples (AnalyticsTask, every 5 minutes): minutes online / screen on per TV and day.
CREATE TABLE IF NOT EXISTS tv_usage_daily (
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  device_id INT UNSIGNED NOT NULL,
  day DATE NOT NULL,
  online_min INT UNSIGNED NOT NULL DEFAULT 0,
  screen_on_min INT UNSIGNED NOT NULL DEFAULT 0,
  samples INT UNSIGNED NOT NULL DEFAULT 0,
  last_sample_at DATETIME NULL,
  PRIMARY KEY (device_id, day),
  KEY idx_tvu_hotel_day (hotel_id, day),
  CONSTRAINT fk_tvu_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ad impressions: POST /api/device/played items carry ad_campaign_id.
ALTER TABLE broadcast_logs ADD COLUMN ad_campaign_id INT UNSIGNED NULL AFTER content_id;
ALTER TABLE broadcast_logs ADD KEY idx_bl_ad (ad_campaign_id, created_at);
ALTER TABLE broadcast_logs ADD KEY idx_bl_hotel_event (hotel_id, event, created_at);
