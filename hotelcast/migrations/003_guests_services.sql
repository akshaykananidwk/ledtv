-- HotelCast 2.0 — Guests / front desk / PMS (#1 #6 #9 #10) and guest services (#2 #3 #8).
-- Every table is a tenant table (hotel_id + FK hotels), registered in core/boot.d/guests_services.php.
-- Idempotent: CREATE TABLE IF NOT EXISTS only.

-- One row per stay (check-in → check-out). PII (name, phone, notes) is anonymised N days after
-- check-out by core/Tasks/GuestRetentionTask.php (setting guest_retention_days, default 30).
CREATE TABLE IF NOT EXISTS guest_stays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  room_id INT UNSIGNED NULL,
  salutation VARCHAR(20) NOT NULL DEFAULT '',
  guest_name VARCHAR(120) NOT NULL,
  language CHAR(2) NOT NULL DEFAULT 'en',
  phone VARCHAR(30) NULL,
  checkin_at DATETIME NOT NULL,
  expected_checkout_at DATETIME NULL,
  checked_out_at DATETIME NULL,
  source ENUM('manual','pms','api') NOT NULL DEFAULT 'manual',
  external_ref VARCHAR(100) NULL,
  notes VARCHAR(1000) NULL,
  balance_text VARCHAR(255) NULL,
  wifi_password VARCHAR(100) NULL,
  pii_deleted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gs_hotel_room (hotel_id, room_id, checked_out_at),
  KEY idx_gs_hotel_ref (hotel_id, external_ref),
  KEY idx_gs_hotel_out (hotel_id, checked_out_at),
  CONSTRAINT fk_gs_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_gs_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Secret guest token per room (URL /g/{token}); rotated on every check-in and check-out.
CREATE TABLE IF NOT EXISTS guest_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  room_id INT UNSIGNED NOT NULL,
  token VARCHAR(40) NOT NULL,
  stay_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gt_token (token),
  UNIQUE KEY uq_gt_room (hotel_id, room_id),
  CONSTRAINT fk_gt_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_gt_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_menu_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  name_en VARCHAR(100) NOT NULL,
  name_gu VARCHAR(100) NULL,
  name_hi VARCHAR(100) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_gmc_hotel (hotel_id, sort_order),
  CONSTRAINT fk_gmc_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_menu_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  category_id INT UNSIGNED NOT NULL,
  name_en VARCHAR(120) NOT NULL,
  name_gu VARCHAR(120) NULL,
  name_hi VARCHAR(120) NULL,
  description_en VARCHAR(300) NULL,
  description_gu VARCHAR(300) NULL,
  description_hi VARCHAR(300) NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  food_type ENUM('veg','nonveg','egg','none') NOT NULL DEFAULT 'veg',
  photo_path VARCHAR(255) NULL,
  available_from TIME NULL,
  available_to TIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gmi_hotel_cat (hotel_id, category_id, sort_order),
  CONSTRAINT fk_gmi_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_gmi_cat FOREIGN KEY (category_id) REFERENCES guest_menu_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- token_hash = sha256 of the guest token used: the guest page only lists its own orders/requests.
CREATE TABLE IF NOT EXISTS guest_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  room_id INT UNSIGNED NULL,
  stay_id INT UNSIGNED NULL,
  token_hash CHAR(64) NULL,
  status ENUM('new','accepted','preparing','delivered','cancelled') NOT NULL DEFAULT 'new',
  notes VARCHAR(500) NULL,
  item_count INT UNSIGNED NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  language CHAR(2) NOT NULL DEFAULT 'en',
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  accepted_at DATETIME NULL,
  delivered_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_go_hotel_status (hotel_id, status, id),
  KEY idx_go_hotel_created (hotel_id, created_at),
  KEY idx_go_token (token_hash),
  KEY idx_go_stay (stay_id),
  CONSTRAINT fk_go_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_go_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  order_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  food_type VARCHAR(10) NOT NULL DEFAULT 'veg',
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  line_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  KEY idx_goi_order (order_id),
  KEY idx_goi_hotel (hotel_id),
  CONSTRAINT fk_goi_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_goi_order FOREIGN KEY (order_id) REFERENCES guest_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_request_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  code VARCHAR(30) NOT NULL DEFAULT '',
  name_en VARCHAR(100) NOT NULL,
  name_gu VARCHAR(100) NULL,
  name_hi VARCHAR(100) NULL,
  icon VARCHAR(16) NOT NULL DEFAULT '',
  needs_time TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_grt_hotel (hotel_id, sort_order),
  CONSTRAINT fk_grt_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  room_id INT UNSIGNED NULL,
  stay_id INT UNSIGNED NULL,
  token_hash CHAR(64) NULL,
  type_id INT UNSIGNED NULL,
  type_name VARCHAR(100) NOT NULL,
  requested_time DATETIME NULL,
  notes VARCHAR(500) NULL,
  status ENUM('open','done','cancelled') NOT NULL DEFAULT 'open',
  language CHAR(2) NOT NULL DEFAULT 'en',
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  done_at DATETIME NULL,
  done_by INT UNSIGNED NULL,
  KEY idx_gr_hotel_status (hotel_id, status, id),
  KEY idx_gr_hotel_created (hotel_id, created_at),
  KEY idx_gr_token (token_hash),
  CONSTRAINT fk_gr_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_gr_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS guest_feedback (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL DEFAULT 1,
  room_id INT UNSIGNED NULL,
  stay_id INT UNSIGNED NULL,
  token_hash CHAR(64) NULL,
  rating TINYINT UNSIGNED NOT NULL,
  rating_cleanliness TINYINT UNSIGNED NULL,
  rating_staff TINYINT UNSIGNED NULL,
  rating_food TINYINT UNSIGNED NULL,
  comment VARCHAR(2000) NULL,
  language CHAR(2) NOT NULL DEFAULT 'en',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_gf_hotel_created (hotel_id, created_at),
  UNIQUE KEY uq_gf_token (hotel_id, token_hash),
  CONSTRAINT fk_gf_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id),
  CONSTRAINT fk_gf_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
