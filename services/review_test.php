<?php
declare(strict_types=1);

/**
 * Phase 9: Automated Ratings, Reviews & Trust Tests
 *
 * Deterministic test suite verifying:
 * - Review eligibility across all booking lifecycle states
 * - Anti-abuse and duplicate submission prevention
 * - Rating boundaries (1–5 accepted, 0 & 6 blocked)
 * - Customer authorization and IDOR protection
 * - Provider average rating calculation and distribution
 * - Safe handling of zero-review providers
 */

require __DIR__ . '/../includes/review_helpers.php';

$allPassed = true;
$testIndex = 1;

function reportTest(string $description, bool $passed, string $extra = ''): void
{
    global $testIndex, $allPassed;
    if (!$passed) {
        $allPassed = false;
    }
    printf("TEST %02d %s: %s %s\n", $testIndex++, $passed ? 'PASS' : 'FAIL', $description, $extra !== '' ? "($extra)" : '');
}

echo "==================================================\n";
echo "SERVEIQ PHASE 9: AUTOMATED RATINGS & REVIEWS TESTS\n";
echo "==================================================\n\n";

// Set up SQLite in-memory database mimicking ServeIQ schema
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

    CREATE TABLE provider_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        business_name TEXT NOT NULL,
        phone TEXT NOT NULL,
        city TEXT NOT NULL,
        address TEXT NOT NULL,
        verification_status TEXT NOT NULL DEFAULT 'approved',
        availability_status TEXT NOT NULL DEFAULT 'available'
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider_id INTEGER NOT NULL,
        service_name TEXT NOT NULL,
        base_price REAL,
        is_active INTEGER NOT NULL DEFAULT 1
    );

    CREATE TABLE service_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        city TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'submitted'
    );

    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        customer_id INTEGER NOT NULL,
        provider_id INTEGER NOT NULL,
        service_id INTEGER NOT NULL,
        scheduled_date TEXT NOT NULL,
        scheduled_time TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        completed_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        booking_id INTEGER NOT NULL UNIQUE,
        customer_id INTEGER NOT NULL,
        provider_id INTEGER NOT NULL,
        rating INTEGER NOT NULL,
        review TEXT,
        status TEXT NOT NULL DEFAULT 'published',
        moderation_note TEXT,
        moderated_by INTEGER,
        moderated_at TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
");

