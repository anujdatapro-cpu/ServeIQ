<?php
declare(strict_types=1);

function adcsConsensusThreshold(): float
{
    return 0.60;
}

function adcsDisagreementThreshold(): float
{
    return 0.50;
}

function adcsNormalize(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function adcsDecodeList(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $value)));
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? array_values(array_filter(array_map(static fn($item): string => trim((string)$item), $decoded))) : [];
}

function adcsCanonicalValue(string $value, array &$labels): string
{
    $normalized = adcsNormalize($value);
    if ($normalized !== '' && !isset($labels[$normalized])) {
        $labels[$normalized] = trim($value);
    }
    return $normalized;
}

function adcsCategoricalField(array $assessments, string $field): array
{
    $counts = [];
    $labels = [];
    foreach ($assessments as $assessment) {
        $value = trim((string)($assessment[$field] ?? ''));
        if ($value === '') {
            continue;
        }
        $normalized = adcsCanonicalValue($value, $labels);
        $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
    }
    arsort($counts);
    $total = count($assessments);
    $values = [];
    foreach ($counts as $normalized => $count) {
        $values[] = ['value' => $labels[$normalized], 'count' => $count, 'ratio' => $total > 0 ? round($count / $total, 4) : 0.0];
    }
    $top = $values[0] ?? null;
    return [
        'status' => adcsFieldStatus($top['ratio'] ?? 0.0, count($values), $total),
        'consensus_value' => ($top && ($top['ratio'] ?? 0) >= adcsConsensusThreshold()) ? $top['value'] : null,
        'consensus_count' => ($top && ($top['ratio'] ?? 0) >= adcsConsensusThreshold()) ? $top['count'] : 0,
        'ratio' => $top['ratio'] ?? 0.0,
        'values' => $values,
    ];
}

function adcsListField(array $assessments, string $field): array
{
    $counts = [];
    $labels = [];
    foreach ($assessments as $assessment) {
        foreach (adcsDecodeList($assessment[$field] ?? []) as $value) {
            $normalized = adcsCanonicalValue($value, $labels);
            if ($normalized !== '') {
                $counts[$normalized] = ($counts[$normalized] ?? 0) + 1;
            }
        }
    }
    arsort($counts);
    $total = count($assessments);
    $values = [];
    foreach ($counts as $normalized => $count) {
        $values[] = ['value' => $labels[$normalized], 'count' => $count, 'ratio' => $total > 0 ? round($count / $total, 4) : 0.0];
    }
    $consensus = array_values(array_filter($values, static fn(array $value): bool => $value['ratio'] >= adcsConsensusThreshold()));
    return [
        'status' => adcsFieldStatus($values[0]['ratio'] ?? 0.0, count($values), $total),
        'consensus_values' => array_map(static fn(array $value): string => $value['value'], $consensus),
        'values' => $values,
        'ratio' => $values === [] ? 0.0 : (float)$values[0]['ratio'],
    ];
}

function adcsFieldStatus(float $ratio, int $distinctValues, int $assessmentCount): string
{
    if ($assessmentCount === 0) return 'no_evidence';
    if ($assessmentCount === 1) return 'insufficient_evidence';
    if ($distinctValues === 0) return 'no_evidence';
    if ($ratio >= adcsConsensusThreshold()) return 'consensus';
    if ($distinctValues > 1 && $ratio <= adcsDisagreementThreshold()) return 'disagreement';
    return 'partial_consensus';
}

function adcsAssessmentDivergence(array $assessment, array $fields): array
{
    $differences = [];
    foreach (['problem_type', 'affected_entity', 'urgency', 'estimated_severity'] as $field) {
        $consensus = $fields[$field]['consensus_value'] ?? null;
        if ($consensus !== null && adcsNormalize((string)($assessment[$field] ?? '')) !== adcsNormalize((string)$consensus)) {
            $differences[] = $field;
        }
    }
    foreach (['symptoms', 'suggested_service_types'] as $field) {
        $consensusValues = $fields[$field]['consensus_values'] ?? [];
        $assessmentValues = adcsDecodeList($assessment[$field] ?? []);
        if ($consensusValues !== [] && count(array_intersect(array_map('adcsNormalize', $assessmentValues), array_map('adcsNormalize', $consensusValues))) === 0) {
            $differences[] = $field;
        }
    }
    return $differences;
}

