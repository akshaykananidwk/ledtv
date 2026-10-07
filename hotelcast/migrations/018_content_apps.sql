-- HotelCast 2.3 — content display apps: photo album (#19) tables.
-- albums       = photo albums of a hotel (staff upload from the phone, optional guest upload link)
-- album_photos = photos of an album; status 'pending' = uploaded by a guest, waiting for approval
-- Both are tenant tables (hotel_id). Showcase (#9), event welcome (#10), sheet table (#14) and
-- social wall (#16) keep their settings in content_items (type 'app') and need no table.
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.
CREATE TABLE IF NOT EXISTS albums (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  guest_upload TINYINT(1) NOT NULL DEFAULT 0,
  guest_moderation TINYINT(1) NOT NULL DEFAULT 1,
  guest_max INT UNSIGNED NOT NULL DEFAULT 100,
  guest_key VARCHAR(32) NOT NULL DEFAULT '',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_albums_hotel (hotel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS album_photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  album_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  thumb_path VARCHAR(255) NULL,
  caption VARCHAR(190) NULL,
  status ENUM('approved','pending') NOT NULL DEFAULT 'approved',
  source ENUM('staff','guest') NOT NULL DEFAULT 'staff',
  guest_name VARCHAR(80) NULL,
  uploader_ip VARCHAR(45) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_album_photos_album (hotel_id, album_id, status, sort_order),
  CONSTRAINT fk_album_photos_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
