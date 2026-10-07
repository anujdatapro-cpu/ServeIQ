<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';

$apply = ($argv[1] ?? '') === '--apply';
$backupFiles = glob(__DIR__ . '/backups/serveiq_phase2_before_*.sql') ?: [];
usort($backupFiles, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
$backupPath = $backupFiles[0] ?? '';
if ($backupPath === '' || !is_file($backupPath) || filesize($backupPath) < 1_000_000) {
    throw new RuntimeException('A verified Phase 2 logical backup is required before activation.');
}

$quotas = [
    'Laptop & Computer Repair' => 8,
    'Mobile Repair' => 8,
    'AC Repair' => 8,
    'Plumbing' => 8,
    'Electrical Repair' => 7,
    'CCTV & Security Installation' => 6,
    'Tyre/Puncture Services' => 7,
    'Vehicle Repair' => 7,
    'Washing Machine Repair' => 7,
    'Refrigerator Repair' => 6,
    'TV Repair' => 6,
    'RO/Water Purifier Service' => 5,
    'Appliance Repair' => 5,
    'Home Cleaning' => 5,
    'Internet & WiFi Services' => 7,
];
$targetCount = array_sum($quotas);
if ($targetCount !== 100) {
    throw new LogicException('Marketplace category quotas must select exactly 100 providers.');
}

$pdo = getDatabaseConnection();
$columns = array_column($pdo->query('SHOW COLUMNS FROM provider_profiles')->fetchAll(), 'Field');
$hasMarketplaceStatus = in_array('marketplace_active', $columns, true);

$candidateSql = <<<'SQL'
SELECT pp.id, pp.user_id, pp.business_name, pp.description, pp.city, pp.area,
       pp.latitude, pp.longitude, pp.location_source, pp.availability_status,
       pp.response_time_minutes, pp.response_time_source, u.name AS provider_name, u.email,
       c.category_name, COUNT(DISTINCT s.id) AS service_count,
       COALESCE(rs.average_rating, 0) AS average_rating,
       COALESCE(rs.review_count, 0) AS review_count
FROM provider_profiles pp
JOIN users u ON u.id = pp.user_id AND u.role = 'provider'
JOIN services s ON s.provider_id = pp.id AND s.is_active = 1
JOIN service_categories c ON c.id = s.category_id AND c.is_active = 1
LEFT JOIN (
    SELECT provider_id, AVG(rating) AS average_rating, COUNT(*) AS review_count
    FROM reviews WHERE status = 'published' GROUP BY provider_id
) rs ON rs.provider_id = pp.id
WHERE u.email REGEXP '^demo[.]provider[0-9]{3}@serveiq[.]local$'
  AND pp.verification_status = 'approved'
GROUP BY pp.id, pp.user_id, pp.business_name, pp.description, pp.city, pp.area,
         pp.latitude, pp.longitude, pp.location_source, pp.availability_status,
         pp.response_time_minutes, pp.response_time_source, u.name, u.email,
         c.id, c.category_name, rs.average_rating, rs.review_count
SQL;
$candidates = $pdo->query($candidateSql)->fetchAll();
$byCategory = [];
foreach ($candidates as $candidate) {
    $candidate['id'] = (int)$candidate['id'];
    $candidate['user_id'] = (int)$candidate['user_id'];
    $candidate['service_count'] = (int)$candidate['service_count'];
    $candidate['review_count'] = (int)$candidate['review_count'];
    $candidate['average_rating'] = (float)$candidate['average_rating'];
    $responseTime = $candidate['response_time_minutes'] === null ? 240 : (int)$candidate['response_time_minutes'];
    $candidate['selection_score'] = ($candidate['average_rating'] * 6)
        + (min(10, $candidate['review_count']) * 0.35)
        + (min(5, $candidate['service_count']) * 0.6)
        + max(0, 4 - ($responseTime / 60));
    $byCategory[(string)$candidate['category_name']][] = $candidate;
}

$selected = [];
$areaUse = [];
$chooseForStatus = static function (array $pool, int $needed, array &$selected, array &$areaUse): int {
    $added = 0;
    while ($added < $needed) {
        $available = array_values(array_filter($pool, static fn(array $row): bool => !isset($selected[$row['id']])));
        if ($available === []) break;
        usort($available, static function (array $left, array $right) use (&$areaUse): int {
            $leftArea = (string)($left['area'] ?? '');
            $rightArea = (string)($right['area'] ?? '');
            $leftUse = $areaUse[$leftArea] ?? 0;
            $rightUse = $areaUse[$rightArea] ?? 0;
            return [$leftUse, -$left['selection_score'], $left['id']]
                <=> [$rightUse, -$right['selection_score'], $right['id']];
        });
        $pick = $available[0];
        $selected[$pick['id']] = $pick;
        $area = (string)($pick['area'] ?? '');
        $areaUse[$area] = ($areaUse[$area] ?? 0) + 1;
        $added++;
    }
    return $added;
};

foreach ($quotas as $category => $quota) {
    $pool = $byCategory[$category] ?? [];
    $offlineCount = max(1, (int)round($quota * 0.10));
    $busyCount = max(1, (int)round($quota * 0.20));
    $statusTargets = [
        'available' => $quota - $offlineCount - $busyCount,
        'busy' => $busyCount,
        'offline' => $offlineCount,
    ];
    foreach ($statusTargets as $status => $needed) {
        $statusPool = array_values(array_filter($pool, static fn(array $row): bool => $row['availability_status'] === $status));
        usort($statusPool, static fn(array $a, array $b): int => [$b['selection_score'], $a['id']] <=> [$a['selection_score'], $b['id']]);
        $added = $chooseForStatus($statusPool, $needed, $selected, $areaUse);
        if ($added < $needed) {
            $fallbackPool = array_values(array_filter($pool, static fn(array $row): bool => !isset($selected[$row['id']])));
            usort($fallbackPool, static fn(array $a, array $b): int => [$b['selection_score'], $a['id']] <=> [$a['selection_score'], $b['id']]);
            if ($chooseForStatus($fallbackPool, $needed - $added, $selected, $areaUse) < $needed - $added) {
                throw new RuntimeException('Not enough eligible demo providers in category: ' . $category);
            }
        }
    }
}

if (count($selected) !== $targetCount) {
    throw new RuntimeException('Selection produced ' . count($selected) . ' providers; expected exactly ' . $targetCount . '.');
}

$categoryCounts = [];
$availabilityCounts = [];
foreach ($selected as $candidate) {
    $categoryCounts[$candidate['category_name']] = ($categoryCounts[$candidate['category_name']] ?? 0) + 1;
    $availabilityCounts[$candidate['availability_status']] = ($availabilityCounts[$candidate['availability_status']] ?? 0) + 1;
}

printf("Backup prerequisite: %s (%d bytes)\n", $backupPath, filesize($backupPath));
printf("Selection preview: %d marketplace-active demo providers\n", count($selected));
foreach ($quotas as $category => $quota) printf("  %s: %d\n", $category, $categoryCounts[$category] ?? 0);
printf("Availability: available %d, busy %d, offline %d\n", $availabilityCounts['available'] ?? 0, $availabilityCounts['busy'] ?? 0, $availabilityCounts['offline'] ?? 0);
if (!$apply) {
    echo "Preview only. Run with --apply to add the status column and activate the selected demo providers.\n";
    exit(0);
}

if (!$hasMarketplaceStatus) {
    $pdo->exec('ALTER TABLE provider_profiles ADD COLUMN marketplace_active TINYINT(1) NOT NULL DEFAULT 0 AFTER verification_status, ADD INDEX idx_provider_marketplace_status (marketplace_active, verification_status, availability_status)');
}

$areas = [
    'Kothrud' => [18.5074,73.8077], 'Baner' => [18.5590,73.7868], 'Aundh' => [18.5602,73.8075],
    'Wakad' => [18.5990,73.7626], 'Hinjewadi' => [18.5913,73.7389], 'Viman Nagar' => [18.5679,73.9143],
    'Kharadi' => [18.5515,73.9348], 'Hadapsar' => [18.5089,73.9259], 'Kondhwa' => [18.4635,73.8890],
    'Pimpri' => [18.6298,73.7997], 'Chinchwad' => [18.6405,73.7722], 'Shivajinagar' => [18.5308,73.8475],
    'Deccan' => [18.5164,73.8410], 'Katraj' => [18.4529,73.8672], 'Warje' => [18.4834,73.8077],
    'Pashan' => [18.5388,73.7920], 'Bavdhan' => [18.5204,73.7830], 'Koregaon Park' => [18.5362,73.8939],
    'Camp' => [18.5130,73.8790], 'Yerawada' => [18.5513,73.8797], 'Dhanori' => [18.5971,73.8860],
    'Lohegaon' => [18.5990,73.9167], 'Magarpatta' => [18.5166,73.9270], 'Wagholi' => [18.5793,73.9800],
    'Bibwewadi' => [18.4766,73.8661], 'Dhayari' => [18.4430,73.8078],
];
$serviceLabels = [
    'Laptop & Computer Repair' => 'Device Care', 'Mobile Repair' => 'Mobile Workshop', 'AC Repair' => 'Cooling Service',
    'Plumbing' => 'Plumbing Works', 'Electrical Repair' => 'Electrical Service',
    'CCTV & Security Installation' => 'Security Systems', 'Tyre/Puncture Services' => 'Tyre & Wheel',
    'Vehicle Repair' => 'Auto Service', 'Washing Machine Repair' => 'Laundry Appliance Care',
    'Refrigerator Repair' => 'Refrigeration Service', 'TV Repair' => 'Television Service',
    'RO/Water Purifier Service' => 'Water Systems', 'Appliance Repair' => 'Appliance Care',
    'Home Cleaning' => 'Home Cleaning', 'Internet & WiFi Services' => 'Network Support',
];
$brands = ['Northstar','Cedarline','Meridian','Brightline','Oak & Ember','Fieldstone','Kindred','Westward','Juniper','Bluepeak','Harborlight','Common Ground','Pine & Wire','Daymark','Openlane','Everwell','Morrow','Nimble','Goodhouse','TrueNorth'];

$pdo->beginTransaction();
try {
    $pdo->exec('UPDATE provider_profiles SET marketplace_active = 0');
    $activate = $pdo->prepare('UPDATE provider_profiles SET marketplace_active = 1 WHERE id = :id');
    $updateProfile = $pdo->prepare('UPDATE provider_profiles SET business_name=:business_name,description=:description,area=:area,latitude=:latitude,longitude=:longitude,location_source=\'demo_estimate\' WHERE id=:id');
    $updateUser = $pdo->prepare('UPDATE users SET name=:name WHERE id=:id');
    $cleanServices = $pdo->prepare('UPDATE services SET description=TRIM(REGEXP_REPLACE(description, \'[[:space:]]*Synthetic demo service listing [0-9]{3}\\.?\', \'\')) WHERE provider_id=:provider_id');
    $hashIndex = 0;
    foreach ($selected as $candidate) {
        $activate->execute(['id' => $candidate['id']]);
        $stableHash = hexdec(substr(hash('sha256', (string)$candidate['email']), 0, 8));
        $areaNames = array_keys($areas);
        $areaName = $areaNames[$stableHash % count($areaNames)];
        $jitterLat = ((($stableHash >> 3) % 7) - 3) * 0.00035;
        $jitterLon = ((($stableHash >> 6) % 7) - 3) * 0.00035;
        [$latitude, $longitude] = $areas[$areaName];
        $brand = $brands[$stableHash % count($brands)];
        $businessName = $brand . ' ' . $areaName . ' ' . ($serviceLabels[$candidate['category_name']] ?? 'Service Studio');
        $profileDescription = preg_replace('/\s*Synthetic ServeIQ demo profile listing [0-9]{3}; contact details are fictional\.?/i', '', (string)$candidate['description']) ?? (string)$candidate['description'];
        $profileDescription = trim($profileDescription) . ' Serving ' . $areaName . ' and nearby neighborhoods.';
        $cleanName = preg_replace('/\s*\(Demo [0-9]{3}\)$/', '', (string)$candidate['provider_name']) ?? (string)$candidate['provider_name'];
        $updateProfile->execute([
            'business_name' => $businessName,
            'description' => $profileDescription,
            'area' => $areaName,
            'latitude' => number_format($latitude + $jitterLat, 7, '.', ''),
            'longitude' => number_format($longitude + $jitterLon, 7, '.', ''),
            'id' => $candidate['id'],
        ]);
        $updateUser->execute(['name' => $cleanName, 'id' => $candidate['user_id']]);
        $cleanServices->execute(['provider_id' => $candidate['id']]);
        $hashIndex++;
    }
    $activeCount = (int)$pdo->query('SELECT COUNT(*) FROM provider_profiles WHERE marketplace_active=1')->fetchColumn();
    if ($activeCount !== 100) throw new RuntimeException('Post-update active provider count is not 100.');
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}

echo "Marketplace activation applied. Historical users, services, bookings, and reviews were retained.\n";
printf("Active demo providers: %d; inactive/historical providers: %d\n", 100, (int)$pdo->query('SELECT COUNT(*) FROM provider_profiles WHERE marketplace_active=0')->fetchColumn());
