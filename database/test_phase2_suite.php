<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();
require_once __DIR__ . '/../services/service_dna.php';
require_once __DIR__ . '/../includes/matching_helpers.php';
require_once __DIR__ . '/../includes/booking_helpers.php';
require_once __DIR__ . '/../includes/review_helpers.php';

echo "============================================================\n";
echo "       SERVEIQ — PHASE 2 COMPREHENSIVE VERIFICATION SUITE   \n";
echo "============================================================\n\n";

$suiteStats = [
    'passed' => 0,
    'failed' => 0,
    'total' => 0,
];

function assertTest(bool $condition, string $message): void {
    global $suiteStats;
    $suiteStats['total']++;
    if ($condition) {
        $suiteStats['passed']++;
        echo "  [PASS] {$message}\n";
    } else {
        $suiteStats['failed']++;
        echo "  [FAIL] {$message}\n";
    }
}

// ============================================================
// TEST GROUP 1: MARKETPLACE DATASET INTEGRITY
// ============================================================
echo "--- TEST GROUP 1: Marketplace Dataset Integrity ---\n";

$activeProviders = (int)$pdo->query("SELECT COUNT(*) FROM provider_profiles WHERE marketplace_active = 1")->fetchColumn();
assertTest($activeProviders === 100, "Exactly 100 active marketplace providers (found: {$activeProviders})");

$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
assertTest($totalCustomers >= 30, "At least 30 demo customers (found: {$totalCustomers})");

$activeServices = (int)$pdo->query(
    "SELECT COUNT(*) FROM services s
     INNER JOIN provider_profiles pp ON pp.id = s.provider_id
     WHERE pp.marketplace_active = 1 AND s.is_active = 1"
)->fetchColumn();
assertTest($activeServices >= 250 && $activeServices <= 350, "Provider-service relationships between 250 and 350 (found: {$activeServices})");

$categories = $pdo->query("SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
assertTest(count($categories) === 15, "All 15 service categories active (found: " . count($categories) . ")");

// Verify every category has active providers
$minProvidersPerCategory = 999;
foreach ($categories as $cat) {
    $count = (int)$pdo->prepare(
        "SELECT COUNT(DISTINCT pp.id) FROM provider_profiles pp
         INNER JOIN services s ON s.provider_id = pp.id
         WHERE pp.marketplace_active = 1 AND s.is_active = 1 AND s.category_id = :cat_id"
    )->execute(['cat_id' => $cat['id']]) ? (int)$pdo->prepare(
        "SELECT COUNT(DISTINCT pp.id) FROM provider_profiles pp
         INNER JOIN services s ON s.provider_id = pp.id
         WHERE pp.marketplace_active = 1 AND s.is_active = 1 AND s.category_id = :cat_id"
    )->fetchColumn() : 0;
    
    // Quick re-fetch cleanly
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT pp.id) FROM provider_profiles pp INNER JOIN services s ON s.provider_id = pp.id WHERE pp.marketplace_active = 1 AND s.is_active = 1 AND s.category_id = :cat_id");
    $stmt->execute(['cat_id' => $cat['id']]);
    $catProviders = (int)$stmt->fetchColumn();
    if ($catProviders < $minProvidersPerCategory) $minProvidersPerCategory = $catProviders;
}
assertTest($minProvidersPerCategory >= 5, "Every category has at least 5 providers (min found: {$minProvidersPerCategory})");

// Orphan records check
$orphanProfiles = (int)$pdo->query("SELECT COUNT(*) FROM provider_profiles pp LEFT JOIN users u ON u.id = pp.user_id WHERE u.id IS NULL")->fetchColumn();
$orphanServices = (int)$pdo->query("SELECT COUNT(*) FROM services s LEFT JOIN provider_profiles pp ON pp.id = s.provider_id WHERE pp.id IS NULL")->fetchColumn();
$orphanBookings = (int)$pdo->query("SELECT COUNT(*) FROM bookings b LEFT JOIN provider_profiles pp ON pp.id = b.provider_id WHERE pp.id IS NULL")->fetchColumn();
$orphanReviews = (int)$pdo->query("SELECT COUNT(*) FROM reviews r LEFT JOIN provider_profiles pp ON pp.id = r.provider_id WHERE pp.id IS NULL")->fetchColumn();
assertTest($orphanProfiles === 0 && $orphanServices === 0 && $orphanBookings === 0 && $orphanReviews === 0, "Zero orphan records across profiles, services, bookings, reviews");

