<?php
declare(strict_types=1);
require __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();
$tables = [
    'users', 'provider_profiles', 'services', 'service_categories',
    'service_requests', 'bookings', 'reviews', 'problem_fingerprints',
    'matching_results', 'provider_assessments', 'adcs_results'
];

echo "=== LIVE DATABASE AUDIT ===\n";
foreach ($tables as $t) {
    $c = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    echo "{$t}: {$c}\n";
}

$cust = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$prov = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'provider'")->fetchColumn();
$adm = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
echo "Users breakdown -> Customers: {$cust}, Providers: {$prov}, Admins: {$adm}\n";

$cols = array_column($pdo->query('SHOW COLUMNS FROM provider_profiles')->fetchAll(), 'Field');
echo "provider_profiles columns: " . implode(', ', $cols) . "\n";
if (in_array('marketplace_active', $cols, true)) {
    $active = $pdo->query('SELECT COUNT(*) FROM provider_profiles WHERE marketplace_active = 1')->fetchColumn();
    $inactive = $pdo->query('SELECT COUNT(*) FROM provider_profiles WHERE marketplace_active = 0')->fetchColumn();
    echo "provider_profiles -> marketplace_active=1: {$active}, marketplace_active=0: {$inactive}\n";
} else {
    echo "marketplace_active column does NOT exist yet.\n";
}

// Check active services belonging to active providers
if (in_array('marketplace_active', $cols, true)) {
    $activeServices = $pdo->query('
        SELECT COUNT(s.id) 
        FROM services s 
        JOIN provider_profiles pp ON pp.id = s.provider_id 
        WHERE pp.marketplace_active = 1 AND s.is_active = 1
    ')->fetchColumn();
    echo "Active services for active providers: {$activeServices}\n";
}
