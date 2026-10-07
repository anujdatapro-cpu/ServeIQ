<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();

echo "============================================================\n";
echo "PHASE 2A LIVE MARKETPLACE DETAILED AUDIT\n";
echo "============================================================\n";

// 1. Customer count
$customers = $pdo->query("SELECT id, name, email, is_email_verified FROM users WHERE role = 'customer' ORDER BY id")->fetchAll();
echo "Total Customer Users: " . count($customers) . "\n";
$demoCustomers = array_filter($customers, fn($c) => preg_match('/^demo\.customer\d+@serveiq\.local$/', $c['email']));
$nonDemoCustomers = array_filter($customers, fn($c) => !preg_match('/^demo\.customer\d+@serveiq\.local$/', $c['email']));
echo "Demo Customers (@serveiq.local): " . count($demoCustomers) . "\n";
echo "Non-demo / other Customers: " . count($nonDemoCustomers) . "\n";
foreach ($nonDemoCustomers as $nd) {
    echo "  Non-demo customer: ID {$nd['id']} | {$nd['name']} | {$nd['email']} | is_email_verified: {$nd['is_email_verified']}\n";
}

// 2. Provider users & profiles
$allProviderUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'")->fetchColumn();
$allProviderProfiles = (int)$pdo->query("SELECT COUNT(*) FROM provider_profiles")->fetchColumn();
$activeProviders = (int)$pdo->query("SELECT COUNT(*) FROM provider_profiles WHERE marketplace_active = 1")->fetchColumn();
$inactiveProviders = (int)$pdo->query("SELECT COUNT(*) FROM provider_profiles WHERE marketplace_active = 0")->fetchColumn();
echo "\nTotal Provider Users: {$allProviderUsers}\n";
echo "Total Provider Profiles: {$allProviderProfiles}\n";
echo "Active Marketplace Providers: {$activeProviders}\n";
echo "Inactive/Historical Providers: {$inactiveProviders}\n";

// 3. Category coverage
$categories = $pdo->query("SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY id")->fetchAll();
echo "\nTotal Categories: " . count($categories) . "\n";
echo str_pad("Category", 32) . " | Active Providers | Active Services | Relationships\n";
echo str_repeat("-", 80) . "\n";

$catCoverage = [];
foreach ($categories as $cat) {
    $catId = (int)$cat['id'];
    $catName = (string)$cat['category_name'];
    
    // Providers with at least one active service in this category
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT pp.id) 
        FROM provider_profiles pp
        JOIN services s ON s.provider_id = pp.id AND s.is_active = 1
        WHERE pp.marketplace_active = 1 AND s.category_id = :cat_id
    ");
    $stmt->execute(['cat_id' => $catId]);
    $pCount = (int)$stmt->fetchColumn();

    // Active services in this category from active providers
    $stmt = $pdo->prepare("
        SELECT COUNT(s.id) 
        FROM services s
        JOIN provider_profiles pp ON pp.id = s.provider_id
        WHERE pp.marketplace_active = 1 AND s.is_active = 1 AND s.category_id = :cat_id
    ");
    $stmt->execute(['cat_id' => $catId]);
    $sCount = (int)$stmt->fetchColumn();

    $catCoverage[$catName] = ['providers' => $pCount, 'services' => $sCount];
    echo str_pad($catName, 32) . " | " . str_pad((string)$pCount, 16) . " | " . str_pad((string)$sCount, 15) . " | {$sCount}\n";
}

