-- HotelCast 2.3 — slide designer (#11) and PDF → slides import (#12). Tenant tables (hotel_id).
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.

-- Editable design (fabric.js JSON) of a designer slide. kind = design: belongs to an `image` content
-- item (the exported 1920×1080 PNG); kind = template: a hotel's own "Save as template" (no content item).
CREATE TABLE IF NOT EXISTS designs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  content_id INT UNSIGNED NULL,
  kind ENUM('design','template') NOT NULL DEFAULT 'design',
  name VARCHAR(190) NOT NULL DEFAULT '',
  data MEDIUMTEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_designs_content (content_id),
  KEY idx_designs_hotel (hotel_id, kind),
  CONSTRAINT fk_designs_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One browser-side PDF conversion (batch id chosen by the browser) → its page images and optional playlist.
CREATE TABLE IF NOT EXISTS pdf_imports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  batch_id CHAR(32) NOT NULL,
  file_name VARCHAR(190) NOT NULL DEFAULT '',
  playlist_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pdf_imports_batch (hotel_id, batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (import, page) → content item: a retried page upload returns the existing item instead of a duplicate.
CREATE TABLE IF NOT EXISTS pdf_import_pages (
  hotel_id INT UNSIGNED NOT NULL,
  import_id INT UNSIGNED NOT NULL,
  page SMALLINT UNSIGNED NOT NULL,
  content_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (import_id, page),
  KEY idx_pdf_pages_hotel (hotel_id),
  CONSTRAINT fk_pdf_pages_import FOREIGN KEY (import_id) REFERENCES pdf_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_pdf_pages_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
