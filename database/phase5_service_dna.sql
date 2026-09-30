-- Phase 5 migration for an existing ServeIQ installation.
-- Run once after serveiq.sql. The canonical schema contains these columns too.
ALTER TABLE problem_fingerprints
    ADD COLUMN problem_type VARCHAR(120) NULL AFTER detected_category_id,
    ADD COLUMN affected_entity VARCHAR(120) NULL AFTER problem_type,
    ADD COLUMN context JSON NULL AFTER symptoms,
    ADD COLUMN keywords JSON NULL AFTER context,
    ADD COLUMN possible_service_types JSON NULL AFTER keywords,
    ADD COLUMN location_context JSON NULL AFTER possible_service_types,
    ADD COLUMN confidence_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER location_context,
    ADD COLUMN user_urgency ENUM('low', 'medium', 'high', 'emergency') NOT NULL DEFAULT 'medium' AFTER confidence_score,
    ADD COLUMN detected_urgency ENUM('low', 'medium', 'high', 'emergency') NULL AFTER user_urgency,
    ADD COLUMN evidence JSON NULL AFTER detected_urgency,
    ADD COLUMN analysis_method VARCHAR(40) NOT NULL DEFAULT 'rule_based_v1' AFTER engine_version,
    ADD COLUMN version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER analysis_method;