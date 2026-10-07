<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/marketplace_filters.php';
require __DIR__ . '/../includes/request_helpers.php';

$pdo = getDatabaseConnection();
$categories = $pdo->query('SELECT id,category_name FROM service_categories WHERE is_active=1 ORDER BY id')->fetchAll();
$categoryIds = array_column($categories, 'id', 'category_name');
$customerStmt = $pdo->prepare("SELECT id FROM users WHERE email='demo.customer01@serveiq.local' AND role='customer' LIMIT 1");
$customerStmt->execute();
$customerId = (int)$customerStmt->fetchColumn();
if ($customerId < 1) throw new RuntimeException('Run the demo seed before running marketplace verification.');

$scenarios = [
    ['Laptop overheating and fan noise', 'My laptop is overheating while gaming and the fan is making a loud noise.', 'Laptop & Computer Repair', ['laptop','computer','overheating','fan','cooling','thermal','hardware','diagnosis']],
    ['Phone display broken', 'My phone display is broken after I dropped it.', 'Mobile Repair', ['phone','mobile','display','screen','broken','repair']],
    ['Car tyre replacement', 'I need a mechanic to replace the flat tyre on my car.', 'Tyre/Puncture Services', ['car','tyre','tire','puncture','flat','mechanic']],
    ['AC not cooling', 'My AC is running but it is not cooling the room.', 'AC Repair', ['ac','air conditioning','cooling','airflow','repair']],
    ['Plumbing water leakage', 'Water is leaking from the pipe under my kitchen sink.', 'Plumbing', ['plumbing','pipe','leak','water','kitchen','tap','repair']],
    ['Washing machine not starting', 'My washing machine is not starting and needs repair.', 'Washing Machine Repair', ['washing machine','washer','start','repair','drainage']],
    ['TV not working', 'My TV is not working and the screen stays black.', 'TV Repair', ['tv','television','screen','display','power','repair']],
    ['Electrical fault', 'There is an electrical fault and power keeps cutting out.', 'Electrical Repair', ['electrical','electrician','wiring','switch','socket','power','fault']],
    ['WiFi router issue', 'My WiFi router is not working and the internet keeps dropping.', 'Internet & WiFi Services', ['wifi','internet','router','network','connection']],
    ['Refrigerator not cooling', 'My refrigerator is not cooling properly.', 'Refrigerator Repair', ['refrigerator','fridge','cooling','compressor','repair']],
    ['CCTV installation', 'I need CCTV camera installation for my house.', 'CCTV & Security Installation', ['cctv','security','camera','installation','wiring']],
    ['RO purifier issue', 'My RO water purifier is leaking and needs service.', 'RO/Water Purifier Service', ['ro','water purifier','filter','water','leak','service']],
];

