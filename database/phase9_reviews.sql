-- Phase 9 upgrade for reviews table. Run after serveiq.sql and phase8_booking.sql.
-- Enhances the reviews table with moderation fields, moderator tracking, and performance indexes.

ALTER TABLE reviews
    ADD COLUMN IF NOT EXISTS status ENUM('published', 'hidden') NOT NULL DEFAULT 'published' AFTER review,
    ADD COLUMN IF NOT EXISTS moderation_note VARCHAR(500) NULL AFTER status,
    ADD COLUMN IF NOT EXISTS moderated_by INT UNSIGNED NULL AFTER moderation_note,
    ADD COLUMN IF NOT EXISTS moderated_at TIMESTAMP NULL AFTER moderated_by;

ALTER TABLE reviews
    ADD CONSTRAINT fk_reviews_moderator FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD INDEX idx_reviews_customer (customer_id),
    ADD INDEX idx_reviews_status_provider (provider_id, status);
