-- Phase 6 migration for an existing ServeIQ installation.
-- Run once after serveiq.sql. This migration does not modify existing request or ServiceDNA data.
CREATE TABLE IF NOT EXISTS matching_results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    match_score DECIMAL(5, 2) NOT NULL,
    score_breakdown JSON NOT NULL,
    match_reasons JSON NOT NULL,
    ranking_position SMALLINT UNSIGNED NOT NULL,
    matching_method VARCHAR(50) NOT NULL DEFAULT 'weighted_rule_based_v1',
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_matching_results_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_matching_results_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    CONSTRAINT uq_matching_results_version UNIQUE (request_id, provider_id, matching_method, version),
    INDEX idx_matching_results_request_rank (request_id, ranking_position),
    INDEX idx_matching_results_provider (provider_id)
) ENGINE=InnoDB;