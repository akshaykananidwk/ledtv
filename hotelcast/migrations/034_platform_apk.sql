-- 2.7 Super Admin → APK Manager (docs/modules/apk_manager.md).
-- Platform-wide TV app releases live in apk_releases with hotel_id NULL; customer releases keep their
-- hotel_id. Per release: is_required (forced update on app start), rollout 'all' | 'selected' (the selected
-- customers are in apk_release_hotels), the package / signing certificate read from the APK, a download counter.
-- Idempotent: duplicate column / key / table errors are ignored by the Migrator.
ALTER TABLE apk_releases MODIFY hotel_id INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE apk_releases ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER notes;
ALTER TABLE apk_releases ADD COLUMN rollout VARCHAR(10) NOT NULL DEFAULT 'all' AFTER is_required;
ALTER TABLE apk_releases ADD COLUMN package_name VARCHAR(150) NULL AFTER rollout;
ALTER TABLE apk_releases ADD COLUMN signer_sha256 CHAR(64) NULL AFTER package_name;
ALTER TABLE apk_releases ADD COLUMN downloads INT UNSIGNED NOT NULL DEFAULT 0 AFTER signer_sha256;
ALTER TABLE apk_releases ADD KEY idx_apk_hotel_code (hotel_id, version_code);
CREATE TABLE IF NOT EXISTS apk_release_hotels (
  release_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (release_id, hotel_id),
  KEY idx_arh_hotel (hotel_id),
  CONSTRAINT fk_arh_release FOREIGN KEY (release_id) REFERENCES apk_releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_arh_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
