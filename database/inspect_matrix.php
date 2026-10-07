<?php
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

$pdo = getDatabaseConnection();

$stmt = $pdo->query("
    SELECT 
        pp.id,
        pp.business_name,
        u.name as provider_name,
        pp.availability_status,
        pp.response_time_minutes,
        pp.area,
        pp.city,
        COALESCE(rv.avg_rating, 0) as avg_rating,
        COALESCE(rv.rev_count, 0) as rev_count,
        COUNT(s.id) as service_count,
        GROUP_CONCAT(DISTINCT c.category_name ORDER BY c.category_name SEPARATOR '; ') as categories,
        GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ' | ') as service_names
    FROM provider_profiles pp
    JOIN users u ON u.id = pp.user_id
    JOIN services s ON s.provider_id = pp.id AND s.is_active = 1
    JOIN service_categories c ON c.id = s.category_id
    LEFT JOIN (
        SELECT provider_id, AVG(rating) as avg_rating, COUNT(*) as rev_count
        FROM reviews WHERE status = 'published'
        GROUP BY provider_id
    ) rv ON rv.provider_id = pp.id
    WHERE pp.marketplace_active = 1
    GROUP BY pp.id, pp.business_name, u.name, pp.availability_status, pp.response_time_minutes, pp.area, pp.city, rv.avg_rating, rv.rev_count
    ORDER BY pp.id ASC
");

$matrix = $stmt->fetchAll();

echo "PROVIDER SERVICE MATRIX (Active Marketplace Providers: " . count($matrix) . ")\n";
echo str_repeat("=", 120) . "\n";
printf("%-4s | %-32s | %-24s | %-6s | %-9s | %-6s | %-6s | %-12s\n",
    "ID", "Business Name", "Categories", "Svcs", "Status", "Rating", "Resp", "Area");
echo str_repeat("-", 120) . "\n";

foreach ($matrix as $row) {
    printf("%-4d | %-32s | %-24s | %-6d | %-9s | %-6s | %-6s | %-12s\n",
        (int)$row['id'],
        mb_substr($row['business_name'], 0, 32),
        mb_substr($row['categories'], 0, 24),
        (int)$row['service_count'],
        $row['availability_status'],
        $row['rev_count'] > 0 ? number_format((float)$row['avg_rating'], 1) : 'None',
        $row['response_time_minutes'] . 'm',
        $row['area']
    );
}

echo str_repeat("=", 120) . "\n";