// 4. Relationships total
$relCount = (int)$pdo->query("
    SELECT COUNT(s.id) 
    FROM services s
    JOIN provider_profiles pp ON pp.id = s.provider_id
    WHERE pp.marketplace_active = 1 AND s.is_active = 1
")->fetchColumn();
echo "\nTotal Active Provider-Service Relationships: {$relCount}\n";

// 5. Multi-service providers stats
$multiStats = $pdo->query("
    SELECT s_count, COUNT(*) as p_count FROM (
        SELECT pp.id, COUNT(s.id) as s_count
        FROM provider_profiles pp
        JOIN services s ON s.provider_id = pp.id AND s.is_active = 1
        WHERE pp.marketplace_active = 1
        GROUP BY pp.id
    ) sub GROUP BY s_count ORDER BY s_count ASC
")->fetchAll();
echo "Multi-service distribution among 100 active providers:\n";
foreach ($multiStats as $ms) {
    echo "  {$ms['s_count']} services: {$ms['p_count']} providers\n";
}

// Check distinct categories per provider
$catPerProv = $pdo->query("
    SELECT cat_count, COUNT(*) as p_count FROM (
        SELECT pp.id, COUNT(DISTINCT s.category_id) as cat_count
        FROM provider_profiles pp
        JOIN services s ON s.provider_id = pp.id AND s.is_active = 1
        WHERE pp.marketplace_active = 1
        GROUP BY pp.id
    ) sub GROUP BY cat_count ORDER BY cat_count ASC
")->fetchAll();
echo "Distinct categories per provider:\n";
foreach ($catPerProv as $cp) {
    echo "  {$cp['cat_count']} categories: {$cp['p_count']} providers\n";
}

// 6. Availability distribution
$avail = $pdo->query("
    SELECT availability_status, COUNT(*) as c
    FROM provider_profiles
    WHERE marketplace_active = 1
    GROUP BY availability_status
")->fetchAll();
echo "\nAvailability status among active providers:\n";
foreach ($avail as $a) {
    echo "  {$a['availability_status']}: {$a['c']}\n";
}

// 7. Ratings distribution
$ratingStats = $pdo->query("
    SELECT 
        MIN(r.rating) as min_rating,
        MAX(r.rating) as max_rating,
        AVG(r.rating) as avg_rating
    FROM reviews r
    JOIN provider_profiles pp ON pp.id = r.provider_id
    WHERE pp.marketplace_active = 1
")->fetch();
echo "\nRatings for active providers: min={$ratingStats['min_rating']}, max={$ratingStats['max_rating']}, avg=" . round((float)$ratingStats['avg_rating'], 2) . "\n";

// 8. Prices distribution
$priceStats = $pdo->query("
    SELECT 
        MIN(s.base_price) as min_price,
        MAX(s.base_price) as max_price,
        AVG(s.base_price) as avg_price
    FROM services s
    JOIN provider_profiles pp ON pp.id = s.provider_id
    WHERE pp.marketplace_active = 1 AND s.is_active = 1
")->fetch();
echo "Prices for active services: min=₹{$priceStats['min_price']}, max=₹{$priceStats['max_price']}, avg=₹" . round((float)$priceStats['avg_price'], 2) . "\n";

// 9. Response times
$respStats = $pdo->query("
    SELECT 
        MIN(response_time_minutes) as min_resp,
        MAX(response_time_minutes) as max_resp,
        AVG(response_time_minutes) as avg_resp
    FROM provider_profiles
    WHERE marketplace_active = 1
")->fetch();
echo "Response times (mins): min={$respStats['min_resp']}, max={$respStats['max_resp']}, avg=" . round((float)$respStats['avg_resp'], 1) . "\n";

// 10. Geographic areas
$areas = $pdo->query("
    SELECT area, COUNT(*) as c
    FROM provider_profiles
    WHERE marketplace_active = 1
    GROUP BY area
    ORDER BY c DESC
")->fetchAll();
echo "\nGeographic distribution (" . count($areas) . " areas across Pune):\n";
foreach ($areas as $ar) {
    echo "  {$ar['area']}: {$ar['c']} providers\n";
}

// 11. Orphan records check
echo "\nOrphan check:\n";
$orphanProfiles = $pdo->query("SELECT COUNT(*) FROM provider_profiles pp LEFT JOIN users u ON u.id = pp.user_id WHERE u.id IS NULL")->fetchColumn();
$orphanServices = $pdo->query("SELECT COUNT(*) FROM services s LEFT JOIN provider_profiles pp ON pp.id = s.provider_id WHERE pp.id IS NULL")->fetchColumn();
$orphanBookings = $pdo->query("SELECT COUNT(*) FROM bookings b LEFT JOIN service_requests sr ON sr.id = b.request_id WHERE sr.id IS NULL")->fetchColumn();
$orphanReviews = $pdo->query("SELECT COUNT(*) FROM reviews r LEFT JOIN bookings b ON b.id = r.booking_id WHERE b.id IS NULL")->fetchColumn();
$orphanRequests = $pdo->query("SELECT COUNT(*) FROM service_requests sr LEFT JOIN users u ON u.id = sr.customer_id WHERE u.id IS NULL")->fetchColumn();
echo "Orphan provider profiles: {$orphanProfiles}\n";
echo "Orphan services: {$orphanServices}\n";
echo "Orphan bookings: {$orphanBookings}\n";
echo "Orphan reviews: {$orphanReviews}\n";
echo "Orphan requests: {$orphanRequests}\n";

