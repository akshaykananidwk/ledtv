-- HotelCast 2.0 — QR setup of TVs (no typing on the TV).
-- A fresh TV calls POST /api/provision/start and shows a QR + 6-character code; a hotel user scans it,
-- picks the room in admin/claim.php ("claimed"); the TV polls GET /api/provision/status, receives
-- server URL + room + registration key and registers itself ("used").
-- NOT a tenant table: hotel_id stays NULL until a user claims the code. Every admin query checks
-- that the user may manage the chosen hotel (see core/Provisioning.php).
-- All DATETIME columns are UTC (hotels may use different time zones).
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS device_provisioning (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(6) NOT NULL,
  secret_hash CHAR(64) NOT NULL,
  device_uid VARCHAR(64) NOT NULL,
  model VARCHAR(100) NULL,
  app_version VARCHAR(20) NULL,
  ip VARCHAR(45) NULL,
  status ENUM('pending','claimed','used','expired') NOT NULL DEFAULT 'pending',
  hotel_id INT UNSIGNED NULL,
  room_id INT UNSIGNED NULL,
  claimed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  claimed_at DATETIME NULL,
  used_at DATETIME NULL,
  KEY idx_prov_code_status (code, status),
  KEY idx_prov_device (device_uid),
  KEY idx_prov_secret (secret_hash),
  KEY idx_prov_created (created_at),
  KEY idx_prov_hotel (hotel_id),
  CONSTRAINT fk_prov_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE,
  CONSTRAINT fk_prov_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
  CONSTRAINT fk_prov_user FOREIGN KEY (claimed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