function getADCSAssessments(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare(
        'SELECT pa.id, pa.request_id, pa.provider_id, pa.problem_type, pa.affected_entity,
                pa.symptoms, pa.suggested_service_types, pa.urgency, pa.estimated_severity,
                pa.assessment_notes, pa.assessment_confidence, pa.status, pa.created_at, pa.updated_at,
                pp.business_name, u.name AS provider_name
         FROM provider_assessments pa
         INNER JOIN provider_profiles pp ON pp.id = pa.provider_id
         INNER JOIN users u ON u.id = pp.user_id
         WHERE pa.request_id = :request_id AND pa.status IN (\'submitted\', \'updated\')
         ORDER BY pa.provider_id ASC'
    );
    $stmt->execute(['request_id' => $requestId]);
    $assessments = $stmt->fetchAll();
    foreach ($assessments as &$assessment) {
        $assessment['symptoms'] = adcsDecodeList($assessment['symptoms']);
        $assessment['suggested_service_types'] = adcsDecodeList($assessment['suggested_service_types']);
    }
    unset($assessment);
    return $assessments;
}

function buildADCSResult(array $assessments, int $requestId): array
{
    $count = count($assessments);
    $fields = [
        'problem_type' => adcsCategoricalField($assessments, 'problem_type'),
        'affected_entity' => adcsCategoricalField($assessments, 'affected_entity'),
        'symptoms' => adcsListField($assessments, 'symptoms'),
        'suggested_service_types' => adcsListField($assessments, 'suggested_service_types'),
        'urgency' => adcsCategoricalField($assessments, 'urgency'),
        'estimated_severity' => adcsCategoricalField($assessments, 'estimated_severity'),
    ];
    $ratios = array_values(array_filter(array_map(static fn(array $field): float => (float)($field['ratio'] ?? 0), $fields), static fn(float $ratio): bool => $ratio > 0));
    $score = $count > 1 && $ratios !== [] ? round((array_sum($ratios) / count($ratios)) * 100, 2) : 0.0;
    $disagreements = [];
    foreach ($fields as $name => $field) {
        if (in_array($field['status'], ['partial_consensus', 'disagreement'], true)) {
            $disagreements[$name] = $field;
        }
    }
    $status = $count === 0 ? 'no_evidence' : ($count === 1 ? 'insufficient_evidence' : ($score >= adcsConsensusThreshold() * 100 ? 'consensus' : (count($disagreements) >= 2 ? 'disagreement' : 'partial_consensus')));
    $outliers = [];
    if ($count >= 3) {
        foreach ($assessments as $assessment) {
            $differences = adcsAssessmentDivergence($assessment, $fields);
            $divergenceScore = round(count($differences) / 5, 4);
            if ($divergenceScore >= adcsConsensusThreshold()) {
                $outliers[] = ['assessment_id' => (int)$assessment['id'], 'provider_id' => (int)$assessment['provider_id'], 'provider_name' => (string)$assessment['business_name'], 'divergence_score' => $divergenceScore, 'differences' => $differences];
            }
        }
    }
    $result = [
        'request_id' => $requestId, 'assessment_count' => $count, 'consensus_status' => $status,
        'consensus_score' => min(100.0, max(0.0, $score)), 'consensus_data' => $fields,
        'disagreement_data' => ['fields' => $disagreements, 'count' => count($disagreements)],
        'outlier_data' => ['providers' => $outliers, 'count' => count($outliers)],
        'analysis_method' => 'adcs_rule_based_v1', 'version' => 1, 'assessments' => $assessments,
    ];
    return $result;
}

function calculateADCSForRequest(PDO $pdo, int $requestId): array
{
    $assessments = getADCSAssessments($pdo, $requestId);
    $result = buildADCSResult($assessments, $requestId);
    $stmt = $pdo->prepare(
        'INSERT INTO adcs_results (request_id, assessment_count, consensus_data, disagreement_data, outlier_data, consensus_score, consensus_status, analysis_method, version)
         VALUES (:request_id, :assessment_count, :consensus_data, :disagreement_data, :outlier_data, :consensus_score, :consensus_status, :analysis_method, :version)
         ON DUPLICATE KEY UPDATE assessment_count = VALUES(assessment_count), consensus_data = VALUES(consensus_data), disagreement_data = VALUES(disagreement_data), outlier_data = VALUES(outlier_data), consensus_score = VALUES(consensus_score), consensus_status = VALUES(consensus_status), analysis_method = VALUES(analysis_method), version = VALUES(version), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'request_id' => $requestId, 'assessment_count' => $result['assessment_count'], 'consensus_data' => json_encode($result['consensus_data'], JSON_THROW_ON_ERROR),
        'disagreement_data' => json_encode($result['disagreement_data'], JSON_THROW_ON_ERROR), 'outlier_data' => json_encode($result['outlier_data'], JSON_THROW_ON_ERROR),
        'consensus_score' => $result['consensus_score'], 'consensus_status' => $result['consensus_status'], 'analysis_method' => $result['analysis_method'], 'version' => $result['version'],
    ]);
    return $result;
}

/**
 * A ServiceDNA/request change makes prior provider opinions stale. Keep them
 * for audit history, but exclude them from ADCS until each provider resubmits.
 */
function invalidateADCSAssessmentsForRequest(PDO $pdo, int $requestId): void
{
    $stmt = $pdo->prepare(
        "UPDATE provider_assessments SET status = 'withdrawn', matching_result_id = NULL
         WHERE request_id = :request_id AND status IN ('submitted', 'updated')"
    );
    $stmt->execute(['request_id' => $requestId]);
}

function getADCSForRequest(PDO $pdo, int $requestId, ?int $customerId = null): ?array
{
    $sql = 'SELECT ar.* FROM adcs_results ar INNER JOIN service_requests r ON r.id = ar.request_id WHERE ar.request_id = :request_id';
    $params = ['request_id' => $requestId];
    if ($customerId !== null) {
        $sql .= ' AND r.customer_id = :customer_id';
        $params['customer_id'] = $customerId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    if (!$result) return null;
    foreach (['consensus_data', 'disagreement_data', 'outlier_data'] as $field) {
        $result[$field] = json_decode((string)$result[$field], true) ?: [];
    }
    $result['assessments'] = getADCSAssessments($pdo, $requestId);
    return $result;
}

function providerIsEligibleForADCS(PDO $pdo, int $requestId, int $providerId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT mr.id FROM matching_results mr
         INNER JOIN provider_profiles pp ON pp.id = mr.provider_id
         INNER JOIN service_requests r ON r.id = mr.request_id
         WHERE mr.request_id = :request_id AND mr.provider_id = :provider_id
           AND pp.verification_status = \'approved\' AND pp.availability_status <> \'offline\'
           AND r.status NOT IN (\'cancelled\', \'completed\')
           AND mr.matching_method = \'weighted_rule_based_v1\' AND mr.version = 1
         LIMIT 1'
    );
    $stmt->execute(['request_id' => $requestId, 'provider_id' => $providerId]);
    $matchingResult = $stmt->fetch();
    return $matchingResult ? (int)$matchingResult['id'] : null;
}
