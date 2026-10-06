-- HotelCast 2.0 — Ad marketplace (#19): local businesses (advertisers) book TV ad space in hotels that opt in;
-- hotels earn a revenue share. Advertisers are NOT hotel users (own tables, own session, portal /advertise/).
-- When a booking is paid and a hotel approves it, the ad becomes a normal sponsor + content item + ad_campaign
-- inside that hotel (existing Ads module), linked through mkt_booking_hotels.campaign_id.
-- Money: DECIMAL(12,2), computed server-side in integer paise (core/Marketplace.php).
-- Idempotent: CREATE TABLE IF NOT EXISTS. MySQL 8 / MariaDB 10.4+.

CREATE TABLE IF NOT EXISTS mkt_advertisers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  business_name VARCHAR(150) NOT NULL,
  contact_name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  city VARCHAR(80) NULL,
  gstin VARCHAR(20) NULL,
  status ENUM('unverified','pending','active','suspended') NOT NULL DEFAULT 'unverified',
  status_note VARCHAR(255) NULL,
  email_verified_at DATETIME NULL,
  otp_hash CHAR(64) NULL,
  otp_expires_at DATETIME NULL,
  otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  language VARCHAR(5) NOT NULL DEFAULT 'en',
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mkt_adv_email (email),
  KEY idx_mkt_adv_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mkt_advertiser_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advertiser_id INT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  last_activity DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_mkt_sess_hash (session_hash),
  KEY idx_mkt_sess_adv (advertiser_id),
  CONSTRAINT fk_mkt_sess_adv FOREIGN KEY (advertiser_id) REFERENCES mkt_advertisers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-hotel opt-in and pricing (tenant table, one row per hotel).
CREATE TABLE IF NOT EXISTS mkt_hotel_settings (
  hotel_id INT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  pricing_model ENUM('per_day','cpm','both') NOT NULL DEFAULT 'per_day',
  price_per_tv_day DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  price_cpm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  max_ads_per_loop TINYINT UNSIGNED NOT NULL DEFAULT 2,
  allowed_categories JSON NULL,
  blocked_categories JSON NULL,
  approval ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  description VARCHAR(500) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mkt_hs_enabled (enabled),
  CONSTRAINT fk_mkt_hs_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Creatives uploaded by an advertiser (files under uploads/adv/a{id}/…); copied into a hotel's media folder
-- when a campaign is created there.
CREATE TABLE IF NOT EXISTS mkt_creatives (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  advertiser_id INT UNSIGNED NOT NULL,
  type ENUM('image','video','text') NOT NULL,
  title VARCHAR(150) NOT NULL,
  file_path VARCHAR(255) NULL,
  thumb_path VARCHAR(255) NULL,
  mime_type VARCHAR(100) NULL,
  file_size BIGINT UNSIGNED NULL,
  body VARCHAR(500) NULL,
  settings JSON NULL,
  duration SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  status ENUM('active','rejected','deleted') NOT NULL DEFAULT 'active',
  reject_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_mkt_cr_adv (advertiser_id, status),
  CONSTRAINT fk_mkt_cr_adv FOREIGN KEY (advertiser_id) REFERENCES mkt_advertisers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ad order (invoice-like). Status flow: draft → submitted → awaiting_payment → paid → scheduled → running
-- → completed; rejected / cancelled with reason and refund note.
CREATE TABLE IF NOT EXISTS mkt_bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(30) NULL,
  advertiser_id INT UNSIGNED NOT NULL,
  creative_id INT UNSIGNED NULL,
  title VARCHAR(150) NOT NULL,
  category VARCHAR(40) NOT NULL,
  pricing_model ENUM('per_day','cpm') NOT NULL DEFAULT 'per_day',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  daily_start TIME NULL,
  daily_end TIME NULL,
  impressions INT UNSIGNED NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  status ENUM('draft','submitted','awaiting_payment','paid','scheduled','running','completed','rejected','cancelled') NOT NULL DEFAULT 'draft',
  status_reason VARCHAR(500) NULL,
  refund_note VARCHAR(500) NULL,
  payment_ref VARCHAR(120) NULL,
  payment_method VARCHAR(40) NULL,
  paid_at DATETIME NULL,
  paid_by INT UNSIGNED NULL,
  submitted_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mkt_bk_number (number),
  KEY idx_mkt_bk_adv (advertiser_id, status),
  KEY idx_mkt_bk_status (status, start_date),
  CONSTRAINT fk_mkt_bk_adv FOREIGN KEY (advertiser_id) REFERENCES mkt_advertisers(id) ON DELETE CASCADE,
  CONSTRAINT fk_mkt_bk_cr FOREIGN KEY (creative_id) REFERENCES mkt_creatives(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One line per booked hotel (tenant table). campaign_id = the ad_campaigns row created in that hotel.
CREATE TABLE IF NOT EXISTS mkt_booking_hotels (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NOT NULL,
  tv_count INT UNSIGNED NOT NULL DEFAULT 0,
  days INT UNSIGNED NOT NULL DEFAULT 0,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  impressions INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  hotel_share_pct DECIMAL(5,2) NOT NULL DEFAULT 70.00,
  hotel_share DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  platform_share DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('pending','approved','rejected','cancelled','delivered') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NULL,
  decided_at DATETIME NULL,
  decided_by INT UNSIGNED NULL,
  sponsor_id INT UNSIGNED NULL,
  content_id INT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  payout_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mkt_bh (booking_id, hotel_id),
  KEY idx_mkt_bh_hotel (hotel_id, status),
  KEY idx_mkt_bh_campaign (campaign_id),
  KEY idx_mkt_bh_payout (payout_id),
  CONSTRAINT fk_mkt_bh_booking FOREIGN KEY (booking_id) REFERENCES mkt_bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_mkt_bh_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status history of a booking (who / when / why).
CREATE TABLE IF NOT EXISTS mkt_booking_events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id INT UNSIGNED NOT NULL,
  hotel_id INT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL,
  note VARCHAR(500) NULL,
  actor VARCHAR(60) NOT NULL DEFAULT 'system',
  created_at DATETIME NOT NULL,
  KEY idx_mkt_ev_booking (booking_id, id),
  CONSTRAINT fk_mkt_ev_booking FOREIGN KEY (booking_id) REFERENCES mkt_bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Monthly payout statements to hotels (revenue share of paid, approved lines). Tenant table.
CREATE TABLE IF NOT EXISTS mkt_payouts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hotel_id INT UNSIGNED NOT NULL,
  period CHAR(7) NOT NULL,
  lines_count INT UNSIGNED NOT NULL DEFAULT 0,
  gross DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
  payment_ref VARCHAR(120) NULL,
  paid_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mkt_payout (hotel_id, period),
  CONSTRAINT fk_mkt_po_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