$insert = $pdo->prepare("INSERT INTO service_requests (customer_id,title,description,city,area,latitude,longitude,urgency,contact_preference,status) VALUES (:customer_id,:title,:description,'Pune','Kothrud',18.5074,73.8077,'medium','email','submitted')");
$testResults = [];
$pdo->beginTransaction();
try {
    foreach ($scenarios as [$label, $description, $expectedCategory, $relevanceTerms]) {
        $insert->execute(['customer_id' => $customerId, 'title' => $label, 'description' => $description]);
        $requestId = (int)$pdo->lastInsertId();
        $dna = analyzeAndStoreServiceDna($pdo, $requestId);
        $candidates = getProviderCandidates($pdo, $requestId, $customerId);
        $ranked = getRankedProvidersForRequest($pdo, $requestId, $customerId);
        $relevant = array_values(array_filter($ranked, static function (array $provider) use ($relevanceTerms): bool {
            $corpus = (string)$provider['provider_description'];
            foreach ($provider['services'] as $service) $corpus .= ' ' . $service['service_name'] . ' ' . $service['description'];
            return matchingTokenRatio($relevanceTerms, $corpus) >= 0.30;
        }));
        $testResults[] = [
            'label' => $label, 'category' => (string)($dna['category_name'] ?? 'Unclassified'),
            'expected' => $expectedCategory, 'candidates' => count($candidates), 'matches' => count($ranked),
            'relevant' => count($relevant), 'top' => $ranked[0]['score'] ?? null,
            'lowest_relevant' => $relevant === [] ? null : end($relevant)['score'],
            'top_provider' => $ranked[0]['business_name'] ?? '-', 'breakdown' => $ranked[0]['breakdown'] ?? [],
            'passed' => ($dna['category_name'] ?? null) === $expectedCategory && count($relevant) >= 7,
            'request_id' => $requestId, 'ranked' => $ranked,
        ];
    }

    $laptop = $testResults[0];
    $laptopRequest = getMatchingRequest($pdo, (int)$laptop['request_id'], $customerId) ?? [];
    $ranked = $laptop['ranked'];
    $baseFilters = marketplaceParseFilters([], $categories, $laptopRequest);
    $base = marketplaceFilterAndSortProviders($ranked, $baseFilters, $laptopRequest);
    $priceSorted = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['sort' => 'price_low'], $categories, $laptopRequest), $laptopRequest);
    $priceDescending = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['sort' => 'price_high'], $categories, $laptopRequest), $laptopRequest);
    $nearestSorted = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['sort' => 'nearest'], $categories, $laptopRequest), $laptopRequest);
    $highestRated = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['sort' => 'highest_rated'], $categories, $laptopRequest), $laptopRequest);
    $fastestSorted = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['sort' => 'fastest'], $categories, $laptopRequest), $laptopRequest);
    $ratingFiltered = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['rating' => '4'], $categories, $laptopRequest), $laptopRequest);
    $rating45 = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['rating' => '4.5'], $categories, $laptopRequest), $laptopRequest);
    $priceRange = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['min_price' => '300', 'max_price' => '1000'], $categories, $laptopRequest), $laptopRequest);
    $availableFiltered = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['available' => '1'], $categories, $laptopRequest), $laptopRequest);
    $distanceFiltered = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['distance' => '5'], $categories, $laptopRequest), $laptopRequest);
    $categoryFiltered = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['category' => (string)$categoryIds['Laptop & Computer Repair']], $categories, $laptopRequest), $laptopRequest);
    $combinedFilters = marketplaceParseFilters(['min_price' => '300', 'max_price' => '1500', 'rating' => '4', 'available' => '1', 'distance' => '10', 'category' => (string)$categoryIds['Laptop & Computer Repair']], $categories, $laptopRequest);
    $combined = marketplaceFilterAndSortProviders($ranked, $combinedFilters, $laptopRequest);
    $noResultFilters = marketplaceParseFilters(['min_price' => '99999', 'max_price' => '100000', 'rating' => '4.8', 'available' => '1', 'distance' => '2'], $categories, $laptopRequest);
    $noResults = marketplaceFilterAndSortProviders($ranked, $noResultFilters, $laptopRequest);
    $invalidSort = marketplaceParseFilters(['sort' => 'price_low; DROP TABLE users'], $categories, $laptopRequest);
    $injectionSearch = marketplaceFilterAndSortProviders($ranked, marketplaceParseFilters(['q' => "' OR 1=1 --"], $categories, $laptopRequest), $laptopRequest);
    $pageOne = array_slice($base, 0, 10);
    $pageTwo = array_slice($base, 10, 10);
    $wrongOwnerVisible = findCustomerRequest($pdo, (int)$laptop['request_id'], $customerId + 1) !== null;

    $bestMatchValid = true;
    for ($i = 1; $i < count($base); $i++) if ($base[$i - 1]['score'] < $base[$i]['score']) $bestMatchValid = false;
    $priceAscending = true;
    for ($i = 1; $i < count($priceSorted); $i++) if ($priceSorted[$i - 1]['starting_price'] > $priceSorted[$i]['starting_price']) $priceAscending = false;
    $priceDescendingValid = true;
    for ($i = 1; $i < count($priceDescending); $i++) if ($priceDescending[$i - 1]['starting_price'] < $priceDescending[$i]['starting_price']) $priceDescendingValid = false;
    $nearestValid = true;
    for ($i = 1; $i < count($nearestSorted); $i++) if ($nearestSorted[$i - 1]['distance_km'] > $nearestSorted[$i]['distance_km']) $nearestValid = false;
    $highestRatedValid = true;
    for ($i = 1; $i < count($highestRated); $i++) if ($highestRated[$i - 1]['average_rating'] < $highestRated[$i]['average_rating']) $highestRatedValid = false;
    $fastestValid = true;
    for ($i = 1; $i < count($fastestSorted); $i++) if ($fastestSorted[$i - 1]['response_time_minutes'] > $fastestSorted[$i]['response_time_minutes']) $fastestValid = false;
    $ratingValid = count(array_filter($ratingFiltered, static fn(array $p): bool => $p['average_rating'] < 4.0)) === 0;
    $availabilityValid = count(array_filter($availableFiltered, static fn(array $p): bool => $p['availability_status'] !== 'available')) === 0;
    $distanceValid = count(array_filter($distanceFiltered, static fn(array $p): bool => $p['distance_km'] === null || $p['distance_km'] > 5)) === 0;
    $filterResults = [
        'base' => count($base), 'price_ascending' => count($priceSorted), 'price_ascending_order_valid' => $priceAscending,
        'price_descending' => count($priceDescending), 'price_descending_order_valid' => $priceDescendingValid,
        'nearest_sort' => count($nearestSorted), 'nearest_order_valid' => $nearestValid,
        'highest_rated_sort' => count($highestRated), 'highest_rated_order_valid' => $highestRatedValid,
        'fastest_response_sort' => count($fastestSorted), 'fastest_order_valid' => $fastestValid,
        'price_300_to_1000' => count($priceRange),
        'rating_4_plus' => count($ratingFiltered), 'rating_valid' => $ratingValid,
        'rating_4_5_plus' => count($rating45),
        'available_now' => count($availableFiltered), 'availability_valid' => $availabilityValid,
        'within_5_km' => count($distanceFiltered), 'distance_valid' => $distanceValid,
        'service_category' => count($categoryFiltered), 'combined' => count($combined), 'no_result_combination' => count($noResults),
        'clear_filters_returns_base' => count($base) === count($ranked),
        'pagination_page_1' => count($pageOne), 'pagination_page_2' => count($pageTwo),
        'pagination_distinct_records' => count(array_unique(array_merge(array_column($pageOne, 'provider_id'), array_column($pageTwo, 'provider_id')))) === count($pageOne) + count($pageTwo),
        'injection_like_search_results' => count($injectionSearch),
        'invalid_sort_defaulted' => $invalidSort['sort'] === 'best_match',
        'unauthorized_request_hidden' => !$wrongOwnerVisible,
        'pagination_page_size' => 10,
    ];
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo "DEMO MATCHING TESTS (transactional fixtures; no matching_results persisted)\n";
foreach ($testResults as $row) {
    printf("%s | detected=%s | candidates=%d | strong=%d | relevant=%d | top=%s | lowest relevant=%s | top provider=%s | %s\n",
        $row['label'], $row['category'], $row['candidates'], $row['matches'], $row['relevant'],
        $row['top'] === null ? '-' : number_format((float)$row['top'], 2),
        $row['lowest_relevant'] === null ? '-' : number_format((float)$row['lowest_relevant'], 2),
        $row['top_provider'] . ' breakdown=' . json_encode($row['breakdown']),
        $row['passed'] ? 'PASS' : 'FAIL');
    if (!$row['passed']) printf("  expected=%s top_provider=%s breakdown=%s\n", $row['expected'], $row['top_provider'], json_encode($row['breakdown'], JSON_UNESCAPED_UNICODE));
}
echo "\nDEMO FILTER TESTS\n";
foreach ($filterResults as $label => $value) printf("%s: %s\n", $label, is_bool($value) ? ($value ? 'PASS' : 'FAIL') : $value);
$allPassed = count(array_filter($testResults, static fn(array $row): bool => !$row['passed'])) === 0
    && $priceAscending && $priceDescendingValid && $nearestValid && $highestRatedValid && $fastestValid
    && $bestMatchValid && $ratingValid && $availabilityValid && $distanceValid
    && $invalidSort['sort'] === 'best_match' && !$wrongOwnerVisible && $noResults === []
    && count($pageOne) === min(10, count($base))
    && count($pageTwo) === max(0, count($base) - 10)
    && $filterResults['pagination_distinct_records']
    && $filterResults['clear_filters_returns_base']
    && $injectionSearch === [];
echo $allPassed ? "\nALL MARKETPLACE DATA TESTS PASSED\n" : "\nMARKETPLACE DATA TESTS HAVE FAILURES\n";
exit($allPassed ? 0 : 1);
