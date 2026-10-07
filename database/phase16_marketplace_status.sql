-- Phase 16: separate marketplace visibility from verification and availability.
-- Existing providers start inactive until the activation selection script runs.
ALTER TABLE provider_profiles
    ADD COLUMN marketplace_active TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_status,
    ADD INDEX idx_provider_marketplace_status (marketplace_active, verification_status, availability_status);