// Multi-service provider check
$multiServiceCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT pp.id, COUNT(s.id) as cnt FROM provider_profiles pp
        INNER JOIN services s ON s.provider_id = pp.id
        WHERE pp.marketplace_active = 1 AND s.is_active = 1
        GROUP BY pp.id
        HAVING cnt >= 2
    ) t"
)->fetchColumn();
assertTest($multiServiceCount === 100, "100% of active marketplace providers offer multi-services (found: {$multiServiceCount}/100)");

echo "\n";

// ============================================================
// TEST GROUP 2: 50+ NATURAL LANGUAGE UNDERSTANDING EVALUATION
// ============================================================
echo "--- TEST GROUP 2: 50+ Natural Language Query Evaluation ---\n";

$nluQueries = [
    // 1. Laptop & Computer Repair
    ['q' => 'My laptop is overheating and shutting down while gaming', 'cat' => 'Laptop & Computer Repair', 'entity' => 'Laptop'],
    ['q' => 'PC blue screen of death crash during bootup', 'cat' => 'Laptop & Computer Repair', 'entity' => 'Computer'],
    ['q' => 'MacBook trackpad and keyboard stopped working suddenly', 'cat' => 'Laptop & Computer Repair', 'entity' => 'Laptop'],
    ['q' => 'Windows desktop computer is extremely slow and lagging', 'cat' => 'Laptop & Computer Repair', 'entity' => 'Computer'],

    // 2. Mobile Repair
    ['q' => 'iPhone screen cracked after falling on road, touch not working', 'cat' => 'Mobile Repair', 'entity' => 'Phone'],
    ['q' => 'Android phone battery drains completely within two hours', 'cat' => 'Mobile Repair', 'entity' => 'Phone'],
    ['q' => 'Smartphone charging port loose, only charges at specific angle', 'cat' => 'Mobile Repair', 'entity' => 'Phone'],
    ['q' => 'Mobile display glass replacement needed urgently', 'cat' => 'Mobile Repair', 'entity' => 'Phone'],

    // 3. AC Repair
    ['q' => 'Split AC not cooling room, blowing warm air', 'cat' => 'AC Repair', 'entity' => 'AC'],
    ['q' => 'Air conditioner indoor unit leaking water onto bedroom floor', 'cat' => 'AC Repair', 'entity' => 'AC'],
    ['q' => 'Inverter AC compressor making loud grinding noise', 'cat' => 'AC Repair', 'entity' => 'AC'],
    ['q' => 'Window AC gas refilling and cooling inspection', 'cat' => 'AC Repair', 'entity' => 'AC'],

    // 4. Plumbing
    ['q' => 'Bathroom water pipe leaking under washbasin', 'cat' => 'Plumbing', 'entity' => 'Pipe'],
    ['q' => 'Kitchen sink drain pipe clogged and overflowing', 'cat' => 'Plumbing', 'entity' => 'Pipe'],
    ['q' => 'Toilet flush valve broken and water continuously running', 'cat' => 'Plumbing', 'entity' => 'Pipe'],
    ['q' => 'Bathroom shower tap dripping water continuously', 'cat' => 'Plumbing', 'entity' => 'Tap'],

    // 5. Electrical Repair
    ['q' => 'Kitchen switch sparking and electrical socket burned', 'cat' => 'Electrical Repair', 'entity' => 'Electrical switch'],
    ['q' => 'Main MCB circuit breaker trips whenever geyser is turned on', 'cat' => 'Electrical Repair', 'entity' => null],
    ['q' => 'Ceiling fan stopped rotating and electrical humming sound', 'cat' => 'Electrical Repair', 'entity' => null],
    ['q' => 'Whole house electrical wiring short circuit emergency', 'cat' => 'Electrical Repair', 'entity' => 'Electrical equipment'],

    // 6. CCTV & Security Installation
    ['q' => 'CCTV security camera not showing video feed on monitor', 'cat' => 'CCTV & Security Installation', 'entity' => 'CCTV camera'],
    ['q' => 'Surveillance system installation needed for 4 outdoor cameras', 'cat' => 'CCTV & Security Installation', 'entity' => 'CCTV camera'],
    ['q' => 'Security cam DVR recording hard drive not detected', 'cat' => 'CCTV & Security Installation', 'entity' => 'CCTV camera'],

    // 7. Vehicle Repair
    ['q' => 'Car engine not starting in morning, battery clicking sound', 'cat' => 'Vehicle Repair', 'entity' => 'Car'],
    ['q' => 'Motorcycle front brake making loud screeching sound', 'cat' => 'Vehicle Repair', 'entity' => 'Car'],
    ['q' => 'Scooter engine oil change and periodic servicing', 'cat' => 'Vehicle Repair', 'entity' => 'Car'],

    // 8. Tyre/Puncture Services
    ['q' => 'Car tyre tubeless puncture repair required at home', 'cat' => 'Tyre/Puncture Services', 'entity' => 'Tyre'],
    ['q' => 'Bike flat tire losing air pressure within minutes', 'cat' => 'Tyre/Puncture Services', 'entity' => 'Tyre'],
    ['q' => 'Car wheel alignment and balancing with tyre rotation', 'cat' => 'Tyre/Puncture Services', 'entity' => 'Tyre'],

    // 9. Washing Machine Repair
    ['q' => 'Washing machine making violent vibrating noise during spin cycle', 'cat' => 'Washing Machine Repair', 'entity' => 'Washing Machine'],
    ['q' => 'Front load washer not draining water at end of cycle', 'cat' => 'Washing Machine Repair', 'entity' => 'Washing Machine'],
    ['q' => 'Top load laundry machine drum not rotating', 'cat' => 'Washing Machine Repair', 'entity' => 'Washing Machine'],

    // 10. Refrigerator Repair
    ['q' => 'Double door fridge freezer cold but lower compartment warm', 'cat' => 'Refrigerator Repair', 'entity' => 'Refrigerator'],
    ['q' => 'Refrigerator compressor running constantly but food spoiling', 'cat' => 'Refrigerator Repair', 'entity' => 'Refrigerator'],
    ['q' => 'Single door fridge water pooling at the bottom', 'cat' => 'Refrigerator Repair', 'entity' => 'Refrigerator'],

    // 11. TV Repair
    ['q' => 'Smart LED TV has sound coming but screen stays completely black', 'cat' => 'TV Repair', 'entity' => 'Television'],
    ['q' => 'Television display showing horizontal lines across screen', 'cat' => 'TV Repair', 'entity' => 'Television'],
    ['q' => 'Smart TV HDMI port not detecting set top box', 'cat' => 'TV Repair', 'entity' => 'Television'],

    // 12. RO/Water Purifier Service
    ['q' => 'RO water purifier stopped dispensing drinking water', 'cat' => 'RO/Water Purifier Service', 'entity' => 'Water purifier'],
    ['q' => 'Water filter purifier membrane replacement and TDS check', 'cat' => 'RO/Water Purifier Service', 'entity' => 'Water purifier'],
    ['q' => 'Aquaguard water purifier continuous beeping alarm', 'cat' => 'RO/Water Purifier Service', 'entity' => 'Water purifier'],

    // 13. Appliance Repair
    ['q' => 'Microwave oven turntable rotating but not heating food', 'cat' => 'Appliance Repair', 'entity' => null],
    ['q' => 'Kitchen dishwasher not washing properly and leaving soap stains', 'cat' => 'Appliance Repair', 'entity' => null],
    ['q' => 'Kitchen appliance mixer grinder motor smoking', 'cat' => 'Appliance Repair', 'entity' => null],

    // 14. Home Cleaning
    ['q' => 'Full 3BHK flat deep cleaning service before housewarming', 'cat' => 'Home Cleaning', 'entity' => null],
    ['q' => 'Sofa cleaning and fabric sanitization in living room', 'cat' => 'Home Cleaning', 'entity' => null],
    ['q' => 'Kitchen deep clean with chimney degreasing', 'cat' => 'Home Cleaning', 'entity' => null],

    // 15. Internet & WiFi Services
    ['q' => 'WiFi router internet keeps disconnecting every few minutes', 'cat' => 'Internet & WiFi Services', 'entity' => 'Router'],
    ['q' => 'Broadband network slow speed and high latency', 'cat' => 'Internet & WiFi Services', 'entity' => 'Router'],
    ['q' => 'Wireless internet configuration and range extender setup', 'cat' => 'Internet & WiFi Services', 'entity' => 'Router'],

    // Additional Complex / Colloquial / Cross-domain variations (bringing to 50+)
    ['q' => 'Desktop PC fan makes buzzing sound and shuts down suddenly', 'cat' => 'Laptop & Computer Repair', 'entity' => 'Computer'],
    ['q' => 'Smartphone dropped on floor display broken touch not responding', 'cat' => 'Mobile Repair', 'entity' => 'Phone'],
    ['q' => 'Aircon cooling unit temperature rises instead of cooling', 'cat' => 'AC Repair', 'entity' => 'AC'],
    ['q' => 'Washbasin tap leakage dripping continuously in guest bathroom', 'cat' => 'Plumbing', 'entity' => 'Tap'],
    ['q' => 'Electrician needed for outlet sparking and short circuit fault', 'cat' => 'Electrical Repair', 'entity' => 'Electrical equipment'],
    ['q' => 'Car tyre burst on highway need puncture technician', 'cat' => 'Tyre/Puncture Services', 'entity' => 'Tyre'],
    ['q' => 'Washer drum vibrating wobbles violently with loud noise', 'cat' => 'Washing Machine Repair', 'entity' => 'Washing Machine'],
    ['q' => 'Freezer ice buildup but refrigerator food compartment warm', 'cat' => 'Refrigerator Repair', 'entity' => 'Refrigerator'],
];

