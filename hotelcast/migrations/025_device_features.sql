-- HotelCast 2.4 — device features (docs/modules/device_features.md):
--   #44 TV health: last health JSON per TV + a small history (one row per TV per 10 minutes, 7 days);
--   #41 live screen view: one live session per TV (only the newest frame is kept on disk);
--   #42 / #50 per-room USB mode and HDMI-CEC mode.
-- Idempotent: "already exists" / "duplicate column" errors are ignored by the Migrator.

ALTER TABLE devices ADD COLUMN health TEXT NULL;
ALTER TABLE devices ADD COLUMN health_at DATETIME NULL;
-- Debounced health alerts: {"storage_low": <unix time alerted>, …} of the warnings already reported.
ALTER TABLE devices ADD COLUMN health_alerts VARCHAR(500) NULL;

-- USB mode: the room plays the USB / SD "KrishnaCloud" folder of its TV (content field usb_mode).
ALTER TABLE rooms ADD COLUMN usb_mode TINYINT(1) NOT NULL DEFAULT 0;
-- HDMI-CEC power mode: auto (detect) | box (Android box drives the TV over HDMI) | tv (built-in panel).
ALTER TABLE rooms ADD COLUMN cec_mode VARCHAR(4) NOT NULL DEFAULT 'auto';

CREATE TABLE IF NOT EXISTS device_health_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NOT NULL,
  storage_free_mb INT NULL,
  ram_avail_mb INT NULL,
  ram_total_mb INT NULL,
  cpu_temp_c DECIMAL(5,1) NULL,
  wifi_rssi SMALLINT NULL,
  app_mem_mb INT NULL,
  uptime_sec INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  KEY idx_dhh_device (device_id, created_at),
  KEY idx_dhh_hotel (hotel_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_live_views (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  device_id INT UNSIGNED NOT NULL,
  token CHAR(32) NOT NULL,
  command_id INT UNSIGNED NULL,
  started_by INT UNSIGNED NULL,
  started_at DATETIME NOT NULL,
  keepalive_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  stopped_at DATETIME NULL,
  frame_at DATETIME NULL,
  frame_size INT UNSIGNED NULL,
  frame_width SMALLINT UNSIGNED NULL,
  frame_height SMALLINT UNSIGNED NULL,
  frames INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_live_device (device_id),
  KEY idx_live_hotel (hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
