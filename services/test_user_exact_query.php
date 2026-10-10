<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/encryption.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/marketplace_filters.php';

$_GET = [
    'id' => '814',
    'q' => 'ServeIQ Demo Home Cleaning',
    'sort' => 'best_match',
    'min_price' => '',
    'max_price' => '',
    'rating' => '',
    'category' => '',
];

echo "Simulating matches.php GET parameters...\n";

// In-memory PDO SQLite database for testing exact execution
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        role TEXT NOT NULL
    );

    CREATE TABLE service_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_name TEXT NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE provider_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL UNIQUE,
        business_name TEXT NOT NULL,
        phone TEXT NOT NULL,
        address TEXT NOT NULL,
        city TEXT NOT NULL,
        area TEXT,
        profile_image TEXT,
        description TEXT,
        experience_years INTEGER NOT NULL DEFAULT 1,
        availability_status TEXT NOT NULL DEFAULT 'available',
        verification_status TEXT NOT NULL DEFAULT 'approved',
        marketplace_active INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        service_name TEXT NOT NULL,
        description TEXT,
        base_price REAL,
        is_active INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE service_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        category_id INTEGER,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        city TEXT NOT NULL,
        area TEXT,
        address TEXT,
        urgency TEXT NOT NULL DEFAULT 'medium',
        status TEXT NOT NULL DEFAULT 'submitted'
    );

    CREATE TABLE problem_fingerprints (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL UNIQUE,
        detected_category_id INTEGER,
        problem_type TEXT,
        affected_entity TEXT,
        symptoms TEXT,
        context TEXT,
        keywords TEXT,
        possible_service_types TEXT,
        location_context TEXT,
        confidence_score INTEGER NOT NULL DEFAULT 0,
        user_urgency TEXT NOT NULL DEFAULT 'medium',
        detected_urgency TEXT,
        evidence TEXT,
        urgency_score INTEGER NOT NULL DEFAULT 0,
        possible_causes TEXT,
        fingerprint_data TEXT NOT NULL DEFAULT '{}',
        engine_version TEXT NOT NULL DEFAULT 'rule-based-1.0',
        analysis_method TEXT NOT NULL DEFAULT 'rule_based_v1',
        version INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER NOT NULL,
        customer_id INTEGER NOT NULL,
        provider_id INTEGER NOT NULL,
        rating INTEGER NOT NULL,
        review TEXT,
        status TEXT NOT NULL DEFAULT 'published'
    );

    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL UNIQUE,
        customer_id INTEGER NOT NULL,
        provider_id INTEGER NOT NULL,
        service_id INTEGER NOT NULL,
        scheduled_date TEXT NOT NULL,
        scheduled_time TEXT NOT NULL,
        notes TEXT,
        rejection_reason TEXT,
        accepted_at TEXT,
        started_at TEXT,
        completed_at TEXT,
        cancelled_at TEXT,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
");

// Populate seed data matching Request 814 & Provider 1615
$pdo->exec("
    INSERT INTO users (id, name, email, role) VALUES
        (10, 'Anuj K', 'anuj@test.com', 'customer'),
        (280, 'Demo Home Cleaner', 'serveiq.cleaner.demo@gmail.com', 'provider');

    INSERT INTO service_categories (id, category_name) VALUES
        (8, 'Home Cleaning');

    INSERT INTO provider_profiles (id, user_id, business_name, phone, address, city, area, experience_years, availability_status, verification_status, marketplace_active) VALUES
        (1615, 280, 'ServeIQ Demo Home Cleaning', '9876543210', '12 Clean St', 'Pune', 'Kothrud', 5, 'available', 'approved', 1);

    INSERT INTO services (id, provider_id, category_id, service_name, description, base_price, is_active) VALUES
        (2001, 1615, 8, 'Full Home Cleaning for 2 BHK Flat', 'Complete deep cleaning service', 999.00, 1);

    INSERT INTO service_requests (id, customer_id, category_id, title, description, city, area, status) VALUES
        (814, 10, 8, 'Home Cleaning for 2 BHK Flat', 'Deep cleaning needed for 2 BHK flat', 'Pune', 'Kothrud', 'submitted');
");

$requestId = 814;
$customerId = 10;

$request = getMatchingRequest($pdo, $requestId, $customerId);
$categories = $pdo->query('SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY category_name')->fetchAll();
$matchingRequest = getMatchingRequest($pdo, $requestId, $customerId) ?? [];
$filters = marketplaceParseFilters($_GET, $categories, $matchingRequest);

echo "Parsed filters:\n";
print_r($filters);

$allProviders = getRankedProvidersForRequest($pdo, $requestId, $customerId);
echo "Initial ranked providers count: " . count($allProviders) . "\n";

if ($filters['q'] !== '' && $requestId && $request) {
    $existingProviderIds = array_column($allProviders, 'provider_id');
    $allCandidates = getProviderCandidates($pdo, $requestId, $customerId);
    foreach ($allCandidates as $candidate) {
        $cId = (int)$candidate['provider_id'];
        if (!in_array($cId, $existingProviderIds, true)) {
            $evaluated = array_merge($candidate, calculateProviderMatchScore($request, $candidate));
            $allProviders[] = $evaluated;
        }
    }
}

echo "Total providers before filtering: " . count($allProviders) . "\n";

$filteredProviders = marketplaceFilterAndSortProviders($allProviders, $filters, $matchingRequest);
echo "Filtered providers count: " . count($filteredProviders) . "\n";

if (!empty($filteredProviders)) {
    echo "Found Provider: " . $filteredProviders[0]['business_name'] . " (ID: " . $filteredProviders[0]['provider_id'] . ")\n";
} else {
    echo "NO PROVIDERS FOUND!\n";
}
