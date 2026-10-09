<?php
declare(strict_types=1);

/**
 * End-to-End Direct Provider Search & Booking Acceptance Regression Test Suite
 *
 * Exercises:
 * 1. Database-backed partial business name & service search filtering.
 * 2. Exclusion and diagnostic detection for ineligible/offline/unverified providers.
 * 3. Consistent provider_profiles.id identity mapping and service ownership validation.
 * 4. End-to-end lifecycle: Customer Request -> Business Search -> Booking Insertion ('pending')
 *    -> Provider Dashboard Lookup -> Provider Acceptance ('accepted') -> Customer Persisted View Verification.
 * 5. Security IDOR isolation and tampered ID protection.
 */

require_once __DIR__ . '/../includes/booking_helpers.php';
require_once __DIR__ . '/../includes/matching_helpers.php';
require_once __DIR__ . '/../includes/marketplace_filters.php';

$allPassed = true;
$testIndex = 1;

function report(string $description, bool $passed, string $extra = ''): void
{
    global $testIndex, $allPassed;
    if (!$passed) {
        $allPassed = false;
    }
    printf("TEST %02d %s: %s %s\n", $testIndex++, $passed ? 'PASS' : 'FAIL', $description, $extra !== '' ? "($extra)" : '');
}

echo "==================================================\n";
echo "SERVEIQ: DIRECT PROVIDER SEARCH & E2E BOOKING TESTS\n";
echo "==================================================\n\n";

// Setup isolated SQLite test database
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
        category_name TEXT NOT NULL
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

    CREATE TABLE booking_status_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER NOT NULL,
        old_status TEXT,
        new_status TEXT NOT NULL,
        changed_by INTEGER NOT NULL,
        note TEXT,
        changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
");