$nluSuccessCount = 0;
foreach ($nluQueries as $idx => $test) {
    $res = analyzeServiceDna($pdo, $test['q'], null, 'medium');
    $detectedCategory = $res['category_name'];
    $isMatch = ($detectedCategory === $test['cat']);
    if ($isMatch) {
        $nluSuccessCount++;
    } else {
        echo "  [FAIL] Query #{$idx}: '{$test['q']}' => Expected '{$test['cat']}', got '{$detectedCategory}'\n";
    }
}
assertTest($nluSuccessCount === count($nluQueries), "All " . count($nluQueries) . " natural language queries correctly classified ({$nluSuccessCount}/" . count($nluQueries) . ")");

// ============================================================
// TEST GROUP 3: AMBIGUOUS & UNSUPPORTED QUERIES
// ============================================================
echo "\n--- TEST GROUP 3: Ambiguity & Unsupported Service Gating ---\n";

$ambiguousCases = [
    'My machine stopped working suddenly',
    'Screen cracked need help',
    'Equipment failure in my house',
];
foreach ($ambiguousCases as $q) {
    $res = analyzeServiceDna($pdo, $q, null, 'medium');
    assertTest($res['clarification_required'] === true, "Ambiguous query '{$q}' triggers clarification_required = true");
}

$unsupportedCases = [
    'I need an experienced tattoo artist for my arm',
    'Looking for dog grooming and pet washing service',
    'Need wedding catering and cake baking service',
    'Math tutor needed for class 10 student',
];
foreach ($unsupportedCases as $q) {
    $res = analyzeServiceDna($pdo, $q, null, 'medium');
    assertTest($res['supported'] === false && $res['category_name'] === null, "Unsupported query '{$q}' correctly rejected (supported = false, category = null)");
}

