-- Phase 14: widen PII columns for authenticated ciphertext. Values are encrypted by PHP on subsequent writes.
-- Existing rows remain plaintext until they are updated by the application or separately migrated.
ALTER TABLE provider_profiles
    MODIFY COLUMN phone TEXT NOT NULL,
    MODIFY COLUMN address TEXT NOT NULL;

ALTER TABLE service_requests
    MODIFY COLUMN address TEXT NULL;
