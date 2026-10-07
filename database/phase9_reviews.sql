-- Phase 9 upgrade for reviews. Safe to rerun against a partially upgraded MySQL 8.0 schema.
-- Adds moderation metadata without replacing reviews or changing their existing visibility.

SET @serveiq_reviews_has_status = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'status'
);
SET @serveiq_reviews_ddl = IF(
    @serveiq_reviews_has_status = 0,
    'ALTER TABLE reviews ADD COLUMN status ENUM(''published'', ''hidden'') NOT NULL DEFAULT ''published'' AFTER review',
    'SELECT 1'
);
PREPARE serveiq_reviews_stmt FROM @serveiq_reviews_ddl;
EXECUTE serveiq_reviews_stmt;
DEALLOCATE PREPARE serveiq_reviews_stmt;

SET @serveiq_reviews_has_note = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'moderation_note'
);
SET @serveiq_reviews_ddl = IF(
    @serveiq_reviews_has_note = 0,
    'ALTER TABLE reviews ADD COLUMN moderation_note VARCHAR(500) NULL AFTER status',
    'SELECT 1'
);
PREPARE serveiq_reviews_stmt FROM @serveiq_reviews_ddl;
EXECUTE serveiq_reviews_stmt;
DEALLOCATE PREPARE serveiq_reviews_stmt;

SET @serveiq_reviews_has_moderator = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'moderated_by'
);
SET @serveiq_reviews_ddl = IF(
    @serveiq_reviews_has_moderator = 0,
    'ALTER TABLE reviews ADD COLUMN moderated_by INT UNSIGNED NULL AFTER moderation_note',
    'SELECT 1'
);
PREPARE serveiq_reviews_stmt FROM @serveiq_reviews_ddl;
EXECUTE serveiq_reviews_stmt;
DEALLOCATE PREPARE serveiq_reviews_stmt;

SET @serveiq_reviews_has_moderated_at = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'moderated_at'
);
SET @serveiq_reviews_ddl = IF(
    @serveiq_reviews_has_moderated_at = 0,
    'ALTER TABLE reviews ADD COLUMN moderated_at TIMESTAMP NULL AFTER moderated_by',
    'SELECT 1'
);
PREPARE serveiq_reviews_stmt FROM @serveiq_reviews_ddl;
EXECUTE serveiq_reviews_stmt;
DEALLOCATE PREPARE serveiq_reviews_stmt;

SET @serveiq_reviews_has_moderator_fk = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews'
      AND CONSTRAINT_NAME = 'fk_reviews_moderator' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @serveiq_reviews_ddl = IF(
    @serveiq_reviews_has_moderator_fk = 0,
    'ALTER TABLE reviews ADD CONSTRAINT fk_reviews_moderator FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE serveiq_reviews_stmt FROM @serveiq_reviews_ddl;
EXECUTE serveiq_reviews_stmt;
DEALLOCATE PREPARE serveiq_reviews_stmt;

SET @serveiq_reviews_has_customer_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'idx_reviews_customer'
);
SET @serveiq_reviews_ddl = IF(
    @serveiq_reviews_has_customer_index = 0,
    'ALTER TABLE reviews ADD INDEX idx_reviews_customer (customer_id)',
    'SELECT 1'
);
PREPARE serveiq_reviews_stmt FROM @serveiq_reviews_ddl;
EXECUTE serveiq_reviews_stmt;
DEALLOCATE PREPARE serveiq_reviews_stmt;

SET @serveiq_reviews_has_status_index = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'idx_reviews_status_provider'
);
SET @serveiq_reviews_ddl = IF(
    @serveiq_reviews_has_status_index = 0,
    'ALTER TABLE reviews ADD INDEX idx_reviews_status_provider (provider_id, status)',
    'SELECT 1'
);
PREPARE serveiq_reviews_stmt FROM @serveiq_reviews_ddl;
EXECUTE serveiq_reviews_stmt;
DEALLOCATE PREPARE serveiq_reviews_stmt;
