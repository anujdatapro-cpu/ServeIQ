-- Phase 8 booking workflow upgrade. Safe to rerun on MySQL 8.0.
-- Adds nullable workflow fields while preserving existing booking records.

SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='service_id')=0,
 'ALTER TABLE bookings ADD COLUMN service_id INT UNSIGNED NULL AFTER provider_id','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='scheduled_date')=0,
 'ALTER TABLE bookings ADD COLUMN scheduled_date DATE NULL AFTER service_id','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='scheduled_time')=0,
 'ALTER TABLE bookings ADD COLUMN scheduled_time TIME NULL AFTER scheduled_date','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='notes')=0,
 'ALTER TABLE bookings ADD COLUMN notes TEXT NULL AFTER scheduled_time','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='rejection_reason')=0,
 'ALTER TABLE bookings ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER notes','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='accepted_at')=0,
 'ALTER TABLE bookings ADD COLUMN accepted_at TIMESTAMP NULL AFTER rejection_reason','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='started_at')=0,
 'ALTER TABLE bookings ADD COLUMN started_at TIMESTAMP NULL AFTER accepted_at','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='completed_at')=0,
 'ALTER TABLE bookings ADD COLUMN completed_at TIMESTAMP NULL AFTER started_at','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='cancelled_at')=0,
 'ALTER TABLE bookings ADD COLUMN cancelled_at TIMESTAMP NULL AFTER completed_at','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;

-- Temporarily include both legacy and current values before mapping legacy rows.
ALTER TABLE bookings MODIFY COLUMN status ENUM('pending','confirmed','accepted','rejected','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending';
UPDATE bookings SET status='accepted' WHERE status='confirmed';
ALTER TABLE bookings MODIFY COLUMN status ENUM('pending','accepted','rejected','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending';

SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND COLUMN_NAME='response_id')>0,
 'ALTER TABLE bookings MODIFY COLUMN response_id INT UNSIGNED NULL','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;

SET @serveiq_booking_ddl = IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND CONSTRAINT_NAME='fk_bookings_service' AND CONSTRAINT_TYPE='FOREIGN KEY')=0,
 'ALTER TABLE bookings ADD CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND INDEX_NAME='idx_bookings_status');
SET @serveiq_booking_ddl = IF(@serveiq_booking_index_exists=0,'ALTER TABLE bookings ADD INDEX idx_bookings_status (status)','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND INDEX_NAME='idx_bookings_provider_status');
SET @serveiq_booking_ddl = IF(@serveiq_booking_index_exists=0,'ALTER TABLE bookings ADD INDEX idx_bookings_provider_status (provider_id,status)','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;
SET @serveiq_booking_index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bookings' AND INDEX_NAME='idx_bookings_customer_status');
SET @serveiq_booking_ddl = IF(@serveiq_booking_index_exists=0,'ALTER TABLE bookings ADD INDEX idx_bookings_customer_status (customer_id,status)','SELECT 1');
PREPARE serveiq_booking_stmt FROM @serveiq_booking_ddl; EXECUTE serveiq_booking_stmt; DEALLOCATE PREPARE serveiq_booking_stmt;

CREATE TABLE IF NOT EXISTS booking_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    note VARCHAR(500) NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_booking_history_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_booking_history_booking (booking_id, changed_at)
) ENGINE=InnoDB;
