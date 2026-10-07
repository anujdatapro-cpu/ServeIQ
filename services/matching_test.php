<?php
declare(strict_types=1);

require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../includes/matching_helpers.php';

$categories = [
    ['id' => 1, 'category_name' => 'Laptop & Computer Repair'],
    ['id' => 2, 'category_name' => 'Mobile Repair'],
    ['id' => 3, 'category_name' => 'AC Repair'],
    ['id' => 4, 'category_name' => 'Plumbing'],
    ['id' => 7, 'category_name' => 'Appliance Repair'],
    ['id' => 10, 'category_name' => 'Washing Machine Repair'],
];

$scenarios = [
    ['text' => 'My laptop is overheating while gaming and the fan is making noise.', 'category' => 1, 'service' => 'Laptop Cooling and Fan Inspection', 'city' => 'Pune', 'expected' => 'Laptop & Computer Repair'],
    ['text' => 'My AC is not cooling and water is leaking.', 'category' => 3, 'service' => 'AC Cooling and Leakage Repair', 'city' => 'Pune', 'expected' => 'AC Repair'],
    ['text' => 'My washing machine is making loud noise and leaking water.', 'category' => 10, 'service' => 'Washing Machine Leakage and Noise Repair', 'city' => 'Pune', 'expected' => 'Washing Machine Repair'],
    ['text' => 'My phone screen is cracked.', 'category' => 2, 'service' => 'Phone Screen Replacement', 'city' => 'Pune', 'expected' => 'Mobile Repair'],
    ['text' => 'My tap is leaking continuously.', 'category' => 4, 'service' => 'Tap Leakage Repair', 'city' => 'Pune', 'expected' => 'Plumbing'],
    ['text' => 'My device has some strange problem.', 'category' => null, 'service' => 'General Services', 'city' => 'Pune', 'expected' => null],
];

foreach ($scenarios as $index => $scenario) {
    $dna = analyzeServiceDnaFromCategories($scenario['text'], null, 'medium', $categories, $scenario['city']);
    $request = array_merge($dna, [
        'category_id' => $scenario['category'],
        'title' => 'Test request',
        'description' => $scenario['text'],
        'city' => $scenario['city'],
        'area' => '',
    ]);
    $provider = [
        'provider_id' => $index + 1,
        'business_name' => 'Scenario Provider',
        'city' => $scenario['city'],
        'area' => '',
        'provider_description' => $scenario['service'],
        'availability_status' => 'available',
        'verification_status' => 'approved',
        'average_rating' => 4.5,
        'review_count' => 10,
        'completed_jobs' => 20,
        'services' => [[
            'category_id' => $scenario['category'] ?? 999,
            'service_name' => $scenario['service'],
            'description' => $scenario['text'],
        ]],
    ];
    $score = calculateProviderMatchScore($request, $provider);
    $passed = $scenario['expected'] === null
        ? $dna['confidence_score'] < 40 && $score['score'] < matchingThreshold()
        : $dna['category_name'] === $scenario['expected'] && $score['score'] >= matchingThreshold();
    printf("TEST %d %s score=%s category=%s\n", $index + 1, $passed ? 'PASS' : 'FAIL', $score['score'], $dna['category_name'] ?? 'Unclassified');
    if (!$passed) {
        exit(1);
    }
}
