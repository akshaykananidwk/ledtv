-- HotelCast 2.4 — synchronized playback (#37) and video walls (#36). See docs/modules/video_wall_sync.md.
-- Idempotent: CREATE TABLE IF NOT EXISTS; duplicate-column errors (1060) are ignored by the Migrator.

-- #37: a playlist can be marked "Sync playback": every TV showing it shows the same item at the same
-- moment. sync_epoch_ms = start of the schedule (server time, ms), reset when the playlist is saved.
ALTER TABLE content_playlists ADD COLUMN sync_playback TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE content_playlists ADD COLUMN sync_epoch_ms BIGINT UNSIGNED NULL;

-- #36: N×M TVs (up to 4×4) acting as one screen. Plays one content item or one playlist, always synced.
-- bezel_mm = gap between the picture areas of two neighbouring TVs (both bezels together); with the
-- visible picture size (screen_w_mm × screen_h_mm) it gives the bezel compensation sent to the TVs.
CREATE TABLE IF NOT EXISTS video_walls (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  rows_count TINYINT UNSIGNED NOT NULL DEFAULT 2,
  cols_count TINYINT UNSIGNED NOT NULL DEFAULT 2,
  bezel_mm DECIMAL(6,2) NOT NULL DEFAULT 0,
  screen_w_mm DECIMAL(7,2) NOT NULL DEFAULT 0,
  screen_h_mm DECIMAL(7,2) NOT NULL DEFAULT 0,
  content_id INT UNSIGNED NULL,
  playlist_id INT UNSIGNED NULL,
  audio_row TINYINT UNSIGNED NOT NULL DEFAULT 0,
  audio_col TINYINT UNSIGNED NOT NULL DEFAULT 0,
  sync_epoch_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_video_walls_hotel (hotel_id),
  CONSTRAINT fk_video_walls_content FOREIGN KEY (content_id) REFERENCES content_items(id) ON DELETE SET NULL,
  CONSTRAINT fk_video_walls_playlist FOREIGN KEY (playlist_id) REFERENCES content_playlists(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One TV (room) per tile; a room belongs to at most one wall; one room per tile.
CREATE TABLE IF NOT EXISTS video_wall_tiles (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  wall_id INT UNSIGNED NOT NULL,
  room_id INT UNSIGNED NOT NULL,
  row_index TINYINT UNSIGNED NOT NULL,
  col_index TINYINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_wall_tiles_room (room_id),
  UNIQUE KEY uq_wall_tiles_cell (wall_id, row_index, col_index),
  KEY idx_wall_tiles_hotel (hotel_id),
  CONSTRAINT fk_wall_tiles_wall FOREIGN KEY (wall_id) REFERENCES video_walls(id) ON DELETE CASCADE,
  CONSTRAINT fk_wall_tiles_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
