<?php
declare(strict_types=1);

/**
 * Phase 8: Automated Booking & Service Workflow Tests
 *
 * Runs deterministic tests covering:
 * - State machine valid and blocked transitions
 * - Role-based transition authorization
 * - Date/time schedule validation
 * - In-memory SQLite lifecycle simulation (creation, duplicate check, IDOR protection, accept, reject, start, complete, cancel)
 */

require __DIR__ . '/../includes/booking_helpers.php';

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
echo "SERVEIQ PHASE 8: AUTOMATED BOOKING TESTS\n";
echo "==================================================\n\n";

// ----------------------------------------------------
// 1. STATE MACHINE TRANSITIONS: VALID PATHS
// ----------------------------------------------------
reportTest('Valid transition: pending -> accepted', canTransitionBookingStatus('pending', 'accepted') === true);
reportTest('Valid transition: pending -> rejected', canTransitionBookingStatus('pending', 'rejected') === true);
reportTest('Valid transition: pending -> cancelled', canTransitionBookingStatus('pending', 'cancelled') === true);
reportTest('Valid transition: accepted -> in_progress', canTransitionBookingStatus('accepted', 'in_progress') === true);
reportTest('Valid transition: accepted -> cancelled', canTransitionBookingStatus('accepted', 'cancelled') === true);
reportTest('Valid transition: in_progress -> completed', canTransitionBookingStatus('in_progress', 'completed') === true);

// ----------------------------------------------------
// 2. STATE MACHINE TRANSITIONS: BLOCKED INVALID PATHS
// ----------------------------------------------------
reportTest('Block transition: completed -> accepted', canTransitionBookingStatus('completed', 'accepted') === false);
reportTest('Block transition: completed -> in_progress', canTransitionBookingStatus('completed', 'in_progress') === false);
reportTest('Block transition: rejected -> accepted', canTransitionBookingStatus('rejected', 'accepted') === false);
reportTest('Block transition: cancelled -> in_progress', canTransitionBookingStatus('cancelled', 'in_progress') === false);
reportTest('Block transition: in_progress -> accepted', canTransitionBookingStatus('in_progress', 'accepted') === false);
reportTest('Block transition: accepted -> pending', canTransitionBookingStatus('accepted', 'pending') === false);

// ----------------------------------------------------
// 3. ROLE-BASED TRANSITION AUTHORIZATION
// ----------------------------------------------------
reportTest('Role check: Customer can cancel pending', canUserTransitionBooking('customer', 'pending', 'cancelled') === true);
reportTest('Role check: Customer can cancel accepted', canUserTransitionBooking('customer', 'accepted', 'cancelled') === true);
reportTest('Role check: Customer CANNOT accept booking', canUserTransitionBooking('customer', 'pending', 'accepted') === false);
reportTest('Role check: Customer CANNOT complete service', canUserTransitionBooking('customer', 'in_progress', 'completed') === false);

reportTest('Role check: Provider can accept pending', canUserTransitionBooking('provider', 'pending', 'accepted') === true);
reportTest('Role check: Provider can reject pending', canUserTransitionBooking('provider', 'pending', 'rejected') === true);
reportTest('Role check: Provider can start accepted', canUserTransitionBooking('provider', 'accepted', 'in_progress') === true);
reportTest('Role check: Provider can complete in_progress', canUserTransitionBooking('provider', 'in_progress', 'completed') === true);
reportTest('Role check: Provider CANNOT cancel booking', canUserTransitionBooking('provider', 'pending', 'cancelled') === false);

// ----------------------------------------------------
// 4. DATE AND TIME VALIDATION
// ----------------------------------------------------
$tomorrow = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
$in30Days = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
$in100Days = (new DateTimeImmutable('+100 days'))->format('Y-m-d');

reportTest('Schedule validation: Tomorrow 10:00 is valid', bookingDateTimeIsValid($tomorrow, '10:00') === true);
reportTest('Schedule validation: 30 days ahead 14:30 is valid', bookingDateTimeIsValid($in30Days, '14:30') === true);
reportTest('Schedule validation: Past date is invalid', bookingDateTimeIsValid($yesterday, '10:00') === false);
reportTest('Schedule validation: Distant date (>90 days) is invalid', bookingDateTimeIsValid($in100Days, '10:00') === false);
reportTest('Schedule validation: Malformed date string is invalid', bookingDateTimeIsValid('not-a-date', '10:00') === false);
reportTest('Schedule validation: Malformed time string is invalid', bookingDateTimeIsValid($tomorrow, '25:99') === false);

