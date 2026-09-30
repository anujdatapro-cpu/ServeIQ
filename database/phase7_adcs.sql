-- Phase 7 migration for an existing ServeIQ installation.
-- Run after phase6_matching.sql. This migration does not modify requests, ServiceDNA, or matching results.
CREATE TABLE IF NOT EXISTS provider_assessments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    matching_result_id INT UNSIGNED NULL,
    problem_type VARCHAR(120) NOT NULL,
    affected_entity VARCHAR(120) NULL,
    symptoms JSON NOT NULL,
    suggested_service_types JSON NOT NULL,
    urgency ENUM('low', 'medium', 'high', 'emergency') NOT NULL,
    estimated_severity ENUM('low', 'medium', 'high', 'critical') NOT NULL,
    assessment_notes TEXT NULL,
    assessment_confidence TINYINT UNSIGNED NOT NULL,
    status ENUM('submitted', 'updated', 'withdrawn') NOT NULL DEFAULT 'submitted',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_provider_assessments_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_provider_assessments_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_provider_assessments_matching FOREIGN KEY (matching_result_id) REFERENCES matching_results(id) ON DELETE SET NULL,
    CONSTRAINT uq_provider_assessments_request_provider UNIQUE (request_id, provider_id),
    INDEX idx_provider_assessments_request_status (request_id, status),
    INDEX idx_provider_assessments_provider (provider_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS adcs_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL UNIQUE,
    assessment_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    consensus_data JSON NOT NULL,
    disagreement_data JSON NOT NULL,
    outlier_data JSON NOT NULL,
    consensus_score DECIMAL(5, 2) NOT NULL DEFAULT 0,
    consensus_status ENUM('no_evidence', 'insufficient_evidence', 'consensus', 'partial_consensus', 'disagreement') NOT NULL DEFAULT 'no_evidence',
    analysis_method VARCHAR(40) NOT NULL DEFAULT 'adcs_rule_based_v1',
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_adcs_results_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    INDEX idx_adcs_results_status (consensus_status)
) ENGINE=InnoDB;