// Seed fixture data
$pdo->exec("
    INSERT INTO users (id, name, email, role) VALUES 
        (1, 'Alice Customer', 'alice@test.com', 'customer'),
        (2, 'Bob Customer', 'bob@test.com', 'customer'),
        (3, 'Charlie Provider', 'charlie@test.com', 'provider'),
        (4, 'Diana Provider', 'diana@test.com', 'provider');

    INSERT INTO provider_profiles (id, user_id, business_name, phone, city, address) VALUES 
        (1, 3, 'Charlie Computer Care', '9876543210', 'Pune', '101 MG Road'),
        (2, 4, 'Diana Quick Fixes', '9876543211', 'Pune', '202 FC Road');

    INSERT INTO services (id, provider_id, service_name, base_price) VALUES 
        (1, 1, 'Laptop Diagnostic & Repair', 50.00),
        (2, 2, 'Mobile Screen Repair', 40.00);

    INSERT INTO service_requests (id, customer_id, title, description, city) VALUES 
        (1, 1, 'Laptop Overheating Issue', 'Fan is very loud and laptop shuts down', 'Pune'),
        (2, 1, 'Screen Repair Request', 'Cracked glass', 'Pune'),
        (3, 2, 'Bob Plumbing Request', 'Leaking tap', 'Pune');

    -- Booking fixtures in various states
    INSERT INTO bookings (id, request_id, customer_id, provider_id, service_id, scheduled_date, scheduled_time, status, completed_at) VALUES 
        (1, 1, 1, 1, 1, '2026-09-20', '10:00', 'completed', '2026-09-20 11:30:00'),
        (2, 1, 1, 1, 1, '2026-09-21', '10:00', 'pending', NULL),
        (3, 1, 1, 1, 1, '2026-09-22', '10:00', 'accepted', NULL),
        (4, 1, 1, 1, 1, '2026-09-23', '10:00', 'in_progress', NULL),
        (5, 1, 1, 1, 1, '2026-09-24', '10:00', 'cancelled', NULL),
        (6, 1, 1, 1, 1, '2026-09-25', '10:00', 'rejected', NULL),
        (7, 3, 2, 1, 1, '2026-09-20', '14:00', 'completed', '2026-09-20 15:30:00');
");

// ----------------------------------------------------
// TEST 1: Completed booking can be reviewed -> PASS
// ----------------------------------------------------
$eligibility1 = canCustomerReviewBooking($pdo, 1, 1);
reportTest('Completed booking can be reviewed', $eligibility1['eligible'] === true);

// ----------------------------------------------------
// TEST 2: Pending booking cannot be reviewed -> BLOCK
// ----------------------------------------------------
$eligibility2 = canCustomerReviewBooking($pdo, 2, 1);
reportTest('Pending booking cannot be reviewed', $eligibility2['eligible'] === false);

// ----------------------------------------------------
// TEST 3: Accepted booking cannot be reviewed -> BLOCK
// ----------------------------------------------------
$eligibility3 = canCustomerReviewBooking($pdo, 3, 1);
reportTest('Accepted booking cannot be reviewed', $eligibility3['eligible'] === false);

// ----------------------------------------------------
// TEST 4: In-progress booking cannot be reviewed -> BLOCK
// ----------------------------------------------------
$eligibility4 = canCustomerReviewBooking($pdo, 4, 1);
reportTest('In-progress booking cannot be reviewed', $eligibility4['eligible'] === false);

// ----------------------------------------------------
// TEST 5: Cancelled booking cannot be reviewed -> BLOCK
// ----------------------------------------------------
$eligibility5 = canCustomerReviewBooking($pdo, 5, 1);
reportTest('Cancelled booking cannot be reviewed', $eligibility5['eligible'] === false);

// ----------------------------------------------------
// TEST 6: Rejected booking cannot be reviewed -> BLOCK
// ----------------------------------------------------
$eligibility6 = canCustomerReviewBooking($pdo, 6, 1);
reportTest('Rejected booking cannot be reviewed', $eligibility6['eligible'] === false);

// ----------------------------------------------------
// TEST 7: Customer cannot review another customer's booking -> BLOCK
// ----------------------------------------------------
// Alice (id: 1) attempts to review Bob's completed booking (id: 7)
$eligibility7 = canCustomerReviewBooking($pdo, 7, 1);
reportTest('Customer cannot review another customer booking (IDOR protection)', $eligibility7['eligible'] === false);

// ----------------------------------------------------
// TEST 8: Create review & Duplicate review blocked -> BLOCK
// ----------------------------------------------------
$reviewId = createBookingReview($pdo, 1, 1, 5, 'Exceptional cooling repair, fast and professional!');
reportTest('Review successfully created for completed booking', $reviewId > 0);

// Attempt duplicate review for same booking 1
$duplicateBlocked = false;
try {
    createBookingReview($pdo, 1, 1, 4, 'Second review attempt');
} catch (RuntimeException $e) {
    $duplicateBlocked = true;
}
reportTest('Duplicate review for same booking blocked', $duplicateBlocked);

// ----------------------------------------------------
// TEST 9: Rating 1–5 accepted -> PASS
// ----------------------------------------------------
// Bob reviews his booking (id: 7) with rating 4
$bobReviewId = createBookingReview($pdo, 7, 2, 4, 'Good service on time.');
reportTest('Rating 1-5 accepted (rating 4 submitted)', $bobReviewId > 0);

// ----------------------------------------------------
// TEST 10: Rating 0 blocked -> PASS
// ----------------------------------------------------
$rating0Blocked = false;
try {
    createBookingReview($pdo, 1, 1, 0, 'Invalid rating');
} catch (InvalidArgumentException $e) {
    $rating0Blocked = true;
}
reportTest('Rating 0 blocked by validator', $rating0Blocked);

// ----------------------------------------------------
// TEST 11: Rating 6 blocked -> PASS
// ----------------------------------------------------
$rating6Blocked = false;
try {
    createBookingReview($pdo, 1, 1, 6, 'Invalid rating');
} catch (InvalidArgumentException $e) {
    $rating6Blocked = true;
}
reportTest('Rating 6 blocked by validator', $rating6Blocked);

// ----------------------------------------------------
// TEST 12: Missing/invalid booking blocked -> PASS
// ----------------------------------------------------
$missingBookingBlocked = false;
$eligibilityMissing = canCustomerReviewBooking($pdo, 99999, 1);
if (!$eligibilityMissing['eligible']) {
    $missingBookingBlocked = true;
}
reportTest('Missing/invalid booking ID blocked', $missingBookingBlocked);

// ----------------------------------------------------
// TEST 13: Provider average rating calculated correctly -> PASS
// ----------------------------------------------------
// Provider 1 received 2 reviews: rating 5 and rating 4 -> Expected average = 4.5
$reputation1 = getProviderReputation($pdo, 1);
$avgCorrect = ($reputation1['average_rating'] === 4.5) && ($reputation1['review_count'] === 2);
reportTest(
    'Provider average rating calculated correctly (4.5 from 5 and 4)', 
    $avgCorrect,
    "Calculated: {$reputation1['average_rating']}, Count: {$reputation1['review_count']}"
);

// ----------------------------------------------------
// TEST 14: Zero-review provider handled safely -> PASS
// ----------------------------------------------------
// Provider 2 has 0 reviews
$reputation2 = getProviderReputation($pdo, 2);
$zeroHandled = ($reputation2['average_rating'] === 0.0) && ($reputation2['review_count'] === 0) && ($reputation2['completed_services'] === 0);
reportTest('Zero-review provider handled safely without errors', $zeroHandled);

echo "\n==================================================\n";
if ($allPassed) {
    echo "PHASE 9 REVIEW TEST SUITE: ALL TESTS PASSED\n";
    echo "==================================================\n";
    exit(0);
} else {
    echo "PHASE 9 REVIEW TEST SUITE: SOME TESTS FAILED\n";
    echo "==================================================\n";
    exit(1);
}