// ----------------------------------------------------
// 5. DATABASE LIFECYCLE & INTEGRATION (IN-MEMORY SQLITE)
// ----------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Create SQLite schema mimicking ServeIQ MySQL tables
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
        user_id INTEGER NOT NULL,
        business_name TEXT NOT NULL,
        phone TEXT NOT NULL,
        city TEXT NOT NULL,
        address TEXT NOT NULL,
        area TEXT,
        verification_status TEXT NOT NULL DEFAULT 'pending',
        availability_status TEXT NOT NULL DEFAULT 'available'
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        provider_id INTEGER NOT NULL,
        category_id INTEGER NOT NULL,
        service_name TEXT NOT NULL,
        base_price REAL,
        description TEXT,
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

    CREATE TABLE matching_results (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL,
        provider_id INTEGER NOT NULL,
        match_score REAL NOT NULL,
        ranking_position INTEGER NOT NULL
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

// Insert seed data
$pdo->exec("
    INSERT INTO users (id, name, email, role) VALUES 
        (1, 'Alice Customer', 'alice@test.com', 'customer'),
        (2, 'Bob Customer', 'bob@test.com', 'customer'),
        (3, 'Charlie Provider', 'charlie@test.com', 'provider'),
        (4, 'Diana Provider', 'diana@test.com', 'provider');

    INSERT INTO service_categories (id, category_name) VALUES 
        (1, 'Laptop & Computer Repair');

    INSERT INTO provider_profiles (id, user_id, business_name, phone, city, address, area, verification_status, availability_status) VALUES 
        (1, 3, 'Charlie Tech Services', '9876543210', 'Pune', '123 Main St', 'Kothrud', 'approved', 'available'),
        (2, 4, 'Diana Fixes', '9876543211', 'Pune', '456 Side St', 'Baner', 'approved', 'available');

    INSERT INTO services (id, provider_id, category_id, service_name, base_price, is_active) VALUES 
        (1, 1, 1, 'Laptop Thermal & Fan Service', 49.99, 1),
        (2, 2, 1, 'Motherboard Diagnostics', 79.99, 1);

    INSERT INTO service_requests (id, customer_id, category_id, title, description, city, urgency, status) VALUES 
        (1, 1, 1, 'Laptop overheating', 'Fan is very loud and laptop shuts down', 'Pune', 'high', 'matched'),
        (2, 1, 1, 'Secondary laptop issue', 'Screen flickers when moved', 'Pune', 'medium', 'matched'),
        (3, 1, 1, 'Tertiary laptop issue', 'Battery draining in 10 mins', 'Pune', 'medium', 'matched');

    INSERT INTO matching_results (request_id, provider_id, match_score, ranking_position) VALUES 
        (1, 1, 92.5, 1),
        (2, 1, 88.0, 1),
        (3, 1, 85.0, 1);
");

// Test 5.1: Valid Booking Creation
$pdo->beginTransaction();
$ins = $pdo->prepare("
    INSERT INTO bookings (request_id, customer_id, provider_id, service_id, scheduled_date, scheduled_time, notes, status)
    VALUES (1, 1, 1, 1, '2026-10-01', '10:00', 'Please call before arrival', 'pending')
");
$ins->execute();
$booking1Id = (int)$pdo->lastInsertId();
recordBookingStatus($pdo, $booking1Id, null, 'pending', 1, 'Booking submitted by customer');
$pdo->commit();

reportTest('Database: Valid booking created in pending state', $booking1Id > 0);

// Test 5.2: Duplicate Booking Prevention on Same Request
$duplicatePrevented = false;
try {
    $insDup = $pdo->prepare("
        INSERT INTO bookings (request_id, customer_id, provider_id, service_id, scheduled_date, scheduled_time, notes, status)
        VALUES (1, 1, 1, 1, '2026-10-02', '11:00', 'Duplicate attempt', 'pending')
    ");
    $insDup->execute();
} catch (PDOException $e) {
    $duplicatePrevented = true;
}
reportTest('Database: Duplicate booking on same request blocked by UNIQUE constraint', $duplicatePrevented);

// Test 5.3: Invalid Service / Provider Mismatch Validation
$serviceCheck = $pdo->prepare("SELECT id FROM services WHERE id = :service_id AND provider_id = :provider_id AND is_active = 1");
$serviceCheck->execute(['service_id' => 2, 'provider_id' => 1]); // Service 2 belongs to Provider 2, not Provider 1
reportTest('Validation: Service belonging to different provider rejected', $serviceCheck->fetch() === false);

// Test 5.4: Customer IDOR Protection
$aliceBooking = findCustomerBooking($pdo, $booking1Id, 1); // Alice owns it
$bobBooking = findCustomerBooking($pdo, $booking1Id, 2);   // Bob does NOT own it
reportTest('Security IDOR: Customer can view their own booking', $aliceBooking !== null && (int)$aliceBooking['id'] === $booking1Id);
reportTest('Security IDOR: Customer B cannot view Customer A booking', $bobBooking === null);

// Test 5.5: Provider IDOR Protection
$charlieBooking = findProviderBooking($pdo, $booking1Id, 1); // Charlie assigned
$dianaBooking = findProviderBooking($pdo, $booking1Id, 2);   // Diana NOT assigned
reportTest('Security IDOR: Assigned provider can view booking', $charlieBooking !== null && (int)$charlieBooking['id'] === $booking1Id);
reportTest('Security IDOR: Non-assigned provider cannot view booking', $dianaBooking === null);

// Test 5.6: Provider Accepts Booking (pending -> accepted)
$pdo->beginTransaction();
$updAccept = $pdo->prepare("
    UPDATE bookings 
    SET status = 'accepted', accepted_at = CURRENT_TIMESTAMP 
    WHERE id = :id AND provider_id = 1 AND status = 'pending'
");
$updAccept->execute(['id' => $booking1Id]);
recordBookingStatus($pdo, $booking1Id, 'pending', 'accepted', 3, 'Booking accepted by provider');
$pdo->commit();

$checkAccept = findProviderBooking($pdo, $booking1Id, 1);
reportTest('Workflow: Provider accepts booking (pending -> accepted)', ($checkAccept['status'] ?? '') === 'accepted' && !empty($checkAccept['accepted_at']));

// Test 5.7: Provider Starts Service (accepted -> in_progress)
$pdo->beginTransaction();
$updStart = $pdo->prepare("
    UPDATE bookings 
    SET status = 'in_progress', started_at = CURRENT_TIMESTAMP 
    WHERE id = :id AND provider_id = 1 AND status = 'accepted'
");
$updStart->execute(['id' => $booking1Id]);
recordBookingStatus($pdo, $booking1Id, 'accepted', 'in_progress', 3, 'Service started');
$pdo->commit();

$checkStart = findProviderBooking($pdo, $booking1Id, 1);
reportTest('Workflow: Provider starts service (accepted -> in_progress)', ($checkStart['status'] ?? '') === 'in_progress' && !empty($checkStart['started_at']));

// Test 5.8: Provider Completes Service (in_progress -> completed)
$pdo->beginTransaction();
$updComplete = $pdo->prepare("
    UPDATE bookings 
    SET status = 'completed', completed_at = CURRENT_TIMESTAMP 
    WHERE id = :id AND provider_id = 1 AND status = 'in_progress'
");
$updComplete->execute(['id' => $booking1Id]);
recordBookingStatus($pdo, $booking1Id, 'in_progress', 'completed', 3, 'Service completed');
$pdo->commit();

$checkComplete = findProviderBooking($pdo, $booking1Id, 1);
reportTest('Workflow: Provider completes service (in_progress -> completed)', ($checkComplete['status'] ?? '') === 'completed' && !empty($checkComplete['completed_at']));

// Test 5.9: Status History Timeline Auditing
$history = getBookingStatusHistory($pdo, $booking1Id);
$historyStatuses = array_column($history, 'new_status');
reportTest(
    'Audit Trail: Booking status history logged sequentially', 
    count($history) === 4 && $historyStatuses === ['pending', 'accepted', 'in_progress', 'completed'],
    'Logged: ' . implode(' -> ', $historyStatuses)
);

// Test 5.10: Provider Rejection Flow (pending -> rejected with reason)
$pdo->beginTransaction();
$pdo->exec("
    INSERT INTO bookings (id, request_id, customer_id, provider_id, service_id, scheduled_date, scheduled_time, status)
    VALUES (2, 2, 1, 1, 1, '2026-10-05', '14:00', 'pending')
");
$updReject = $pdo->prepare("
    UPDATE bookings 
    SET status = 'rejected', rejection_reason = :reason 
    WHERE id = 2 AND provider_id = 1 AND status = 'pending'
");
$updReject->execute(['reason' => 'Technician unavailable on requested date']);
recordBookingStatus($pdo, 2, 'pending', 'rejected', 3, 'Rejected: Technician unavailable');
$pdo->commit();

$checkReject = findCustomerBooking($pdo, 2, 1);
reportTest('Workflow: Provider declines booking with rejection reason', ($checkReject['status'] ?? '') === 'rejected' && ($checkReject['rejection_reason'] ?? '') === 'Technician unavailable on requested date');

// Test 5.11: Customer Cancellation Flow (pending -> cancelled)
$pdo->beginTransaction();
$pdo->exec("
    INSERT INTO bookings (id, request_id, customer_id, provider_id, service_id, scheduled_date, scheduled_time, status)
    VALUES (3, 3, 1, 1, 1, '2026-10-06', '16:00', 'pending')
");
$updCancel = $pdo->prepare("
    UPDATE bookings 
    SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP 
    WHERE id = 3 AND customer_id = 1 AND status IN ('pending', 'accepted')
");
$updCancel->execute();
recordBookingStatus($pdo, 3, 'pending', 'cancelled', 1, 'Cancelled by customer');
$pdo->commit();

$checkCancel = findCustomerBooking($pdo, 3, 1);
reportTest('Workflow: Customer cancels pending booking', ($checkCancel['status'] ?? '') === 'cancelled' && !empty($checkCancel['cancelled_at']));

echo "\n==================================================\n";
if ($allPassed) {
    echo "PHASE 8 BOOKING TEST SUITE: ALL TESTS PASSED\n";
    echo "==================================================\n";
    exit(0);
} else {
    echo "PHASE 8 BOOKING TEST SUITE: SOME TESTS FAILED\n";
    echo "==================================================\n";
    exit(1);
}
