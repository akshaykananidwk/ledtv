-- 2.5 platform screens (docs/modules/platform_screens.md): the unassigned device pool.
-- A TV that registers with the PLATFORM registration key (Platform → All screens → Unassigned pool,
-- system_settings hotel 0: platform_pool_key / platform_pool_enabled) is not part of any customer yet.
-- It gets a normal device token, polls as usual and shows a "waiting for setup" screen until the
-- Super Admin assigns it to a customer (core/DevicePool.php). Assigning creates (or reuses) the
-- customer's `devices` row with the SAME token_hash, so the TV switches to the customer's content on
-- its next poll without registering again. TVs removed from a customer ("Move to unassigned pool")
-- land here too (source 'removed', from_hotel_id).
--   device_uid    the TV's id (unique in the pool)
--   token_hash    SHA-256 of the TV's bearer token (looked up by DeviceManager::authenticate)
--   label         the room / screen number typed on the TV during registration (used as screen ID
--                 when the TV is assigned with "same screen ID")
-- NOT a tenant table (no hotel_id): platform admins only.
-- Idempotent: CREATE TABLE IF NOT EXISTS; "duplicate key" (1061) is ignored by the Migrator.
CREATE TABLE IF NOT EXISTS device_pool (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_uid VARCHAR(64) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  label VARCHAR(20) NULL,
  platform ENUM('android','web') NOT NULL DEFAULT 'android',
  model VARCHAR(100) NULL,
  app_version VARCHAR(20) NULL,
  app_version_code INT UNSIGNED NULL,
  android_version VARCHAR(20) NULL,
  ip_address VARCHAR(45) NULL,
  public_ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  source ENUM('platform_key','removed') NOT NULL DEFAULT 'platform_key',
  from_hotel_id INT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  last_ping DATETIME NULL,
  registered_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pool_uid (device_uid),
  KEY idx_pool_token (token_hash),
  KEY idx_pool_from (from_hotel_id),
  CONSTRAINT fk_pool_hotel FOREIGN KEY (from_hotel_id) REFERENCES hotels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- All screens page: list / count every TV across customers quickly.
ALTER TABLE devices ADD KEY idx_devices_platform_list (is_revoked, hotel_id, status);