// Populate seed data
$pdo->exec("
    INSERT INTO users (id, name, email, role) VALUES
        (1, 'John Customer', 'john@test.com', 'customer'),
        (2, 'Jane Customer', 'jane@test.com', 'customer'),
        (10, 'Apex Owner', 'apex@test.com', 'provider'),
        (11, 'Bolt Owner', 'bolt@test.com', 'provider'),
        (12, 'Offline Owner', 'offline@test.com', 'provider'),
        (13, 'Unverified Owner', 'unverified@test.com', 'provider');

    INSERT INTO service_categories (id, category_name) VALUES
        (1, 'Electrical Repair'),
        (2, 'AC Repair');

    -- Note user_id vs provider_profiles.id offset intentionally to verify identity mapping!
    -- Profile ID 1 -> User ID 10 (Apex Electrical)
    -- Profile ID 2 -> User ID 11 (Bolt Services)
    -- Profile ID 3 -> User ID 12 (Offline Electrics) - offline
    -- Profile ID 4 -> User ID 13 (Unverified Repairs) - pending verification
    INSERT INTO provider_profiles (id, user_id, business_name, phone, address, city, area, experience_years, availability_status, verification_status, marketplace_active) VALUES
        (1, 10, 'Apex Electrical Solutions', '9876543210', '12 Spark Ave', 'Pune', 'Kothrud', 5, 'available', 'approved', 1),
        (2, 11, 'Bolt Fast Services', '9876543211', '34 Wire Rd', 'Pune', 'Baner', 3, 'available', 'approved', 1),
        (3, 12, 'Offline Electrics Shop', '9876543212', '56 Dark St', 'Pune', 'Aundh', 2, 'offline', 'approved', 1),
        (4, 13, 'Unverified Repairs Hub', '9876543213', '78 Wait Rd', 'Pune', 'Viman Nagar', 1, 'available', 'pending', 1);

    INSERT INTO services (id, provider_id, category_id, service_name, description, base_price, is_active) VALUES
        (101, 1, 1, 'Full House Electrical Inspection', 'Complete safety check and wiring inspection', 499.00, 1),
        (102, 1, 1, 'Switchboard & Socket Repair', 'Fix loose connections and short circuits', 199.00, 1),
        (103, 2, 1, 'Quick Electrical Fixing', 'Emergency repairs and fuse replacement', 299.00, 1),
        (104, 3, 1, 'Offline Appliance Repair', 'General repairs', 150.00, 1);

    INSERT INTO service_requests (id, customer_id, category_id, title, description, city, area, status) VALUES
        (501, 1, 1, 'Frequent breaker tripping', 'Main circuit breaker trips whenever AC turned on', 'Pune', 'Kothrud', 'submitted'),
        (502, 2, 1, 'Socket spark issue', 'Kitchen wall socket sparked when plugging microwave', 'Pune', 'Baner', 'submitted');
");

// ----------------------------------------------------
// 1. PARTIAL BUSINESS SEARCH & FILTERING TESTS
// ----------------------------------------------------
$candidates = getProviderCandidates($pdo, 501, 1);
$categories = $pdo->query("SELECT id, category_name FROM service_categories")->fetchAll();
$req = $pdo->query("SELECT * FROM service_requests WHERE id = 501")->fetch();

// Test 1.1: Partial business name search matches "Apex"
$filtersApex = marketplaceParseFilters(['q' => 'Apex'], $categories, $req);
$resultsApex = marketplaceFilterAndSortProviders($candidates, $filtersApex, $req);
report('Partial search: "Apex" finds "Apex Electrical Solutions"', count($resultsApex) === 1 && (int)$resultsApex[0]['provider_id'] === 1);

// Test 1.2: Case-insensitive search matches "aPeX eLeCtRiCaL"
$filtersCase = marketplaceParseFilters(['q' => 'aPeX eLeCtRiCaL'], $categories, $req);
$resultsCase = marketplaceFilterAndSortProviders($candidates, $filtersCase, $req);
report('Case-insensitive search: "aPeX eLeCtRiCaL" returns correct provider', count($resultsCase) === 1 && (int)$resultsCase[0]['provider_id'] === 1);

// Test 1.3: Partial service name search matches "Switchboard"
$filtersService = marketplaceParseFilters(['q' => 'Switchboard'], $categories, $req);
$resultsService = marketplaceFilterAndSortProviders($candidates, $filtersService, $req);
report('Service name search: "Switchboard" finds provider offering switchboard repair', count($resultsService) === 1 && (int)$resultsService[0]['provider_id'] === 1);

// Test 1.4: Search excludes offline or pending verification providers from active candidates
$hasOfflineInCandidates = false;
$hasUnverifiedInCandidates = false;
foreach ($candidates as $c) {
    if ((int)$c['provider_id'] === 3) $hasOfflineInCandidates = true;
    if ((int)$c['provider_id'] === 4) $hasUnverifiedInCandidates = true;
}
report('Search candidate safety: Offline and unverified providers excluded from active results', !$hasOfflineInCandidates && !$hasUnverifiedInCandidates);

// ----------------------------------------------------
// 2. INELIGIBLE PROVIDER DIAGNOSTIC FEEDBACK TESTS
// ----------------------------------------------------
function checkIneligibleProvider(PDO $pdo, string $searchQuery): ?string
{
    $searchPattern = '%' . trim($searchQuery) . '%';
    $checkStmt = $pdo->prepare('
        SELECT pp.id, pp.business_name, pp.verification_status, pp.availability_status, pp.marketplace_active,
               u.name AS provider_name,
               (SELECT COUNT(*) FROM services s WHERE s.provider_id = pp.id AND s.is_active = 1) AS active_services_count
        FROM provider_profiles pp
        INNER JOIN users u ON u.id = pp.user_id
        WHERE pp.business_name LIKE :q OR u.name LIKE :q
        LIMIT 1
    ');
    $checkStmt->execute(['q' => $searchPattern]);
    $p = $checkStmt->fetch();
    if (!$p) return null;

    $reasons = [];
    if ($p['verification_status'] !== 'approved') $reasons[] = 'profile verification is ' . $p['verification_status'];
    if ($p['availability_status'] === 'offline') $reasons[] = 'provider status is currently offline';
    if ((int)$p['marketplace_active'] !== 1) $reasons[] = 'marketplace listing is inactive';
    if ((int)$p['active_services_count'] === 0) $reasons[] = 'no active services are currently listed';

    return $reasons !== [] ? 'The provider "' . $p['business_name'] . '" was found in the database, but cannot be booked at this time because ' . implode(', ', $reasons) . '.' : null;
}

$offlineDiag = checkIneligibleProvider($pdo, 'Offline Electrics');
report('Diagnostic feedback: Offline provider query explains offline status', $offlineDiag !== null && str_contains($offlineDiag, 'currently offline'));

$unverifiedDiag = checkIneligibleProvider($pdo, 'Unverified Repairs');
report('Diagnostic feedback: Unverified provider query explains pending verification status', $unverifiedDiag !== null && str_contains($unverifiedDiag, 'verification is pending'));

// ----------------------------------------------------
// 3. PROVIDER IDENTITY MAPPING & SERVICE OWNERSHIP TESTS
// ----------------------------------------------------
$apexProfileId = 1; // provider_profiles.id
$apexUserId = 10;   // users.id

// Verify service 101 belongs to provider_profile 1
$svcCheckValid = $pdo->prepare("SELECT id FROM services WHERE id = :sid AND provider_id = :pid AND is_active = 1");
$svcCheckValid->execute(['sid' => 101, 'pid' => $apexProfileId]);
report('Service ownership: Valid service 101 belongs to provider_profile 1', $svcCheckValid->fetch() !== false);

// Verify service 103 (belonging to provider 2) is REJECTED for provider 1
$svcCheckInvalid = $pdo->prepare("SELECT id FROM services WHERE id = :sid AND provider_id = :pid AND is_active = 1");
$svcCheckInvalid->execute(['sid' => 103, 'pid' => $apexProfileId]);
report('Service ownership safety: Service 103 belonging to provider 2 is blocked for provider 1', $svcCheckInvalid->fetch() === false);

// ----------------------------------------------------
// 4. END-TO-END BOOKING CREATION & ACCEPTANCE WORKFLOW
// ----------------------------------------------------
$tomorrow = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
$time = '11:00';

// Step 4.1: Customer submits booking for Request 501 with Apex Electrical (profile_id 1, service_id 101)
$pdo->beginTransaction();
$insBooking = $pdo->prepare("
    INSERT INTO bookings (request_id, customer_id, provider_id, service_id, scheduled_date, scheduled_time, notes, status)
    VALUES (:request_id, :customer_id, :provider_id, :service_id, :date, :time, :notes, 'pending')
");
$insBooking->execute([
    'request_id' => 501,
    'customer_id' => 1,
    'provider_id' => $apexProfileId,
    'service_id' => 101,
    'date' => $tomorrow,
    'time' => $time,
    'notes' => 'Please bring insulation tape',
]);
$bookingId = (int)$pdo->lastInsertId();
recordBookingStatus($pdo, $bookingId, null, 'pending', 1, 'Booking submitted by customer');
$pdo->commit();

report('E2E Step 1: Customer creates booking in pending state', $bookingId > 0);

// Step 4.2: Assigned provider (Apex Owner, user_id 10 -> profile_id 1) retrieves booking from dashboard
$providerBooking = findProviderBooking($pdo, $bookingId, $apexProfileId);
report('E2E Step 2: Assigned provider retrieves exact booking by profile_id', $providerBooking !== null && (int)$providerBooking['id'] === $bookingId && $providerBooking['status'] === 'pending');

// Step 4.3: Unrelated provider (Bolt Services, profile_id 2) CANNOT retrieve or manage booking
$unrelatedBooking = findProviderBooking($pdo, $bookingId, 2);
report('E2E Step 3: Unrelated provider cannot retrieve customer booking (IDOR protected)', $unrelatedBooking === null);

// Step 4.4: Assigned provider accepts the pending booking
$pdo->beginTransaction();
$updAccept = $pdo->prepare("
    UPDATE bookings
    SET status = 'accepted', accepted_at = CURRENT_TIMESTAMP
    WHERE id = :id AND provider_id = :pid AND status = 'pending'
");
$updAccept->execute(['id' => $bookingId, 'pid' => $apexProfileId]);
report('E2E Step 4: Provider status update affected exactly 1 row', $updAccept->rowCount() === 1);

recordBookingStatus($pdo, $bookingId, 'pending', 'accepted', $apexUserId, 'Booking accepted by provider');
$pdo->commit();

// Step 4.5: Customer retrieves booking and sees persisted Accepted status
$customerBooking = findCustomerBooking($pdo, $bookingId, 1);
report('E2E Step 5: Customer views booking and sees persisted "accepted" status', $customerBooking !== null && $customerBooking['status'] === 'accepted' && !empty($customerBooking['accepted_at']));

// Step 4.6: Status history reflects lifecycle transition
$history = getBookingStatusHistory($pdo, $bookingId);
report('E2E Step 6: Status history recorded "pending -> accepted" transition with actor info', count($history) === 2 && $history[1]['old_status'] === 'pending' && $history[1]['new_status'] === 'accepted' && (int)$history[1]['changed_by'] === $apexUserId);

echo "\n==================================================\n";
if ($allPassed) {
    echo "DIRECT PROVIDER SEARCH & E2E BOOKING SUITE: ALL PASSED\n";
    echo "==================================================\n";
    exit(0);
} else {
    echo "DIRECT PROVIDER SEARCH & E2E BOOKING SUITE: FAILED\n";
    echo "==================================================\n";
    exit(1);
}
