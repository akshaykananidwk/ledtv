-- HotelCast 2.4 — scheduling (#31 calendar, #32 content approval, #33 content expiry, #34 playlist
-- dayparting, #35 holiday calendar). See docs/modules/scheduling.md.
-- Idempotent: "duplicate column / key" and "table exists" errors are ignored by the Migrator.

-- #32 Approval workflow. Every existing row (and every row inserted by code that does not know about
-- approvals) is 'approved' through the column default, so 2.3 content keeps playing unchanged.
ALTER TABLE content_items ADD COLUMN approval_status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'approved';
ALTER TABLE content_items ADD COLUMN approval_note VARCHAR(500) NULL;
ALTER TABLE content_items ADD COLUMN submitted_by INT UNSIGNED NULL;
ALTER TABLE content_items ADD COLUMN reviewed_by INT UNSIGNED NULL;
ALTER TABLE content_items ADD COLUMN reviewed_at DATETIME NULL;
-- #33 Validity window (hotel local time, like every DATETIME of the app). NULL = no limit.
ALTER TABLE content_items ADD COLUMN valid_from DATETIME NULL;
ALTER TABLE content_items ADD COLUMN valid_to DATETIME NULL;
-- valid_to value the "expires soon" warning was sent for (re-armed when valid_to changes).
ALTER TABLE content_items ADD COLUMN expiry_warned_for DATETIME NULL;
ALTER TABLE content_items ADD KEY idx_content_approval (hotel_id, approval_status);
UPDATE content_items SET approval_status = 'approved' WHERE approval_status IS NULL OR approval_status = '';

-- #32 A staff edit of APPROVED content is kept here until a manager approves it; meanwhile the
-- approved version stays on air. One open revision per item (draft / pending / rejected).
CREATE TABLE IF NOT EXISTS content_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  content_id INT UNSIGNED NOT NULL,
  data MEDIUMTEXT NOT NULL,
  status ENUM('draft','pending','rejected') NOT NULL DEFAULT 'pending',
  note VARCHAR(500) NULL,
  submitted_by INT UNSIGNED NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_content_revisions_content (content_id),
  KEY idx_content_revisions_hotel (hotel_id, status),
  CONSTRAINT fk_content_revisions_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- #34 Dayparting per playlist item: daily time window (overnight allowed) and weekdays (1 = Mon … 7 = Sun).
ALTER TABLE playlist_items ADD COLUMN daypart_from TIME NULL;
ALTER TABLE playlist_items ADD COLUMN daypart_to TIME NULL;
ALTER TABLE playlist_items ADD COLUMN daypart_days VARCHAR(20) NULL;

-- #35 Holiday calendar: on these dates targeted TVs switch off, show content, or nothing (marker only).
CREATE TABLE IF NOT EXISTS holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  action ENUM('none','tv_off','show_content') NOT NULL DEFAULT 'none',
  content_id INT UNSIGNED NULL,
  playlist_id INT UNSIGNED NULL,
  target_type ENUM('all','rooms','groups','floors') NOT NULL DEFAULT 'all',
  target_ids JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_holidays_dates (hotel_id, start_date, end_date),
  CONSTRAINT fk_holidays_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_holidays_playlist FOREIGN KEY (playlist_id) REFERENCES content_playlists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