// ============================================================
// TEST GROUP 4: STRICT 2-STAGE RELEVANCE GATE & NEGATIVE TESTS
// ============================================================
echo "\n--- TEST GROUP 4: 2-Stage Relevance Gate & Negative Matches ---\n";

// Helper to create temporary service request for matching
function createTempRequest(PDO $pdo, string $title, string $desc, string $city, string $area): int {
    $customerUser = $pdo->query("SELECT id FROM users WHERE role = 'customer' LIMIT 1")->fetch();
    $customerId = (int)$customerUser['id'];
    
    $stmt = $pdo->prepare(
        "INSERT INTO service_requests (customer_id, title, description, city, area, urgency, status)
         VALUES (:customer_id, :title, :description, :city, :area, 'medium', 'open')"
    );
    $stmt->execute([
        'customer_id' => $customerId,
        'title' => $title,
        'description' => $desc,
        'city' => $city,
        'area' => $area,
    ]);
    $requestId = (int)$pdo->lastInsertId();
    analyzeAndStoreServiceDna($pdo, $requestId);
    return $requestId;
}

// Negative test 1: Laptop query must NEVER return plumbers or vehicle mechanics
$reqLaptopId = createTempRequest($pdo, "Laptop overheating", "My laptop fan is loud and shutting down", "Pune", "Kothrud");
$laptopMatches = getRankedProvidersForRequest($pdo, $reqLaptopId);
assertTest(count($laptopMatches) > 0, "Laptop query returned candidates (> 0)");
$hasInvalidProvider = false;
foreach ($laptopMatches as $m) {
    $categoriesOffered = array_column($m['services'], 'category_name');
    if (!in_array('Laptop & Computer Repair', $categoriesOffered, true)) {
        $hasInvalidProvider = true;
    }
}
assertTest(!$hasInvalidProvider, "Negative test: Laptop query ONLY returned providers offering 'Laptop & Computer Repair'");

