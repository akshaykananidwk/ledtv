-- HotelCast 2.4 — device schedules (docs/modules/device_schedules.md): timed TV actions (volume, input,
-- nightly restart, bells, spoken announcements), the sound library and presence / motion sensors.
-- device_schedules      = tenant table: one row per timed action (time + once / daily / weekly + target).
-- device_schedule_runs  = tenant table: one row per fired occurrence (device_id 0 = the occurrence itself,
--                         device_id > 0 = one TV of a staggered restart, sent at due_at). The unique key makes
--                         firing idempotent: an occurrence is claimed by INSERT IGNORE exactly once.
-- sounds                = tenant table: uploaded mp3 / wav / ogg files (≤ 5 MB) for bell schedules; the three
--                         built-in chimes live in assets/sounds/ and have no row.
-- presence_sensors      = tenant table: motion sensors posting to POST /api/presence (token hash only).
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.
CREATE TABLE IF NOT EXISTS device_schedules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL DEFAULT '',
  action VARCHAR(20) NOT NULL,
  options TEXT NULL,
  run_time TIME NOT NULL,
  repeat_mode ENUM('once','daily','weekly') NOT NULL DEFAULT 'daily',
  run_date DATE NULL,
  days VARCHAR(20) NULL,
  target_type VARCHAR(10) NOT NULL DEFAULT 'all',
  target_ids TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  active_from DATETIME NULL,
  last_fired_for DATETIME NULL,
  last_fired_at DATETIME NULL,
  last_result VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_device_schedules_hotel (hotel_id, is_active, run_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_schedule_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  schedule_id INT UNSIGNED NOT NULL,
  occurrence_at DATETIME NOT NULL,
  device_id INT UNSIGNED NOT NULL DEFAULT 0,
  due_at DATETIME NOT NULL,
  status ENUM('planned','sent','skipped') NOT NULL DEFAULT 'planned',
  note VARCHAR(190) NULL,
  broadcast_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device_schedule_runs (schedule_id, occurrence_at, device_id),
  KEY idx_device_schedule_runs_due (hotel_id, status, due_at),
  CONSTRAINT fk_device_schedule_runs_schedule FOREIGN KEY (schedule_id) REFERENCES device_schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sounds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sounds_hotel (hotel_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presence_sensors (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  target_type VARCHAR(10) NOT NULL DEFAULT 'rooms',
  target_ids TEXT NULL,
  token_hash CHAR(64) NULL,
  token_hint VARCHAR(8) NOT NULL DEFAULT '',
  idle_minutes INT UNSIGNED NOT NULL DEFAULT 10,
  override_schedule TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  state ENUM('idle','on','off') NOT NULL DEFAULT 'idle',
  last_seen DATETIME NULL,
  last_event VARCHAR(20) NULL,
  on_sent_at DATETIME NULL,
  off_sent_at DATETIME NULL,
  events INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_presence_sensors_token (token_hash),
  KEY idx_presence_sensors_hotel (hotel_id, is_active, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proof of play report (admin/play_report.php): plays of one content item in a date range (the other filters use
-- idx_bl_hotel_event (hotel_id, event, created_at) and idx_bl_room (room_id, created_at)).
ALTER TABLE broadcast_logs ADD KEY idx_bl_hotel_content (hotel_id, content_id, created_at);
