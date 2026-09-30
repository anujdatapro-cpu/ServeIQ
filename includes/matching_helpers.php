<?php
declare(strict_types=1);

function matchingWeights(): array
{
    return [
        'category' => 25.0,
        'service_type' => 20.0,
        'problem_symptoms' => 20.0,
        'keyword_skill' => 15.0,
        'location' => 10.0,
        'provider_quality' => 10.0,
    ];
}

function matchingThreshold(): float
{
    return 40.0;
}

function matchingNormalize(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function matchingTokens(string $value): array
{
    $stopWords = ['a', 'an', 'and', 'for', 'from', 'has', 'is', 'of', 'the', 'to', 'with'];
    $tokens = preg_split('/\s+/u', matchingNormalize($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter(array_unique($tokens), static fn(string $token): bool => mb_strlen($token) > 2 && !in_array($token, $stopWords, true)));
}

function matchingDecodeJson(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function matchingPhraseRatio(array $phrases, string $haystack): float
{
    $phrases = array_values(array_filter(array_map(static fn($phrase): string => trim((string)$phrase), $phrases)));
    if ($phrases === []) {
        return 0.0;
    }
    $normalizedHaystack = matchingNormalize($haystack);
    $matched = 0;
    foreach ($phrases as $phrase) {
        $normalizedPhrase = matchingNormalize($phrase);
        if ($normalizedPhrase !== '' && (str_contains($normalizedHaystack, $normalizedPhrase) || count(array_intersect(matchingTokens($normalizedPhrase), matchingTokens($normalizedHaystack))) > 0)) {
            $matched++;
        }
    }
    return $matched / count($phrases);
}

function matchingTokenRatio(array $keywords, string $haystack): float
{
    $expected = [];
    foreach ($keywords as $keyword) {
        $expected = array_merge($expected, matchingTokens((string)$keyword));
    }
    $expected = array_values(array_unique($expected));
    if ($expected === []) {
        return 0.0;
    }
    $available = matchingTokens($haystack);
    return count(array_intersect($expected, $available)) / count($expected);
}

function getMatchingRequest(PDO $pdo, int $requestId, ?int $customerId = null): ?array
{
    $sql = 'SELECT r.id, r.customer_id, r.category_id, r.title, r.description, r.city, r.area, r.urgency,
                   f.detected_category_id, f.problem_type, f.affected_entity, f.symptoms, f.context,
                   f.keywords, f.possible_service_types, f.location_context, f.confidence_score,
                   f.user_urgency, f.detected_urgency, f.analysis_method, f.version
            FROM service_requests r
            LEFT JOIN problem_fingerprints f ON f.request_id = r.id
            WHERE r.id = :request_id';
    $params = ['request_id' => $requestId];
    if ($customerId !== null) {
        $sql .= ' AND r.customer_id = :customer_id';
        $params['customer_id'] = $customerId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $request = $stmt->fetch();
    return $request ?: null;
}

function matchingRequestAttributes(array $request): array
{
    $keywords = matchingDecodeJson($request['keywords'] ?? []);
    if ($keywords === []) {
        $keywords = matchingTokens((string)($request['title'] ?? '') . ' ' . (string)($request['description'] ?? ''));
    }
    return [
        'category_id' => $request['detected_category_id'] ?? $request['category_id'] ?? null,
        'problem_type' => (string)($request['problem_type'] ?? ''),
        'affected_entity' => (string)($request['affected_entity'] ?? ''),
        'symptoms' => matchingDecodeJson($request['symptoms'] ?? []),
        'context' => matchingDecodeJson($request['context'] ?? []),
        'keywords' => $keywords,
        'possible_service_types' => matchingDecodeJson($request['possible_service_types'] ?? []),
        'city' => (string)($request['city'] ?? ''),
        'area' => (string)($request['area'] ?? ''),
        'confidence_score' => (int)($request['confidence_score'] ?? 0),
    ];
}

function getProviderCandidates(PDO $pdo, int $requestId, ?int $customerId = null): array
{
    $request = getMatchingRequest($pdo, $requestId, $customerId);
    if (!$request) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT pp.id AS provider_id, pp.business_name, pp.phone, pp.address, pp.city, pp.area,
                pp.description AS provider_description, pp.experience_years, pp.availability_status,
                pp.verification_status, u.name AS provider_name, s.id AS service_id,
                s.category_id, s.service_name, s.description AS service_description,
                COALESCE(review_stats.average_rating, 0) AS average_rating,
                COALESCE(review_stats.review_count, 0) AS review_count,
                COALESCE(job_stats.completed_jobs, 0) AS completed_jobs
         FROM provider_profiles pp
         INNER JOIN users u ON u.id = pp.user_id AND u.role = \'provider\'
         INNER JOIN services s ON s.provider_id = pp.id AND s.is_active = 1
         LEFT JOIN (
             SELECT provider_id, AVG(rating) AS average_rating, COUNT(*) AS review_count
             FROM reviews GROUP BY provider_id
         ) review_stats ON review_stats.provider_id = pp.id
         LEFT JOIN (
             SELECT provider_id, COUNT(*) AS completed_jobs
             FROM bookings WHERE status = \'completed\' GROUP BY provider_id
         ) job_stats ON job_stats.provider_id = pp.id
         WHERE pp.verification_status = \'approved\'
           AND pp.availability_status <> \'offline\'
         ORDER BY pp.id ASC, s.id ASC'
    );
    $stmt->execute();
    $providers = [];
    foreach ($stmt->fetchAll() as $row) {
        $providerId = (int)$row['provider_id'];
        if (!isset($providers[$providerId])) {
            $providers[$providerId] = [
                'provider_id' => $providerId,
                'provider_name' => (string)$row['provider_name'],
                'business_name' => (string)$row['business_name'],
                'phone' => (string)$row['phone'],
                'address' => (string)$row['address'],
                'city' => (string)$row['city'],
                'area' => (string)($row['area'] ?? ''),
                'provider_description' => (string)($row['provider_description'] ?? ''),
                'experience_years' => (int)$row['experience_years'],
                'availability_status' => (string)$row['availability_status'],
                'verification_status' => (string)$row['verification_status'],
                'average_rating' => (float)$row['average_rating'],
                'review_count' => (int)$row['review_count'],
                'completed_jobs' => (int)$row['completed_jobs'],
                'services' => [],
            ];
        }
        $providers[$providerId]['services'][] = [
            'service_id' => (int)$row['service_id'],
            'category_id' => (int)$row['category_id'],
            'service_name' => (string)$row['service_name'],
            'description' => (string)($row['service_description'] ?? ''),
        ];
    }
    return array_values($providers);
}

function calculateProviderMatchScore(array $request, array $provider): array
{
    $weights = matchingWeights();
    $attributes = matchingRequestAttributes($request);
    $services = $provider['services'] ?? [];
    $serviceCategories = array_map(static fn(array $service): int => (int)$service['category_id'], $services);
    $serviceCorpus = (string)($provider['provider_description'] ?? '');
    foreach ($services as $service) {
        $serviceCorpus .= ' ' . (string)$service['service_name'] . ' ' . (string)$service['description'];
    }

    $categoryScore = $attributes['category_id'] !== null && in_array((int)$attributes['category_id'], $serviceCategories, true) ? $weights['category'] : 0.0;
    $serviceTypeRatio = matchingPhraseRatio($attributes['possible_service_types'], $serviceCorpus);
    $serviceTypeScore = $serviceTypeRatio * $weights['service_type'];
    $technicalPhrases = array_merge([$attributes['problem_type'], $attributes['affected_entity']], $attributes['symptoms'], $attributes['context']);
    $technicalRatio = matchingPhraseRatio($technicalPhrases, $serviceCorpus);
    $problemSymptomsScore = $technicalRatio * $weights['problem_symptoms'];
    $keywordSkillScore = matchingTokenRatio($attributes['keywords'], $serviceCorpus) * $weights['keyword_skill'];

    $locationScore = 0.0;
    $locationReasons = [];
    if ($attributes['city'] !== '' && matchingNormalize($attributes['city']) === matchingNormalize((string)$provider['city'])) {
        $locationScore += 7.0;
        $locationReasons[] = 'City matches';
    }
    if ($attributes['area'] !== '' && matchingNormalize($attributes['area']) === matchingNormalize((string)$provider['area'])) {
        $locationScore += 3.0;
        $locationReasons[] = 'Area matches';
    }

    $qualityScore = 4.0;
    $qualityReasons = ['Provider is verified'];
    if (($provider['availability_status'] ?? '') === 'available') {
        $qualityScore += 2.0;
        $qualityReasons[] = 'Provider is available';
    } elseif (($provider['availability_status'] ?? '') === 'busy') {
        $qualityScore += 1.0;
        $qualityReasons[] = 'Provider is currently busy';
    }
    if ((int)($provider['review_count'] ?? 0) > 0) {
        $reviewWeight = (int)$provider['review_count'] >= 5 ? 2.0 : 1.0;
        $qualityScore += min($reviewWeight, ((float)$provider['average_rating'] / 5) * $reviewWeight);
        $qualityReasons[] = 'Rating evidence available';
    }
    if ((int)($provider['completed_jobs'] ?? 0) > 0) {
        $qualityScore += min(2.0, ((int)$provider['completed_jobs'] / 25) * 2.0);
        $qualityReasons[] = 'Completed-job history available';
    }
    $qualityScore = min($weights['provider_quality'], $qualityScore);

    $breakdown = [
        'category' => round($categoryScore, 2), 'service_type' => round($serviceTypeScore, 2),
        'problem_symptoms' => round($problemSymptomsScore, 2), 'keyword_skill' => round($keywordSkillScore, 2),
        'location' => round($locationScore, 2), 'provider_quality' => round($qualityScore, 2),
    ];
    $breakdown['total'] = round(array_sum($breakdown), 2);
    $technicalScore = round($serviceTypeScore + $problemSymptomsScore + $keywordSkillScore, 2);
    $reasons = [];
    if ($categoryScore > 0) $reasons[] = 'Category matches';
    if ($serviceTypeScore > 0) $reasons[] = 'Relevant service type overlap';
    if ($problemSymptomsScore > 0) $reasons[] = 'Problem or symptom skills overlap';
    if ($keywordSkillScore > 0) $reasons[] = 'Keywords overlap provider services';
    $reasons = array_merge($reasons, $locationReasons, $qualityReasons);
    if ($locationScore === 0.0) $reasons[] = 'Location match unavailable';
    if ($technicalScore === 0.0) $reasons[] = 'Limited technical overlap';

    return [
        'score' => $breakdown['total'], 'breakdown' => $breakdown, 'technical_score' => $technicalScore,
        'quality_score' => $breakdown['provider_quality'], 'reasons' => array_values(array_unique($reasons)),
        'matching_method' => 'weighted_rule_based_v1', 'version' => 1,
    ];
}

function getProviderMatchBreakdown(PDO $pdo, int $requestId, int $providerId, ?int $customerId = null): ?array
{
    $request = getMatchingRequest($pdo, $requestId, $customerId);
    if (!$request) return null;
    foreach (getProviderCandidates($pdo, $requestId, $customerId) as $provider) {
        if ((int)$provider['provider_id'] === $providerId) {
            return array_merge($provider, calculateProviderMatchScore($request, $provider));
        }
    }
    return null;
}

function getRankedProvidersForRequest(PDO $pdo, int $requestId, ?int $customerId = null): array
{
    $request = getMatchingRequest($pdo, $requestId, $customerId);
    if (!$request) return [];
    $ranked = [];
    foreach (getProviderCandidates($pdo, $requestId, $customerId) as $provider) {
        $result = array_merge($provider, calculateProviderMatchScore($request, $provider));
        if ($result['score'] >= matchingThreshold()) {
            $ranked[] = $result;
        }
    }
    usort($ranked, static function (array $left, array $right): int {
        return [$right['score'], $right['technical_score'], $right['quality_score'], $left['provider_id']]
            <=> [$left['score'], $left['technical_score'], $left['quality_score'], $right['provider_id']];
    });
    foreach ($ranked as $index => &$provider) {
        $provider['ranking_position'] = $index + 1;
    }
    unset($provider);
    return $ranked;
}

function persistMatchingResults(PDO $pdo, int $requestId, array $rankedProviders): void
{
    $deleteStmt = $pdo->prepare('DELETE FROM matching_results WHERE request_id = :request_id AND matching_method = :matching_method AND version = :version');
    $deleteStmt->execute(['request_id' => $requestId, 'matching_method' => 'weighted_rule_based_v1', 'version' => 1]);
    if ($rankedProviders === []) return;
    $stmt = $pdo->prepare(
        'INSERT INTO matching_results
            (request_id, provider_id, match_score, score_breakdown, match_reasons, ranking_position, matching_method, version)
         VALUES (:request_id, :provider_id, :match_score, :score_breakdown, :match_reasons, :ranking_position, :matching_method, :version)
         ON DUPLICATE KEY UPDATE match_score = VALUES(match_score), score_breakdown = VALUES(score_breakdown), match_reasons = VALUES(match_reasons), ranking_position = VALUES(ranking_position), updated_at = CURRENT_TIMESTAMP'
    );
    foreach ($rankedProviders as $provider) {
        $stmt->execute([
            'request_id' => $requestId, 'provider_id' => (int)$provider['provider_id'], 'match_score' => $provider['score'],
            'score_breakdown' => json_encode($provider['breakdown'], JSON_THROW_ON_ERROR), 'match_reasons' => json_encode($provider['reasons'], JSON_THROW_ON_ERROR),
            'ranking_position' => (int)$provider['ranking_position'], 'matching_method' => $provider['matching_method'], 'version' => $provider['version'],
        ]);
    }
}

/** Rebuild persisted matches only as part of a write lifecycle. */
function refreshMatchingResultsForRequest(PDO $pdo, int $requestId): array
{
    $rankedProviders = getRankedProvidersForRequest($pdo, $requestId);
    persistMatchingResults($pdo, $requestId, $rankedProviders);
    return $rankedProviders;
}
