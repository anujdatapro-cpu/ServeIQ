<?php
declare(strict_types=1);

require __DIR__ . '/../includes/adcs_helpers.php';

function assessmentFixture(int $providerId, string $problem, array $symptoms, string $service, string $urgency, string $severity = 'medium'): array
{
    return [
        'id' => $providerId,
        'provider_id' => $providerId,
        'business_name' => 'Provider ' . $providerId,
        'problem_type' => $problem,
        'affected_entity' => 'Laptop',
        'symptoms' => $symptoms,
        'suggested_service_types' => [$service],
        'urgency' => $urgency,
        'estimated_severity' => $severity,
    ];
}

$full = [
    assessmentFixture(1, 'Cooling issue', ['overheating', 'fan noise'], 'Cooling inspection', 'medium'),
    assessmentFixture(2, 'Cooling issue', ['overheating', 'fan noise'], 'Cooling inspection', 'medium'),
    assessmentFixture(3, 'Cooling issue', ['overheating', 'fan noise'], 'Cooling inspection', 'medium'),
];
$partial = [
    assessmentFixture(1, 'Cooling issue', ['overheating', 'fan noise'], 'Cooling inspection', 'medium'),
    assessmentFixture(2, 'Cooling issue', ['overheating'], 'Fan replacement', 'medium'),
    assessmentFixture(3, 'Cooling issue', ['overheating'], 'Thermal service', 'high'),
];
$outlier = [
    assessmentFixture(1, 'Cooling issue', ['overheating'], 'Cooling inspection', 'medium'),
    assessmentFixture(2, 'Cooling issue', ['overheating'], 'Cooling inspection', 'medium'),
    assessmentFixture(3, 'Motherboard failure', ['no power'], 'Motherboard repair', 'high', 'critical'),
];
$cases = [
    ['name' => 'FULL CONSENSUS', 'assessments' => $full, 'status' => 'consensus', 'minCount' => 3, 'outliers' => 0],
    ['name' => 'PARTIAL CONSENSUS', 'assessments' => $partial, 'status' => 'consensus', 'minCount' => 3, 'outliers' => 0],
    ['name' => 'OUTLIER', 'assessments' => $outlier, 'status' => 'consensus', 'minCount' => 3, 'outliers' => 1],
    ['name' => 'ONE PROVIDER', 'assessments' => [$full[0]], 'status' => 'insufficient_evidence', 'minCount' => 1, 'outliers' => 0],
    ['name' => 'ZERO PROVIDERS', 'assessments' => [], 'status' => 'no_evidence', 'minCount' => 0, 'outliers' => 0],
    ['name' => 'CONFLICT', 'assessments' => [assessmentFixture(1, 'Cooling issue', ['overheating'], 'Cooling inspection', 'medium'), assessmentFixture(2, 'Motherboard failure', ['no power'], 'Motherboard repair', 'high', 'critical')], 'status' => 'disagreement', 'minCount' => 2, 'outliers' => 0],
];

foreach ($cases as $index => $case) {
    $result = buildADCSResult($case['assessments'], $index + 1);
    $outlierCount = (int)$result['outlier_data']['count'];
    $passed = $result['assessment_count'] === $case['minCount'] && $result['consensus_status'] === $case['status'] && $outlierCount === $case['outliers'] && $result['consensus_score'] >= 0 && $result['consensus_score'] <= 100;
    printf("TEST %d %s %s score=%s assessments=%d outliers=%d\n", $index + 1, $case['name'], $passed ? 'PASS' : 'FAIL', $result['consensus_score'], $result['assessment_count'], $outlierCount);
    if (!$passed) exit(1);
}
