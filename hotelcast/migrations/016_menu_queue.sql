-- HotelCast 2.3 — Menu board (display app "menu_board") and token / queue system ("queue_display").
-- Idempotent: ADD COLUMN / CREATE TABLE IF NOT EXISTS; "duplicate column" errors are ignored by the
-- Migrator for .sql files. MySQL 8 / MariaDB 10.4+.

-- ------------------------------------------------------------------ menu board
-- The board shares the room-service menu (003: guest_menu_categories / guest_menu_items), so a dish
-- marked "sold out" disappears from the TV board and from room service at the same time.
ALTER TABLE guest_menu_items ADD COLUMN is_sold_out TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE guest_menu_items ADD COLUMN show_on_board TINYINT(1) NOT NULL DEFAULT 1 AFTER is_sold_out;
ALTER TABLE guest_menu_items ADD COLUMN is_special TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_board;
ALTER TABLE guest_menu_items ADD COLUMN badge VARCHAR(20) NULL AFTER is_special;
ALTER TABLE guest_menu_items ADD COLUMN price_old DECIMAL(10,2) NULL AFTER price;
-- Dayparting: a category is shown on menu boards only between board_from and board_to (hotel time).
ALTER TABLE guest_menu_categories ADD COLUMN show_on_board TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active;
ALTER TABLE guest_menu_categories ADD COLUMN board_from TIME NULL AFTER show_on_board;
ALTER TABLE guest_menu_categories ADD COLUMN board_to TIME NULL AFTER board_from;

-- ------------------------------------------------------------------ token / queue system
-- A service ("Dr. Patel – OPD", "Cash counter") issues numbered tokens; numbers restart every day
-- (hotel time zone): last_date / last_number are the per-day counter, updated under a row lock.
CREATE TABLE IF NOT EXISTS queue_services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  prefix VARCHAR(5) NOT NULL DEFAULT '',
  start_number INT UNSIGNED NOT NULL DEFAULT 1,
  self_service TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  last_date DATE NULL,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qs_hotel (hotel_id, is_active, sort_order),
  CONSTRAINT fk_qs_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A counter / desk / cabin. service_id NULL = calls tokens of every service.
CREATE TABLE IF NOT EXISTS queue_counters (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  service_id INT UNSIGNED NULL,
  room_text VARCHAR(120) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_qc_hotel (hotel_id, is_active, sort_order),
  CONSTRAINT fk_qc_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- origin_service_id = the service that issued the number (unique per day); service_id = the queue
-- the token waits in now (changes on "transfer"). queued_at (microseconds) orders the waiting line,
-- so a transferred token always queues behind the tokens already waiting.
CREATE TABLE IF NOT EXISTS queue_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  origin_service_id INT UNSIGNED NOT NULL,
  token_date DATE NOT NULL,
  number INT UNSIGNED NOT NULL,
  prefix VARCHAR(5) NOT NULL DEFAULT '',
  status ENUM('waiting','called','serving','done','skipped','no_show') NOT NULL DEFAULT 'waiting',
  counter_id INT UNSIGNED NULL,
  customer_name VARCHAR(80) NULL,
  customer_phone VARCHAR(20) NULL,
  source ENUM('desk','self') NOT NULL DEFAULT 'desk',
  issued_by INT UNSIGNED NULL,
  ip_address VARCHAR(45) NULL,
  recall_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  queued_at DATETIME(6) NOT NULL,
  called_at DATETIME(6) NULL,
  done_at DATETIME NULL,
  UNIQUE KEY uq_qt_number (origin_service_id, token_date, number),
  KEY idx_qt_wait (hotel_id, token_date, status, service_id, queued_at),
  KEY idx_qt_called (hotel_id, token_date, called_at),
  KEY idx_qt_counter (hotel_id, counter_id, status),
  CONSTRAINT fk_qt_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