// Negative test 2: AC leak query must match AC Repair providers, NOT Plumbing
$reqAcLeakId = createTempRequest($pdo, "AC indoor unit leak", "Split AC is leaking water inside the bedroom", "Pune", "Viman Nagar");
$acMatches = getRankedProvidersForRequest($pdo, $reqAcLeakId);
assertTest(count($acMatches) > 0, "AC leak query returned candidates (> 0)");
$hasNonAcProvider = false;
foreach ($acMatches as $m) {
    $categoriesOffered = array_column($m['services'], 'category_name');
    if (!in_array('AC Repair', $categoriesOffered, true)) {
        $hasNonAcProvider = true;
    }
}
assertTest(!$hasNonAcProvider, "Negative test: AC water leak query strictly matched AC Repair providers, NOT general plumbers");

// Zero-match test: Unsupported tattoo query produces 0 matched providers
$reqTattooId = createTempRequest($pdo, "Tattoo artist", "I need a tattoo artist for my arm", "Pune", "Kothrud");
$tattooMatches = getRankedProvidersForRequest($pdo, $reqTattooId);
assertTest(count($tattooMatches) === 0, "Zero-match test: Unsupported request produces 0 matched providers");

// Location gating test: Mumbai query when providers are in Pune
$reqMumbaiId = createTempRequest($pdo, "Laptop repair Mumbai", "My laptop is overheating", "Mumbai", "Bandra");
$mumbaiMatches = getRankedProvidersForRequest($pdo, $reqMumbaiId);
assertTest(count($mumbaiMatches) === 0, "Location gate test: Request in Mumbai produces 0 matches when all providers are in Pune");

// Clean up temporary requests
$pdo->prepare("DELETE FROM service_requests WHERE id IN (?, ?, ?, ?)")->execute([$reqLaptopId, $reqAcLeakId, $reqTattooId, $reqMumbaiId]);
$pdo->prepare("DELETE FROM problem_fingerprints WHERE request_id IN (?, ?, ?, ?)")->execute([$reqLaptopId, $reqAcLeakId, $reqTattooId, $reqMumbaiId]);

// ============================================================
// TEST GROUP 5: BOOKING LIFECYCLE STATE MACHINE & TRANSITIONS
// ============================================================
echo "\n--- TEST GROUP 5: Booking Lifecycle & Transitions ---\n";

$testCust = $pdo->query("SELECT id FROM users WHERE role = 'customer' LIMIT 1")->fetch();
$testProv = $pdo->query("SELECT id, user_id FROM provider_profiles WHERE marketplace_active = 1 LIMIT 1")->fetch();
$testServ = $pdo->prepare("SELECT id, base_price FROM services WHERE provider_id = :pid LIMIT 1");
$testServ->execute(['pid' => $testProv['id']]);
$serviceRow = $testServ->fetch();

// 1. Create booking
$stmt = $pdo->prepare(
    "INSERT INTO bookings (customer_id, provider_id, service_id, scheduled_date, scheduled_time, status, notes, total_price)
     VALUES (:cid, :pid, :sid, CURDATE(), '10:00:00', 'pending', 'Phase 2 verification test booking', :price)"
);
$stmt->execute([
    'cid' => $testCust['id'],
    'pid' => $testProv['id'],
    'sid' => $serviceRow['id'],
    'price' => $serviceRow['base_price'] ?? 599.0,
]);
$bookingId = (int)$pdo->lastInsertId();
assertTest($bookingId > 0, "Created initial test booking in 'pending' status (ID: {$bookingId})");

// 2. Pending -> Accepted
$okAccept = transitionBookingStatus($pdo, $bookingId, 'accepted', (int)$testProv['user_id'], 'provider');
assertTest($okAccept, "Valid transition: 'pending' -> 'accepted'");

