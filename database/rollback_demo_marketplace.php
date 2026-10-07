<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();
$prefix = '[DEMO FIXTURE: provider-';
$prefixLength = strlen($prefix);
$demoUsers = $pdo->query("SELECT id,role FROM users WHERE email REGEXP '^demo[.]customer[0-9]{2}@serveiq[.]local$' OR email REGEXP '^demo[.]provider[0-9]{3}@serveiq[.]local$'")->fetchAll();
$userIds = array_map(static fn(array $row): int => (int)$row['id'], $demoUsers);
$providers = $pdo->query("SELECT pp.id,pp.profile_image FROM provider_profiles pp INNER JOIN users u ON u.id=pp.user_id WHERE u.email REGEXP '^demo[.]provider[0-9]{3}@serveiq[.]local$'")->fetchAll();
$providerIds = array_map(static fn(array $row): int => (int)$row['id'], $providers);
$seedCategoryRows = $pdo->query("SELECT id FROM service_categories WHERE description LIKE '[ServeIQ Demo Seed]%'")->fetchAll();
$seedCategoryIds = array_map(static fn(array $row): int => (int)$row['id'], $seedCategoryRows);
$demoCustomerUsers = array_values(array_map(static fn(array $row): int => (int)$row['id'], array_filter($demoUsers, static fn(array $row): bool => $row['role'] === 'customer')));
$fixtureCustomers = $demoCustomerUsers === [] ? '0' : implode(',', $demoCustomerUsers);
$fixtureClause = 'LEFT(description,' . $prefixLength . ')=' . $pdo->quote($prefix);
$fixtureCount = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE customer_id IN ($fixtureCustomers) AND $fixtureClause")->fetchColumn();
$guards = [];
if ($demoCustomerUsers !== []) {
    $customerList = implode(',', $demoCustomerUsers);
    $guards['non-demo requests by demo customers'] = (int)$pdo->query("SELECT COUNT(*) FROM service_requests WHERE customer_id IN ($customerList) AND NOT ($fixtureClause)")->fetchColumn();
}
if ($providerIds !== []) {
    $providerList = implode(',', $providerIds);
    $notFixtureRequest = "request_id NOT IN (SELECT id FROM service_requests WHERE $fixtureClause)";
    $guards['non-demo provider responses'] = (int)$pdo->query("SELECT COUNT(*) FROM provider_responses WHERE provider_id IN ($providerList) AND $notFixtureRequest")->fetchColumn();
    $guards['non-demo bookings'] = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE provider_id IN ($providerList) AND $notFixtureRequest")->fetchColumn();
    $guards['non-demo assessments'] = (int)$pdo->query("SELECT COUNT(*) FROM provider_assessments WHERE provider_id IN ($providerList) AND $notFixtureRequest")->fetchColumn();
    $guards['non-demo matching records'] = (int)$pdo->query("SELECT COUNT(*) FROM matching_results WHERE provider_id IN ($providerList) AND $notFixtureRequest")->fetchColumn();
}
if ($seedCategoryIds !== []) {
    $categoryList = implode(',', $seedCategoryIds);
    $guards['non-demo services using seed categories'] = (int)$pdo->query("SELECT COUNT(*) FROM services s JOIN provider_profiles pp ON pp.id=s.provider_id JOIN users u ON u.id=pp.user_id WHERE s.category_id IN ($categoryList) AND u.email NOT REGEXP '^demo[.]provider[0-9]{3}@serveiq[.]local$'")->fetchColumn();
    $guards['non-demo requests using seed categories'] = (int)$pdo->query("SELECT COUNT(*) FROM service_requests r LEFT JOIN problem_fingerprints f ON f.request_id=r.id WHERE (r.category_id IN ($categoryList) OR f.detected_category_id IN ($categoryList)) AND NOT (r.customer_id IN ($fixtureCustomers) AND LEFT(r.description,$prefixLength)=" . $pdo->quote($prefix) . ')')->fetchColumn();
}
echo "Demo marketplace rollback preview\n";
printf("Demo accounts: %d\nDemo provider profiles: %d\nDemo seed-created categories: %d\nDemo review fixture requests: %d\n", count($demoUsers), count($providers), count($seedCategoryIds), $fixtureCount);
foreach ($guards as $label => $count) printf("%s: %d\n", $label, $count);
if (array_sum($guards) > 0) { fwrite(STDERR, "Rollback stopped because demo accounts or providers now have non-demo workflow data.\n"); exit(2); }
if (($argv[1] ?? '') !== '--confirm=DELETE-DEMO-SEED') { echo "Preview only. Re-run with --confirm=DELETE-DEMO-SEED to remove the seed.\n"; exit(0); }

$pdo->beginTransaction();
try {
    $requestIds = $demoCustomerUsers === [] ? [] : array_map('intval', $pdo->query("SELECT id FROM service_requests WHERE customer_id IN ($fixtureCustomers) AND $fixtureClause")->fetchAll(PDO::FETCH_COLUMN));
    if ($requestIds !== []) {
        $requestList = implode(',', $requestIds);
        $pdo->exec("DELETE FROM reviews WHERE booking_id IN (SELECT id FROM bookings WHERE request_id IN ($requestList))");
        $pdo->exec("DELETE FROM booking_status_history WHERE booking_id IN (SELECT id FROM bookings WHERE request_id IN ($requestList))");
        $pdo->exec("DELETE FROM bookings WHERE request_id IN ($requestList)");
        $pdo->exec("DELETE FROM provider_responses WHERE request_id IN ($requestList)");
        $pdo->exec("DELETE FROM provider_assessments WHERE request_id IN ($requestList)");
        $pdo->exec("DELETE FROM matching_results WHERE request_id IN ($requestList)");
        $pdo->exec("DELETE FROM adcs_results WHERE request_id IN ($requestList)");
        $pdo->exec("DELETE FROM problem_fingerprints WHERE request_id IN ($requestList)");
        $pdo->exec("DELETE FROM request_images WHERE request_id IN ($requestList)");
        $pdo->exec("DELETE FROM service_requests WHERE id IN ($requestList)");
    }
    if ($providerIds !== []) {
        $providerList = implode(',', $providerIds);
        $pdo->exec("DELETE FROM services WHERE provider_id IN ($providerList)");
        $pdo->exec("DELETE FROM provider_profiles WHERE id IN ($providerList)");
    }
    if ($userIds !== []) $pdo->exec('DELETE FROM users WHERE id IN (' . implode(',', $userIds) . ')');
    if ($seedCategoryIds !== []) $pdo->exec("DELETE FROM service_categories WHERE id IN (" . implode(',', $seedCategoryIds) . ") AND description LIKE '[ServeIQ Demo Seed]%'");
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}

$avatarRoot = realpath(dirname(__DIR__) . '/uploads/profiles');
if ($avatarRoot !== false) foreach ($providers as $provider) {
    $relative = (string)($provider['profile_image'] ?? '');
    if (!preg_match('~^uploads/profiles/demo-provider-[0-9]{3}\.svg$~', $relative)) continue;
    $path = realpath(dirname(__DIR__) . '/' . $relative);
    if ($path !== false && dirname($path) === $avatarRoot && is_file($path)) unlink($path);
}
printf("Removed %d demo accounts, %d demo provider profiles, %d demo fixture requests, and their dependent records.\n", count($demoUsers), count($providers), $fixtureCount);
