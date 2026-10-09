<?php
declare(strict_types=1);

require_once __DIR__ . '/matching_helpers.php';

function marketplaceDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0088;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $earthRadiusKm * asin(min(1, sqrt($a)));
}

function marketplaceParseFilters(array $input, array $categories, array $request): array
{
    $allowedSorts = ['best_match', 'nearest', 'price_low', 'price_high', 'highest_rated', 'fastest'];
    $sort = is_string($input['sort'] ?? null) && in_array($input['sort'], $allowedSorts, true) ? $input['sort'] : 'best_match';
    $categoryIds = array_map(static fn(array $category): int => (int)$category['id'], $categories);
    $categoryId = filter_var($input['category'] ?? null, FILTER_VALIDATE_INT);
    if ($categoryId === false || $categoryId === null || !in_array($categoryId, $categoryIds, true)) {
        $categoryId = null;
    }
    $number = static function (mixed $value, float $min, float $max): ?float {
        if (!is_scalar($value) || $value === '' || !is_numeric((string)$value)) return null;
        $parsed = (float)$value;
        return is_finite($parsed) && $parsed >= $min && $parsed <= $max ? $parsed : null;
    };
    $rating = $number($input['rating'] ?? null, 1, 5);
    if ($rating !== null && !in_array($rating, [4.0, 4.5, 4.8], true)) $rating = null;
    $radius = $number($input['distance'] ?? null, 1, 10);
    if ($radius !== null && !in_array($radius, [2.0, 5.0, 10.0], true)) $radius = null;
    $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
    $search = is_string($input['q'] ?? null) ? trim(mb_substr($input['q'], 0, 80, 'UTF-8')) : '';
    $hasCoordinates = isset($request['latitude'], $request['longitude'])
        && is_numeric($request['latitude']) && is_numeric($request['longitude']);
    return [
        'sort' => $sort,
        'min_price' => $number($input['min_price'] ?? null, 0, 100000),
        'max_price' => $number($input['max_price'] ?? null, 0, 100000),
        'rating' => $rating,
        'available' => isset($input['available']) && $input['available'] === '1',
        'distance' => $hasCoordinates ? $radius : null,
        'distance_unavailable' => !$hasCoordinates && ($radius !== null || $sort === 'nearest'),
        'category' => $categoryId,
        'q' => $search,
        'page' => $page === false ? 1 : (int)$page,
        'has_coordinates' => $hasCoordinates,
    ];
}

function marketplaceFilterAndSortProviders(array $providers, array $filters, array $request): array
{
    $requestLat = $filters['has_coordinates'] ? (float)$request['latitude'] : null;
    $requestLon = $filters['has_coordinates'] ? (float)$request['longitude'] : null;
    $selected = [];
    foreach ($providers as $provider) {
        $eligibleServices = array_values(array_filter($provider['services'], static fn(array $service): bool =>
            $filters['category'] === null || (int)$service['category_id'] === $filters['category']
        ));
        // If a direct search query q is provided and matches the provider's business or provider name,
        // include active services for that provider even if category filter differs
        if ($eligibleServices === [] && $filters['q'] !== '') {
            $normalizedNameCorpus = matchingNormalize($provider['business_name'] . ' ' . $provider['provider_name']);
            $normalizedQ = matchingNormalize($filters['q']);
            $qTokens = matchingTokens($filters['q']);
            if (str_contains($normalizedNameCorpus, $normalizedQ) || (!empty($qTokens) && count(array_intersect($qTokens, matchingTokens($normalizedNameCorpus))) === count($qTokens))) {
                $eligibleServices = $provider['services'];
            }
        }
        if ($eligibleServices === []) continue;
        $prices = array_values(array_filter(array_map(static fn(array $service): ?float => $service['base_price'], $eligibleServices), static fn(?float $price): bool => $price !== null));
        $provider['starting_price'] = $prices === [] ? null : min($prices);
        $provider['services'] = $eligibleServices;
        $provider['distance_km'] = null;
        if ($filters['has_coordinates'] && $provider['latitude'] !== null && $provider['longitude'] !== null) {
            $provider['distance_km'] = marketplaceDistanceKm($requestLat, $requestLon, $provider['latitude'], $provider['longitude']);
        }
        if ($filters['min_price'] !== null && ($provider['starting_price'] === null || $provider['starting_price'] < $filters['min_price'])) continue;
        if ($filters['max_price'] !== null && ($provider['starting_price'] === null || $provider['starting_price'] > $filters['max_price'])) continue;
        if ($filters['rating'] !== null && (float)$provider['average_rating'] < $filters['rating']) continue;
        if ($filters['available'] && $provider['availability_status'] !== 'available') continue;
        if ($filters['distance'] !== null && ($provider['distance_km'] === null || $provider['distance_km'] > $filters['distance'])) continue;
        if ($filters['q'] !== '') {
            $normalizedCorpus = matchingNormalize($provider['business_name'] . ' ' . $provider['provider_name'] . ' ' . $provider['city'] . ' ' . $provider['area']);
            foreach ($eligibleServices as $service) {
                $normalizedCorpus .= ' ' . matchingNormalize($service['category_name'] . ' ' . $service['service_name'] . ' ' . ($service['description'] ?? ''));
            }
            $normalizedQuery = matchingNormalize($filters['q']);
            $queryTokens = matchingTokens($filters['q']);

            $matchesSubstring = str_contains($normalizedCorpus, $normalizedQuery);
            $matchesTokens = !empty($queryTokens) && count(array_intersect($queryTokens, matchingTokens($normalizedCorpus))) === count($queryTokens);

            if (!$matchesSubstring && !$matchesTokens) {
                continue;
            }
        }
        $provider['locality_match'] = matchingNormalize((string)$request['area']) !== ''
            && matchingNormalize((string)$request['area']) === matchingNormalize((string)$provider['area']);
        $selected[] = $provider;
    }
    usort($selected, static function (array $a, array $b) use ($filters): int {
        $priceA = $a['starting_price'] ?? PHP_FLOAT_MAX;
        $priceB = $b['starting_price'] ?? PHP_FLOAT_MAX;
        return match ($filters['sort']) {
            'price_low' => [$priceA, -$a['score'], $a['provider_id']] <=> [$priceB, -$b['score'], $b['provider_id']],
            'price_high' => [-$priceA, -$a['score'], $a['provider_id']] <=> [-$priceB, -$b['score'], $b['provider_id']],
            'nearest' => [($a['distance_km'] ?? ($a['locality_match'] ? 0 : PHP_FLOAT_MAX)), -$a['score'], -$a['average_rating'], $a['provider_id']]
                <=> [($b['distance_km'] ?? ($b['locality_match'] ? 0 : PHP_FLOAT_MAX)), -$b['score'], -$b['average_rating'], $b['provider_id']],
            'highest_rated' => [-$a['average_rating'], -$a['review_count'], -$a['score'], $a['provider_id']]
                <=> [-$b['average_rating'], -$b['review_count'], -$b['score'], $b['provider_id']],
            'fastest' => [($a['response_time_minutes'] ?? PHP_INT_MAX), -$a['score'], $a['provider_id']]
                <=> [($b['response_time_minutes'] ?? PHP_INT_MAX), -$b['score'], $b['provider_id']],
            default => [-$a['score'], -$a['technical_score'], -$a['quality_score'], $a['provider_id']]
                <=> [-$b['score'], -$b['technical_score'], -$b['quality_score'], $b['provider_id']],
        };
    });
    return $selected;
}
