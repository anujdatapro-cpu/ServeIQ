-- Optional geographic and response estimate data for marketplace discovery.
-- All new fields are nullable; profiles and requests without coordinates remain valid.
SET @schema_name = DATABASE();

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'provider_profiles' AND COLUMN_NAME = 'latitude') = 0,
    'ALTER TABLE provider_profiles ADD COLUMN latitude DECIMAL(10,7) NULL AFTER area', 'SELECT 1'
);
PREPARE serveiq_stmt FROM @ddl; EXECUTE serveiq_stmt; DEALLOCATE PREPARE serveiq_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'provider_profiles' AND COLUMN_NAME = 'longitude') = 0,
    'ALTER TABLE provider_profiles ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude', 'SELECT 1'
);
PREPARE serveiq_stmt FROM @ddl; EXECUTE serveiq_stmt; DEALLOCATE PREPARE serveiq_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'provider_profiles' AND COLUMN_NAME = 'location_source') = 0,
    'ALTER TABLE provider_profiles ADD COLUMN location_source ENUM(''provider_location'',''demo_estimate'') NULL AFTER longitude', 'SELECT 1'
);
PREPARE serveiq_stmt FROM @ddl; EXECUTE serveiq_stmt; DEALLOCATE PREPARE serveiq_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'provider_profiles' AND COLUMN_NAME = 'response_time_minutes') = 0,
    'ALTER TABLE provider_profiles ADD COLUMN response_time_minutes SMALLINT UNSIGNED NULL AFTER availability_status', 'SELECT 1'
);
PREPARE serveiq_stmt FROM @ddl; EXECUTE serveiq_stmt; DEALLOCATE PREPARE serveiq_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'provider_profiles' AND COLUMN_NAME = 'response_time_source') = 0,
    'ALTER TABLE provider_profiles ADD COLUMN response_time_source ENUM(''provider_estimate'',''demo_estimate'') NULL AFTER response_time_minutes', 'SELECT 1'
);
PREPARE serveiq_stmt FROM @ddl; EXECUTE serveiq_stmt; DEALLOCATE PREPARE serveiq_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'service_requests' AND COLUMN_NAME = 'latitude') = 0,
    'ALTER TABLE service_requests ADD COLUMN latitude DECIMAL(10,7) NULL AFTER area', 'SELECT 1'
);
PREPARE serveiq_stmt FROM @ddl; EXECUTE serveiq_stmt; DEALLOCATE PREPARE serveiq_stmt;

SET @ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @schema_name AND TABLE_NAME = 'service_requests' AND COLUMN_NAME = 'longitude') = 0,
    'ALTER TABLE service_requests ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude', 'SELECT 1'
);
PREPARE serveiq_stmt FROM @ddl; EXECUTE serveiq_stmt; DEALLOCATE PREPARE serveiq_stmt;
