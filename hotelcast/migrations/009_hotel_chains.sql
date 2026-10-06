-- HotelCast 2.0 — hotel chains (#20): one owner, many hotels.
-- New tables only; hotels.chain_id, users.chain_id and the users.role value 'chain_admin' are added by
-- 009_hotel_chains_upgrade.php (runs right after this file, checks information_schema, resumable).
-- None of these tables is a tenant table: chain rows belong to a chain; chain_publications carries an
-- explicit hotel_id and is always queried with chain_id + hotel_id (see core/Chains.php).
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS hotel_chains (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  owner_name VARCHAR(120) NULL,
  owner_email VARCHAR(190) NULL,
  owner_phone VARCHAR(40) NULL,
  reseller_id INT UNSIGNED NULL,
  brand_name VARCHAR(120) NULL,
  brand_logo VARCHAR(255) NULL,
  brand_color VARCHAR(7) NULL,
  notes VARCHAR(1000) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_chains_reseller (reseller_id),
  CONSTRAINT fk_chains_reseller FOREIGN KEY (reseller_id) REFERENCES resellers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chain content library: same columns as content_items; media files live in uploads/chains/c{id}/…
CREATE TABLE IF NOT EXISTS chain_content_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  type ENUM('image','video','stream','timetable','announcement','html','url','youtube','clock') NOT NULL,
  file_path VARCHAR(255) NULL,
  thumb_path VARCHAR(255) NULL,
  mime_type VARCHAR(100) NULL,
  file_size BIGINT UNSIGNED NULL,
  url VARCHAR(1000) NULL,
  body MEDIUMTEXT NULL,
  settings JSON NULL,
  duration INT UNSIGNED NOT NULL DEFAULT 10,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cci_chain (chain_id),
  CONSTRAINT fk_cci_chain FOREIGN KEY (chain_id) REFERENCES hotel_chains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chain_playlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  description VARCHAR(500) NULL,
  transition ENUM('none','fade','slide') NOT NULL DEFAULT 'fade',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cpl_chain (chain_id),
  CONSTRAINT fk_cpl_chain FOREIGN KEY (chain_id) REFERENCES hotel_chains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chain_playlist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT UNSIGNED NOT NULL,
  content_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  duration INT UNSIGNED NULL,
  KEY idx_cpli_playlist (playlist_id, sort_order),
  KEY idx_cpli_content (content_id),
  CONSTRAINT fk_cpli_playlist FOREIGN KEY (playlist_id) REFERENCES chain_playlists(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpli_content FOREIGN KEY (content_id) REFERENCES chain_content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link chain item → copy in a hotel (content_items.id / content_playlists.id of that hotel).
CREATE TABLE IF NOT EXISTS chain_publications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  source_type ENUM('content','playlist') NOT NULL,
  source_id INT UNSIGNED NOT NULL,
  local_id INT UNSIGNED NOT NULL,
  source_file VARCHAR(255) NULL,
  published_by INT UNSIGNED NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cpub_hotel_source (hotel_id, source_type, source_id),
  KEY idx_cpub_chain_source (chain_id, source_type, source_id),
  CONSTRAINT fk_cpub_chain FOREIGN KEY (chain_id) REFERENCES hotel_chains(id) ON DELETE CASCADE,
  CONSTRAINT fk_cpub_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chain-wide settings templates (ticker, overlay, branding) pushed to selected hotels.
CREATE TABLE IF NOT EXISTS chain_setting_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  settings JSON NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cst_chain (chain_id),
  CONSTRAINT fk_cst_chain FOREIGN KEY (chain_id) REFERENCES hotel_chains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History of chain-wide actions (broadcasts, publishes, template pushes) with per-hotel results.
CREATE TABLE IF NOT EXISTS chain_actions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chain_id INT UNSIGNED NOT NULL,
  kind VARCHAR(30) NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  hotel_ids TEXT NULL,
  results TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cact_chain (chain_id, id),
  CONSTRAINT fk_cact_chain FOREIGN KEY (chain_id) REFERENCES hotel_chains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