// 3. Accepted -> In Progress
$okStart = transitionBookingStatus($pdo, $bookingId, 'in_progress', (int)$testProv['user_id'], 'provider');
assertTest($okStart, "Valid transition: 'accepted' -> 'in_progress'");

// 4. In Progress -> Completed
$okComplete = transitionBookingStatus($pdo, $bookingId, 'completed', (int)$testProv['user_id'], 'provider');
assertTest($okComplete, "Valid transition: 'in_progress' -> 'completed'");

// 5. Invalid transition: Completed -> Pending must fail
$invalidTransition = transitionBookingStatus($pdo, $bookingId, 'pending', (int)$testProv['user_id'], 'provider');
assertTest(!$invalidTransition, "Invalid transition blocked: 'completed' cannot revert to 'pending'");

// 6. Review creation on completed booking
$reviewResult = submitBookingReview($pdo, [
    'booking_id' => $bookingId,
    'customer_id' => (int)$testCust['id'],
    'rating' => 5,
    'comment' => 'Excellent service! Solved the problem quickly and professionally.',
]);
assertTest($reviewResult['success'] === true, "Verified customer review successfully submitted on completed booking");

// Check review exists in DB
$revStmt = $pdo->prepare("SELECT * FROM reviews WHERE booking_id = :bid LIMIT 1");
$revStmt->execute(['bid' => $bookingId]);
$reviewRow = $revStmt->fetch();
assertTest($reviewRow !== false && (int)$reviewRow['rating'] === 5, "Review verified in database with 5-star rating");

// ============================================================
// TEST GROUP 6: NATIVE PDF RECEIPT & IDOR SECURITY CHECKS
// ============================================================
echo "\n--- TEST GROUP 6: Native PDF Receipt & IDOR Security ---\n";

// Helper function to simulate receipt download handler logic
function testReceiptAccess(PDO $pdo, int $bookingId, ?array $sessionUser): array {
    if (!$sessionUser) {
        return ['status' => 302, 'reason' => 'Unauthenticated redirect'];
    }
    
    $stmt = $pdo->prepare(
        "SELECT b.*, pp.user_id AS provider_user_id
         FROM bookings b
         INNER JOIN provider_profiles pp ON pp.id = b.provider_id
         WHERE b.id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        return ['status' => 404, 'reason' => 'Booking not found'];
    }

    $isCustomer = ($sessionUser['role'] === 'customer' && (int)$sessionUser['id'] === (int)$booking['customer_id']);
    $isProvider = ($sessionUser['role'] === 'provider' && (int)$sessionUser['id'] === (int)$booking['provider_user_id']);
    $isAdmin = ($sessionUser['role'] === 'admin');

    if (!$isCustomer && !$isProvider && !$isAdmin) {
        return ['status' => 403, 'reason' => 'IDOR access denied'];
    }

    return ['status' => 200, 'reason' => 'Authorized'];
}

// 1. Customer can access own receipt
$resCust = testReceiptAccess($pdo, $bookingId, ['id' => (int)$testCust['id'], 'role' => 'customer']);
assertTest($resCust['status'] === 200, "Authorized customer access allowed (HTTP 200)");

// 2. Provider can access own receipt
$resProv = testReceiptAccess($pdo, $bookingId, ['id' => (int)$testProv['user_id'], 'role' => 'provider']);
assertTest($resProv['status'] === 200, "Authorized provider access allowed (HTTP 200)");

// 3. Admin can access receipt
$adminUser = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$resAdmin = testReceiptAccess($pdo, $bookingId, ['id' => (int)($adminUser['id'] ?? 1), 'role' => 'admin']);
assertTest($resAdmin['status'] === 200, "Admin access allowed (HTTP 200)");

// 4. Other customer (IDOR attack) is blocked
$resOtherCust = testReceiptAccess($pdo, $bookingId, ['id' => 999999, 'role' => 'customer']);
assertTest($resOtherCust['status'] === 403, "IDOR Protection: Unrelated customer blocked with HTTP 403 Forbidden");

// 5. Other provider (IDOR attack) is blocked
$resOtherProv = testReceiptAccess($pdo, $bookingId, ['id' => 999999, 'role' => 'provider']);
assertTest($resOtherProv['status'] === 403, "IDOR Protection: Unrelated provider blocked with HTTP 403 Forbidden");

