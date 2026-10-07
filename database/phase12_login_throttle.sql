-- Phase 12: temporary brute-force throttling. Apply after phase11_security_audit.sql.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    first_failed_at DATETIME NOT NULL,
    last_failed_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    UNIQUE KEY uq_login_attempt_identifier_ip (identifier_hash, ip_hash),
    INDEX idx_login_attempt_lock (locked_until),
    INDEX idx_login_attempt_last_failure (last_failed_at)
) ENGINE=InnoDB;
