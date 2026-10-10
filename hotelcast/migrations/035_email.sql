-- 2.8 Email (docs/modules/email.md): password reset / invite tokens and the mail log.
-- password_resets: only the SHA-256 of the token is stored; single use (used_at), expires_at (60 min for a
-- reset, 72 h for an invite of a new user). A new request invalidates the older open tokens of the user.
-- mail_log: the last 200 sends (time, recipient, subject, status, error) — never the body.
-- Idempotent: duplicate table errors are ignored by the Migrator.
CREATE TABLE IF NOT EXISTS password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(10) NOT NULL DEFAULT 'reset',
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip VARCHAR(45) NULL,
  UNIQUE KEY uq_pwreset_token (token_hash),
  KEY idx_pwreset_user (user_id, used_at),
  CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient VARCHAR(190) NOT NULL DEFAULT '',
  subject VARCHAR(190) NOT NULL DEFAULT '',
  transport VARCHAR(10) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'sent',
  error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_mail_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