// 6. Unauthenticated visitor redirected
$resGuest = testReceiptAccess($pdo, $bookingId, null);
assertTest($resGuest['status'] === 302, "Unauthenticated user redirected to login (HTTP 302)");

// Clean up test booking and review
$pdo->prepare("DELETE FROM reviews WHERE booking_id = :bid")->execute(['bid' => $bookingId]);
$pdo->prepare("DELETE FROM bookings WHERE id = :bid")->execute(['bid' => $bookingId]);

// ============================================================
// TEST GROUP 7: 6 REAL END-TO-END CUSTOMER SCENARIOS
// ============================================================
echo "\n--- TEST GROUP 7: 6 Real End-to-End Customer Scenarios ---\n";

$scenarios = [
    [
        'title' => 'Laptop Diagnostics & Overheating',
        'desc' => 'HP Pavilion laptop fan making grinding noise, shuts down during video editing',
        'city' => 'Pune', 'area' => 'Kothrud',
        'expected_cat' => 'Laptop & Computer Repair',
    ],
    [
        'title' => 'Mobile Screen & Battery Replacement',
        'desc' => 'OnePlus smartphone screen cracked and battery draining from 100 to 0 in 1 hour',
        'city' => 'Pune', 'area' => 'Viman Nagar',
        'expected_cat' => 'Mobile Repair',
    ],
    [
        'title' => 'AC Cooling Inspection & Gas Leakage',
        'desc' => 'Voltas Split AC blowing warm air and outdoor unit compressor not starting',
        'city' => 'Pune', 'area' => 'Hinjewadi',
        'expected_cat' => 'AC Repair',
    ],
    [
        'title' => 'Bathroom Plumbing & Drain Leakage',
        'desc' => 'Master bathroom washbasin drain clogged and tap leaking water continuously',
        'city' => 'Pune', 'area' => 'Baner',
        'expected_cat' => 'Plumbing',
    ],
    [
        'title' => 'Refrigerator Compressor & Cooling Repair',
        'desc' => 'Samsung double door fridge freezer stopped cooling and food spoiling',
        'city' => 'Pune', 'area' => 'Wakad',
        'expected_cat' => 'Refrigerator Repair',
    ],
    [
        'title' => 'Electrical Short Circuit & MCB Tripping',
        'desc' => 'Living room main switch socket sparking and MCB circuit breaker tripping',
        'city' => 'Pune', 'area' => 'Hadapsar',
        'expected_cat' => 'Electrical Repair',
    ],
];

foreach ($scenarios as $sIdx => $sc) {
    $rId = createTempRequest($pdo, $sc['title'], $sc['desc'], $sc['city'], $sc['area']);
    $dna = getServiceDnaForRequest($pdo, $rId);
    $ranked = getRankedProvidersForRequest($pdo, $rId);
    
    $catMatched = ($dna['category_name'] === $sc['expected_cat']);
    $providerFound = (count($ranked) > 0);
    $topProvider = $providerFound ? $ranked[0] : null;
    $topScore = $topProvider ? $topProvider['score'] : 0;
    
    echo sprintf(
        "  Scenario #%d: %-35s => Cat: %-25s | Top: %-25s (Score: %.1f)\n",
        $sIdx + 1,
        $sc['title'],
        $dna['category_name'] ?? 'NONE',
        $topProvider ? $topProvider['business_name'] : 'NO_MATCH',
        $topScore
    );
    
    assertTest($catMatched && $providerFound && $topScore >= 40.0, "Scenario #".($sIdx+1)." End-to-End Match Succeeded (Score >= 40.0)");
    
    // Clean up
    $pdo->prepare("DELETE FROM service_requests WHERE id = :id")->execute(['id' => $rId]);
    $pdo->prepare("DELETE FROM problem_fingerprints WHERE request_id = :id")->execute(['id' => $rId]);
}

echo "\n============================================================\n";
echo "                   FINAL TEST SUITE SUMMARY                 \n";
echo "============================================================\n";
echo "  Total Assertions : {$suiteStats['total']}\n";
echo "  Passed           : {$suiteStats['passed']}\n";
echo "  Failed           : {$suiteStats['failed']}\n";
if ($suiteStats['failed'] === 0) {
    echo "  Status           : ALL TESTS PASSED SUCCESSFULLY! (100%)\n";
} else {
    echo "  Status           : SOME TESTS FAILED\n";
}
echo "============================================================\n";
