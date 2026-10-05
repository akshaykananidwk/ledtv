-- HotelCast initial schema
-- MySQL 8 / MariaDB 10.6, utf8mb4 throughout (English + Gujarati)

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(190) NOT NULL,
  full_name VARCHAR(120) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','manager','staff') NOT NULL DEFAULT 'staff',
  language VARCHAR(5) NOT NULL DEFAULT 'en',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_session_hash (session_hash),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
  KEY idx_content_type (type),
  KEY idx_content_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_playlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  description VARCHAR(500) NULL,
  transition ENUM('none','fade','slide') NOT NULL DEFAULT 'fade',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS playlist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT UNSIGNED NOT NULL,
  content_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  duration INT UNSIGNED NULL,
  KEY idx_pli_playlist (playlist_id, sort_order),
  KEY idx_pli_content (content_id),
  CONSTRAINT fk_pli_playlist FOREIGN KEY (playlist_id) REFERENCES content_playlists(id) ON DELETE CASCADE,
  CONSTRAINT fk_pli_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  type ENUM('floor','zone','custom') NOT NULL DEFAULT 'custom',
  description VARCHAR(500) NULL,
  content_id INT UNSIGNED NULL,
  playlist_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_group_name (name),
  CONSTRAINT fk_group_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_group_playlist FOREIGN KEY (playlist_id) REFERENCES content_playlists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  room_number VARCHAR(20) NOT NULL,
  name VARCHAR(120) NULL,
  floor VARCHAR(20) NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  content_id INT UNSIGNED NULL,
  playlist_id INT UNSIGNED NULL,
  settings_pin VARCHAR(10) NULL,
  notes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_room_number (room_number),
  KEY idx_room_floor (floor),
  CONSTRAINT fk_room_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_room_playlist FOREIGN KEY (playlist_id) REFERENCES content_playlists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS room_group_members (
  room_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (room_id, group_id),
  KEY idx_rgm_group (group_id),
  CONSTRAINT fk_rgm_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_rgm_group FOREIGN KEY (group_id) REFERENCES room_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_uid VARCHAR(64) NOT NULL,
  room_id INT UNSIGNED NULL,
  token_hash CHAR(64) NOT NULL,
  app_version VARCHAR(20) NULL,
  app_version_code INT UNSIGNED NULL,
  android_version VARCHAR(20) NULL,
  model VARCHAR(100) NULL,
  ip_address VARCHAR(45) NULL,
  public_ip VARCHAR(45) NULL,
  battery SMALLINT NULL,
  network_type VARCHAR(20) NULL,
  wifi_signal SMALLINT NULL,
  free_storage_mb INT NULL,
  screen_on TINYINT(1) NOT NULL DEFAULT 1,
  uptime_sec INT UNSIGNED NULL,
  current_hash CHAR(40) NULL,
  current_item_id INT UNSIGNED NULL,
  status ENUM('online','offline') NOT NULL DEFAULT 'offline',
  is_revoked TINYINT(1) NOT NULL DEFAULT 0,
  offline_notified TINYINT(1) NOT NULL DEFAULT 0,
  last_ping DATETIME NULL,
  last_heartbeat DATETIME NULL,
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device_uid (device_uid),
  KEY idx_device_room (room_id),
  KEY idx_device_ping (last_ping),
  CONSTRAINT fk_device_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS broadcast_commands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL DEFAULT '',
  command ENUM('SHOW_CONTENT','EMERGENCY','REBOOT','CLEAR_CACHE','UPDATE_APP','SCREEN_OFF','SCREEN_ON','RELOAD','PING') NOT NULL,
  target_type ENUM('all','rooms','groups','floors') NOT NULL DEFAULT 'all',
  target_ids JSON NULL,
  content_id INT UNSIGNED NULL,
  playlist_id INT UNSIGNED NULL,
  payload JSON NULL,
  is_emergency TINYINT(1) NOT NULL DEFAULT 0,
  mode ENUM('now','once','window') NOT NULL DEFAULT 'now',
  status ENUM('scheduled','active','completed','cancelled') NOT NULL DEFAULT 'active',
  start_at DATETIME NULL,
  end_at DATETIME NULL,
  daily_start TIME NULL,
  daily_end TIME NULL,
  repeat_days VARCHAR(20) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bc_status (status, start_at),
  KEY idx_bc_emergency (is_emergency, status),
  CONSTRAINT fk_bc_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_bc_playlist FOREIGN KEY (playlist_id) REFERENCES content_playlists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_commands (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
  broadcast_id INT UNSIGNED NULL,
  command VARCHAR(30) NOT NULL,
  payload JSON NULL,
  status ENUM('pending','delivered','acked','failed','expired') NOT NULL DEFAULT 'pending',
  message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  acked_at DATETIME NULL,
  KEY idx_dc_device_status (device_id, status),
  KEY idx_dc_created (created_at),
  CONSTRAINT fk_dc_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS broadcast_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  broadcast_id INT UNSIGNED NULL,
  device_id INT UNSIGNED NULL,
  room_id INT UNSIGNED NULL,
  content_id INT UNSIGNED NULL,
  event VARCHAR(30) NOT NULL,
  message VARCHAR(500) NULL,
  duration_sec INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bl_broadcast (broadcast_id),
  KEY idx_bl_room (room_id, created_at),
  KEY idx_bl_content (content_id),
  KEY idx_bl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS device_status_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NULL,
  status ENUM('online','offline') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dsl_device (device_id, created_at),
  KEY idx_dsl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS apk_releases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_name VARCHAR(30) NOT NULL,
  version_code INT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  notes TEXT NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_apk_code (version_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS update_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action ENUM('update','rollback') NOT NULL DEFAULT 'update',
  from_version VARCHAR(30) NULL,
  to_version VARCHAR(30) NULL,
  commit_hash VARCHAR(64) NULL,
  status ENUM('running','success','failed','rolled_back') NOT NULL DEFAULT 'running',
  backup_file VARCHAR(255) NULL,
  log MEDIUMTEXT NULL,
  started_by INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY idx_uh_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value MEDIUMTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  username VARCHAR(50) NULL,
  action VARCHAR(60) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id INT UNSIGNED NULL,
  details VARCHAR(1000) NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_al_user (user_id, created_at),
  KEY idx_al_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rl_key VARCHAR(190) NOT NULL PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_start INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_ip (ip_address, created_at),
  KEY idx_la_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
