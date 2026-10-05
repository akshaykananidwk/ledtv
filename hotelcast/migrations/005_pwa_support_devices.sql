-- HotelCast 2.0 — PWA + web push (#14), support tools (#24), TV device controls (#4 #5 #15 #16).
-- Idempotent: CREATE TABLE IF NOT EXISTS; MODIFY to the same type is a no-op on re-run.
-- MySQL 8 / MariaDB 10.4+.

-- broadcast_commands.command was an ENUM in 001: the new device commands (SET_VOLUME, MUTE, UNMUTE,
-- SCREENSHOT, UPLOAD_LOGS, OPEN_INPUT, SHOW_WELCOME, SHOW_MESSAGE, …) need a free-form column.
-- device_commands.command already is VARCHAR(30).
ALTER TABLE broadcast_commands MODIFY command VARCHAR(30) NOT NULL;

-- Web push subscriptions: one row per browser / device of a user. hotel_id is the user's hotel
-- (users.hotel_id, NULL for platform-only users); rows are always accessed by user_id.
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(200) NOT NULL,
  auth VARCHAR(100) NOT NULL,
  alert_types VARCHAR(1000) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used DATETIME NULL,
  failures INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NULL,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  KEY idx_push_hotel (hotel_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_push_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uploaded support data per TV: screenshots (JPEG), log bundles, crash reports. The files live in
-- storage/support/h{hotel}/d{device}/ (never web reachable) and are served by admin/support.php.
CREATE TABLE IF NOT EXISTS device_support_files (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  device_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NULL,
  kind ENUM('screenshot','logs','crash') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size INT UNSIGNED NOT NULL DEFAULT 0,
  app_version VARCHAR(40) NULL,
  meta JSON NULL,
  happened_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dsf_device (hotel_id, device_id, kind, id),
  KEY idx_dsf_kind_created (kind, created_at),
  KEY idx_dsf_hotel_created (hotel_id, created_at),
  CONSTRAINT fk_dsf_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_dsf_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TV analytics events (POST /api/device/event): guest_menu_open, guest_menu_item, qr_shown,
-- input_switch, welcome_shown, … Read by the analytics module.
CREATE TABLE IF NOT EXISTS device_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  device_id INT UNSIGNED NULL,
  room_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,
  data JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_de_hotel_created (hotel_id, created_at),
  KEY idx_de_hotel_type (hotel_id, type, created_at),
  KEY idx_de_device (device_id, created_at),
  CONSTRAINT fk_de_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
