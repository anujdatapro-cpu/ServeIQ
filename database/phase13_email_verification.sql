-- Phase 13: email verification challenge storage. Existing accounts are retained as verified.
-- MySQL 8.0 does not support ADD COLUMN IF NOT EXISTS. Make the upgrade safe
-- when the column was already added manually or by an earlier partial run.
SET @email_verified_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'is_email_verified'
);
SET @email_verified_ddl = IF(
    @email_verified_column_exists = 0,
    'ALTER TABLE users ADD COLUMN is_email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER role',
    'SELECT 1'
);
PREPARE email_verified_stmt FROM @email_verified_ddl;
EXECUTE email_verified_stmt;
DEALLOCATE PREPARE email_verified_stmt;

CREATE TABLE IF NOT EXISTS email_verifications (
    user_id INT UNSIGNED PRIMARY KEY,
    otp_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    resend_after DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_email_verification_expiry (expires_at)
) ENGINE=InnoDB;
