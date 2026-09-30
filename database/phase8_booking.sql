-- Phase 8 upgrade for the existing bookings table. Run after serveiq.sql.
-- Existing `confirmed` rows are retained and normalized to `accepted`.
ALTER TABLE bookings
    MODIFY response_id INT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS service_id INT UNSIGNED NULL AFTER provider_id,
    ADD COLUMN IF NOT EXISTS scheduled_date DATE NULL AFTER service_id,
    ADD COLUMN IF NOT EXISTS scheduled_time TIME NULL AFTER scheduled_date,
    ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER scheduled_time,
    ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMP NULL AFTER rejection_reason,
    ADD COLUMN IF NOT EXISTS started_at TIMESTAMP NULL AFTER accepted_at,
    ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP NULL AFTER started_at,
    ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL AFTER completed_at;

UPDATE bookings SET status = 'accepted' WHERE status = 'confirmed';
ALTER TABLE bookings MODIFY status ENUM('pending', 'accepted', 'in_progress', 'completed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending';
ALTER TABLE bookings
    ADD CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
    ADD INDEX idx_bookings_status (status),
    ADD INDEX idx_bookings_provider_status (provider_id, status),
    ADD INDEX idx_bookings_customer_status (customer_id, status);

CREATE TABLE IF NOT EXISTS booking_status_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    old_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    note VARCHAR(500) NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_booking_history_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_history_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_booking_history_booking (booking_id, changed_at)
) ENGINE=InnoDB;
