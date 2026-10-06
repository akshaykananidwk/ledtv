-- HotelCast 2.0 — online sign-up + free trial (#17) and demo mode (#21).
--  * hotels.is_trial      : hotel created by the public sign-up, still on its free trial
--  * hotels.demo_kind     : NULL | 'public' (the shared public demo hotel) | 'client' (private demo copy for a prospect)
--  * hotels.demo_purged_at: client demo expired and its data was purged
--  * signups              : public sign-up requests (platform level, NOT a tenant table)
-- Idempotent: CREATE TABLE IF NOT EXISTS; duplicate column / key errors (1060 / 1061) are ignored by the
-- Migrator. MySQL 8 / MariaDB 10.4+.

ALTER TABLE hotels ADD COLUMN is_trial TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE hotels ADD COLUMN demo_kind VARCHAR(10) NULL;
ALTER TABLE hotels ADD COLUMN demo_purged_at DATETIME NULL;
ALTER TABLE hotels ADD KEY idx_hotels_trial (is_trial, status);
ALTER TABLE hotels ADD KEY idx_hotels_demo (demo_kind);

-- status: verify (waiting for the e-mail OTP) → pending (manual approval) → approved | rejected | expired.
-- password_hash is the owner's chosen password (bcrypt), kept only until the hotel is created / rejected.
-- otp_hash is a bcrypt hash of the 6-digit code (never stored in clear), valid until otp_expires_at.
CREATE TABLE IF NOT EXISTS signups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  status ENUM('verify','pending','approved','rejected','expired') NOT NULL DEFAULT 'verify',
  hotel_name VARCHAR(120) NOT NULL,
  city VARCHAR(80) NULL,
  owner_name VARCHAR(120) NOT NULL,
  mobile VARCHAR(20) NOT NULL,
  email VARCHAR(190) NOT NULL,
  tv_estimate SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  language CHAR(2) NOT NULL DEFAULT 'en',
  password_hash VARCHAR(255) NULL,
  otp_hash VARCHAR(255) NULL,
  otp_expires_at DATETIME NULL,
  otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  otp_sends TINYINT UNSIGNED NOT NULL DEFAULT 0,
  duplicate TINYINT(1) NOT NULL DEFAULT 0,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  terms_accepted_at DATETIME NULL,
  verified_at DATETIME NULL,
  hotel_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  trial_ends_at DATETIME NULL,
  upgrade_plan_id INT UNSIGNED NULL,
  upgrade_invoice_id INT UNSIGNED NULL,
  upgrade_requested_at DATETIME NULL,
  converted_at DATETIME NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  reject_reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_signups_status (status, created_at),
  KEY idx_signups_email (email),
  KEY idx_signups_mobile (mobile),
  KEY idx_signups_hotel (hotel_id),
  CONSTRAINT fk_signups_hotel FOREIGN KEY (hotel_id) REFERENCES hotels(id) ON DELETE SET NULL,
  CONSTRAINT fk_signups_plan FOREIGN KEY (upgrade_plan_id) REFERENCES plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
