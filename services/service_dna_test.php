<?php
declare(strict_types=1);

require __DIR__ . '/service_dna.php';

$categories = [
    ['id' => 1, 'category_name' => 'Laptop & Computer Repair'],
    ['id' => 2, 'category_name' => 'Mobile Repair'],
    ['id' => 3, 'category_name' => 'AC Repair'],
    ['id' => 4, 'category_name' => 'Plumbing'],
    ['id' => 7, 'category_name' => 'Appliance Repair'],
];

$testCases = [
    [
        'input' => 'My laptop is overheating while gaming and the fan is making noise.',
        'expected_category' => 'Laptop & Computer Repair',
        'expected_problem' => 'Overheating',
        'expected_entity' => 'Laptop',
        'expected_symptoms' => ['overheating', 'loud fan'],
        'min_confidence' => 60,
    ],
    [
        'input' => 'My AC is not cooling and water is leaking.',
        'expected_category' => 'AC Repair',
        'expected_problem' => 'Not cooling',
        'expected_entity' => 'AC',
        'expected_symptoms' => ['not cooling', 'water leakage'],
        'min_confidence' => 60,
    ],
    [
        'input' => 'My device has some strange problem.',
        'expected_category' => null,
        'expected_problem' => null,
        'expected_entity' => null,
        'expected_symptoms' => [],
        'max_confidence' => 40,
    ],
];

$allPassed = true;
foreach ($testCases as $idx => $tc) {
    $dna = analyzeServiceDnaFromCategories($tc['input'], null, 'medium', $categories);
    $categoryOk = $dna['category_name'] === $tc['expected_category'];
    $problemOk = $dna['problem_type'] === $tc['expected_problem'];
    $entityOk = $dna['affected_entity'] === $tc['expected_entity'];
    $confidenceOk = isset($tc['min_confidence']) 
        ? ($dna['confidence_score'] >= $tc['min_confidence'])
        : ($dna['confidence_score'] <= $tc['max_confidence']);

    $passed = $categoryOk && $problemOk && $entityOk && $confidenceOk;
    if (!$passed) {
        $allPassed = false;
    }
    printf("SERVICEDNA TEST %d: %s (Category: %s, Problem: %s, Confidence: %d%%)\n",
        $idx + 1,
        $passed ? 'PASS' : 'FAIL',
        $dna['category_name'] ?? 'None',
        $dna['problem_type'] ?? 'None',
        $dna['confidence_score']
    );
}

if (!$allPassed) {
    exit(1);
}
echo "SERVICEDNA TESTS: ALL PASSED\n";
exit(0);
