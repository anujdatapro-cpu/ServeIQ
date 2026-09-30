CREATE DATABASE IF NOT EXISTS serveiq_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE serveiq_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS adcs_results;
DROP TABLE IF EXISTS provider_assessments;
DROP TABLE IF EXISTS provider_responses;
DROP TABLE IF EXISTS matching_results;
DROP TABLE IF EXISTS problem_fingerprints;
DROP TABLE IF EXISTS request_images;
DROP TABLE IF EXISTS service_requests;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS provider_profiles;
DROP TABLE IF EXISTS service_categories;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer', 'provider', 'admin') NOT NULL DEFAULT 'customer',
    profile_image VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE service_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(120) NOT NULL UNIQUE,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE provider_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    business_name VARCHAR(160) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    area VARCHAR(100) NULL,
    experience_years SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT NULL,
    profile_image VARCHAR(255) NULL,
    availability_status ENUM('available', 'busy', 'offline') NOT NULL DEFAULT 'available',
    verification_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_provider_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_provider_city (city),
    INDEX idx_provider_status (verification_status, availability_status)
) ENGINE=InnoDB;

CREATE TABLE services (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    service_name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    base_price DECIMAL(10, 2) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_services_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_services_category FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE RESTRICT,
    INDEX idx_services_category (category_id),
    INDEX idx_services_provider (provider_id)
) ENGINE=InnoDB;

CREATE TABLE service_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    city VARCHAR(100) NOT NULL,
    area VARCHAR(100) NULL,
    address VARCHAR(255) NULL,
    urgency ENUM('low', 'medium', 'high', 'emergency') NOT NULL DEFAULT 'medium',
    contact_preference ENUM('phone', 'email', 'messaging') NOT NULL DEFAULT 'email',
    status ENUM('draft', 'submitted', 'analyzing', 'matched', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'submitted',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_requests_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_requests_category FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE SET NULL,
    INDEX idx_requests_customer (customer_id),
    INDEX idx_requests_category_city (category_id, city),
    INDEX idx_requests_status (status)
) ENGINE=InnoDB;

CREATE TABLE request_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL UNIQUE,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_request_images_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    INDEX idx_request_images_request (request_id)
) ENGINE=InnoDB;

CREATE TABLE problem_fingerprints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL UNIQUE,
    detected_category_id INT UNSIGNED NULL,
    problem_type VARCHAR(120) NULL,
    affected_entity VARCHAR(120) NULL,
    symptoms JSON NOT NULL,
    context JSON NOT NULL,
    keywords JSON NOT NULL,
    possible_service_types JSON NOT NULL,
    location_context JSON NOT NULL,
    confidence_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    user_urgency ENUM('low', 'medium', 'high', 'emergency') NOT NULL DEFAULT 'medium',
    detected_urgency ENUM('low', 'medium', 'high', 'emergency') NULL,
    evidence JSON NOT NULL,
    urgency_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
    possible_causes JSON NULL,
    fingerprint_data JSON NOT NULL,
    engine_version VARCHAR(30) NOT NULL DEFAULT 'rule-based-1.0',
    analysis_method VARCHAR(40) NOT NULL DEFAULT 'rule_based_v1',
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_fingerprints_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_fingerprints_category FOREIGN KEY (detected_category_id) REFERENCES service_categories(id) ON DELETE SET NULL,
    INDEX idx_fingerprints_category (detected_category_id)
) ENGINE=InnoDB;

CREATE TABLE provider_responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    diagnosis VARCHAR(255) NOT NULL,
    confidence TINYINT UNSIGNED NOT NULL,
    recommended_service VARCHAR(180) NOT NULL,
    estimated_price DECIMAL(10, 2) NOT NULL,
    estimated_time VARCHAR(100) NOT NULL,
    notes TEXT NULL,
    status ENUM('submitted', 'accepted', 'declined') NOT NULL DEFAULT 'submitted',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_responses_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_responses_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    CONSTRAINT uq_response_provider_request UNIQUE (request_id, provider_id),
    INDEX idx_responses_request (request_id),
    INDEX idx_responses_provider (provider_id)
) ENGINE=InnoDB;

CREATE TABLE matching_results (
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

CREATE TABLE provider_assessments (
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

CREATE TABLE adcs_results (
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

CREATE TABLE bookings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    service_id INT UNSIGNED NOT NULL,
    response_id INT UNSIGNED NULL UNIQUE,
    scheduled_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    notes TEXT NULL,
    rejection_reason VARCHAR(500) NULL,
    accepted_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    cancelled_at TIMESTAMP NULL,
    status ENUM('pending', 'accepted', 'in_progress', 'completed', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_request FOREIGN KEY (request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_bookings_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_response FOREIGN KEY (response_id) REFERENCES provider_responses(id) ON DELETE RESTRICT,
    INDEX idx_bookings_customer (customer_id),
    INDEX idx_bookings_provider (provider_id)
) ENGINE=InnoDB;

CREATE TABLE booking_status_history (
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

CREATE TABLE reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL UNIQUE,
    customer_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_reviews_provider FOREIGN KEY (provider_id) REFERENCES provider_profiles(id) ON DELETE CASCADE,
    INDEX idx_reviews_provider (provider_id)
) ENGINE=InnoDB;

INSERT INTO service_categories (category_name, description) VALUES
    ('Laptop & Computer Repair', 'Hardware, software, performance, and cooling issues.'),
    ('Mobile Repair', 'Screen, battery, charging, and device troubleshooting.'),
    ('AC Repair', 'Air conditioning installation, repair, and maintenance.'),
    ('Plumbing', 'Leaks, fittings, drainage, and household plumbing.'),
    ('Electrical Repair', 'Wiring, switches, appliances, and electrical safety.'),
    ('Vehicle Repair', 'Two-wheeler and car servicing and repair.'),
    ('Appliance Repair', 'Repair and maintenance for household appliances.'),
    ('Home Cleaning', 'Home, office, deep cleaning, and maintenance services.'),
    ('Internet & WiFi Services', 'Router, connectivity, and network troubleshooting.')
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    is_active = 1;
