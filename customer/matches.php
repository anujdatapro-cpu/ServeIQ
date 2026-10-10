<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/marketplace_filters.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$customerId = (int)getUserId();
$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// Query all customer requests to support direct navigation from workspace dropdown
$reqListStmt = $pdo->prepare('SELECT id, title, status, created_at FROM service_requests WHERE customer_id = :customer_id ORDER BY created_at DESC');
$reqListStmt->execute(['customer_id' => $customerId]);
$customerRequests = $reqListStmt->fetchAll();

if (!$requestId || $requestId < 1) {
    if (!empty($customerRequests)) {
        $requestId = (int)$customerRequests[0]['id'];
    }
}

$request = $requestId ? findCustomerRequest($pdo, (int)$requestId, $customerId) : null;

$allProviders = ($requestId && $request) ? getRankedProvidersForRequest($pdo, (int)$requestId, $customerId) : [];
$categories = $pdo->query('SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY category_name')->fetchAll();
$matchingRequest = ($requestId && $request) ? (getMatchingRequest($pdo, (int)$requestId, $customerId) ?? []) : [];
$matchingAttributes = matchingRequestAttributes($matchingRequest);
$fingerprintData = matchingDecodeJson($matchingRequest['fingerprint_data'] ?? []);
$clarificationRequired = (bool)($fingerprintData['clarification_required'] ?? false);
$clarificationQuestion = (string)($fingerprintData['clarification_question'] ?? '');
$clarificationOptions = is_array($fingerprintData['clarification_options'] ?? null) ? $fingerprintData['clarification_options'] : [];
$filters = marketplaceParseFilters($_GET, $categories, $matchingRequest);

// Include candidates matching direct business/shop name or service search
if ($filters['q'] !== '' && $requestId && $request) {
    $existingProviderIds = array_column($allProviders, 'provider_id');
    $allCandidates = getProviderCandidates($pdo, (int)$requestId, $customerId);
    foreach ($allCandidates as $candidate) {
        $cId = (int)$candidate['provider_id'];
        if (!in_array($cId, $existingProviderIds, true)) {
            $evaluated = array_merge($candidate, calculateProviderMatchScore($request, $candidate));
            $allProviders[] = $evaluated;
        }
    }
}

$filteredProviders = marketplaceFilterAndSortProviders($allProviders, $filters, $matchingRequest);
$totalProviders = count($filteredProviders);

$ineligibleNotice = '';
if ($filters['q'] !== '' && $filteredProviders === []) {
    $searchPattern = '%' . trim($filters['q']) . '%';
    $checkIneligibleStmt = $pdo->prepare(
        'SELECT pp.id, pp.business_name, pp.verification_status, pp.availability_status, pp.marketplace_active,
                u.name AS provider_name,
                (SELECT COUNT(*) FROM services s WHERE s.provider_id = pp.id AND s.is_active = 1) AS active_services_count
         FROM provider_profiles pp
         INNER JOIN users u ON u.id = pp.user_id
         WHERE pp.business_name LIKE :q OR u.name LIKE :q
         LIMIT 1'
    );
    $checkIneligibleStmt->execute(['q' => $searchPattern]);
    $ineligibleProvider = $checkIneligibleStmt->fetch();

    if ($ineligibleProvider) {
        $reasons = [];
        if ($ineligibleProvider['verification_status'] !== 'approved') {
            $reasons[] = 'profile verification is ' . $ineligibleProvider['verification_status'];
        }
        if ($ineligibleProvider['availability_status'] === 'offline') {
            $reasons[] = 'provider status is currently offline';
        }
        if ((int)$ineligibleProvider['marketplace_active'] !== 1) {
            $reasons[] = 'marketplace listing is inactive';
        }
        if ((int)$ineligibleProvider['active_services_count'] === 0) {
            $reasons[] = 'no active services are currently listed';
        }

        if ($reasons !== []) {
            $ineligibleNotice = 'The provider "' . htmlspecialchars($ineligibleProvider['business_name'], ENT_QUOTES, 'UTF-8') . '" was found in the database, but cannot be booked at this time because ' . htmlspecialchars(implode(', ', $reasons), ENT_QUOTES, 'UTF-8') . '.';
        }
    }
}

$pageSize = 10;
$pageCount = max(1, (int)ceil($totalProviders / $pageSize));
$filters['page'] = min($filters['page'], $pageCount);
$providers = array_slice($filteredProviders, ($filters['page'] - 1) * $pageSize, $pageSize);
$existingBooking = $requestId ? findBookingForRequest($pdo, (int)$requestId) : null;
$canBook = !$existingBooking && $request && !in_array($request['status'], ['cancelled', 'completed'], true);

$pageTitle = 'Recommended Providers | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom">
            <div>
                <span class="section-kicker">Phase 6 · Intelligent Provider Matching</span>
                <h1 class="mb-1">Recommended Service Providers</h1>
                <p class="text-muted mb-0">Ranked using 6-factor weighted correlation from your Service Analysis and verified provider profiles.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <?php if ($requestId): ?>
                    <a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Back to Request
                    </a>
                <?php endif; ?>
                <a href="my_requests.php" class="btn btn-outline-secondary">My Requests</a>
                <?php if ($existingBooking): ?>
                    <a href="booking_details.php?id=<?= (int)$existingBooking['id'] ?>" class="btn btn-outline-primary">
                        <i class="bi bi-calendar-check me-1"></i>View Current Booking
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($customerRequests) && count($customerRequests) > 1): ?>
            <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-light">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <label for="requestSwitcher" class="form-label mb-0 small fw-bold text-nowrap"><i class="bi bi-arrow-left-right me-1"></i>Select Request:</label>
                    <select id="requestSwitcher" class="form-select form-select-sm" style="max-width: 400px;" onchange="window.location.href='matches.php?id=' + this.value">
                        <?php foreach ($customerRequests as $cr): ?>
                            <option value="<?= (int)$cr['id'] ?>" <?= (int)$cr['id'] === $requestId ? 'selected' : '' ?>>
                                #<?= (int)$cr['id'] ?> - <?= htmlspecialchars(mb_substr($cr['title'], 0, 45), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(requestStatusLabel($cr['status']), ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$request): ?>
            <div class="empty-state-card text-center p-5 card border-0 shadow-sm rounded-4">
                <i class="bi bi-diagram-3 fs-1 text-primary mb-3"></i>
                <h2 class="h4">No Service Requests Found</h2>
                <p class="text-muted mb-3">You have not created any service requests yet. Matches are calculated automatically after you describe your problem.</p>
                <a href="create_request.php" class="btn btn-primary rounded-pill px-4">Describe Your Problem</a>
            </div>
        <?php else: ?>

        <?php if ($allProviders !== []): ?><section class="marketplace-filters card border-0 shadow-sm rounded-4 p-3 p-lg-4 mb-4" aria-labelledby="filterHeading">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div><h2 class="h5 mb-1" id="filterHeading">Find the right provider</h2><p class="small text-muted mb-0"><?= (int)$totalProviders ?> matching provider<?= $totalProviders === 1 ? '' : 's' ?> after filters</p></div>
                <button class="btn btn-outline-primary d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#marketplaceFilterBody" aria-expanded="false" aria-controls="marketplaceFilterBody">Filters &amp; sort</button>
            </div>
            <div class="collapse show" id="marketplaceFilterBody">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="id" value="<?= (int)$requestId ?>">
                    <div class="col-12 col-md-6 col-lg-3"><label for="filter_q" class="form-label">Search by business/shop name</label><input id="filter_q" name="q" class="form-control" maxlength="80" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Business name, service, or area"></div>
                    <div class="col-12 col-md-6 col-lg-3"><label for="filter_sort" class="form-label">Sort by</label><select id="filter_sort" name="sort" class="form-select"><option value="best_match" <?= $filters['sort'] === 'best_match' ? 'selected' : '' ?>>Best match</option><option value="nearest" <?= $filters['sort'] === 'nearest' ? 'selected' : '' ?>>Nearest</option><option value="price_low" <?= $filters['sort'] === 'price_low' ? 'selected' : '' ?>>Lowest price</option><option value="price_high" <?= $filters['sort'] === 'price_high' ? 'selected' : '' ?>>Highest price</option><option value="highest_rated" <?= $filters['sort'] === 'highest_rated' ? 'selected' : '' ?>>Highest rated</option><option value="fastest" <?= $filters['sort'] === 'fastest' ? 'selected' : '' ?>>Fastest response estimate</option></select></div>
                    <div class="col-6 col-lg-2"><label for="filter_min_price" class="form-label">Min price (₹)</label><input id="filter_min_price" name="min_price" type="number" min="0" max="100000" step="1" class="form-control" value="<?= $filters['min_price'] !== null ? htmlspecialchars((string)$filters['min_price'], ENT_QUOTES, 'UTF-8') : '' ?>"></div>
                    <div class="col-6 col-lg-2"><label for="filter_max_price" class="form-label">Max price (₹)</label><input id="filter_max_price" name="max_price" type="number" min="0" max="100000" step="1" class="form-control" value="<?= $filters['max_price'] !== null ? htmlspecialchars((string)$filters['max_price'], ENT_QUOTES, 'UTF-8') : '' ?>"></div>
                    <div class="col-6 col-lg-2"><label for="filter_rating" class="form-label">Rating</label><select id="filter_rating" name="rating" class="form-select"><option value="">Any rating</option><?php foreach ([4.0, 4.5, 4.8] as $rating): ?><option value="<?= $rating ?>" <?= $filters['rating'] === $rating ? 'selected' : '' ?>><?= number_format($rating, 1) ?>+</option><?php endforeach; ?></select></div>
                    <div class="col-12 col-md-6 col-lg-3"><label for="filter_category" class="form-label">Service category</label><select id="filter_category" name="category" class="form-select"><option value="">All matched services</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $filters['category'] === (int)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                    <div class="col-6 col-lg-2"><label for="filter_distance" class="form-label">Within</label><select id="filter_distance" name="distance" class="form-select" <?= $filters['has_coordinates'] ? '' : 'disabled' ?>><option value="">Any distance</option><?php foreach ([2.0, 5.0, 10.0] as $distance): ?><option value="<?= (int)$distance ?>" <?= $filters['distance'] === $distance ? 'selected' : '' ?>><?= (int)$distance ?> km</option><?php endforeach; ?></select></div>
                    <div class="col-6 col-lg-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="available" id="filter_available" value="1" <?= $filters['available'] ? 'checked' : '' ?>><label class="form-check-label" for="filter_available">Available now</label></div></div>
                    <div class="col-12 col-lg-5 small text-muted"><?php if (!$filters['has_coordinates']): ?>Distance filters need request coordinates. Add them when creating a request to enable this option.<?php elseif ($filters['distance_unavailable']): ?>Some providers have no coordinates, so radius filtering only includes providers with known coordinates.<?php endif; ?> Fastest response uses provider estimates; demo estimates are labelled.</div>
                    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="matches.php?id=<?= (int)$requestId ?>">Clear all</a></div>
                </form>
            </div>
        </section><?php endif; ?>

        <?php if ($existingBooking): ?>
            <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 rounded-4 border-0 shadow-sm">
                <div>
                    <i class="bi bi-info-circle-fill me-2 text-primary"></i>
                    This request already has an active booking with <strong><?= htmlspecialchars($existingBooking['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    (Status: <span class="badge <?= bookingStatusClass($existingBooking['status']) ?>"><?= htmlspecialchars(bookingStatusLabel($existingBooking['status']), ENT_QUOTES, 'UTF-8') ?></span>).
                </div>
                <a href="booking_details.php?id=<?= (int)$existingBooking['id'] ?>" class="btn btn-sm btn-primary">Manage Booking</a>
            </div>
        <?php endif; ?>

        <!-- Request Diagnostic Context Summary -->
        <div class="matching-context-card card border-0 shadow-sm rounded-4 p-4 mb-4 bg-light">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <span class="text-muted small text-uppercase fw-bold">Service Request Target</span>
                    <h2 class="h5 mb-0"><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <div>
                    <span class="badge bg-secondary-subtle text-secondary border px-3 py-2">
                        <i class="bi bi-shield-check me-1"></i>Deterministic 6-Factor Matching
                    </span>
                </div>
            </div>
        </div>

        <?php if ($allProviders === []): ?>
            <div class="empty-state-saas">
                <div class="empty-state-icon"><i class="bi bi-person-x"></i></div>
                <?php if ($matchingAttributes['category_id'] === null && $clarificationRequired): ?>
                    <h3 class="empty-state-title"><?= htmlspecialchars($clarificationQuestion !== '' ? $clarificationQuestion : 'Could you clarify the service you need?', ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="empty-state-desc">The description does not identify one supported service clearly enough to show providers. Choose the device or update the request details.</p>
                    <?php if ($clarificationOptions !== []): ?><div class="d-flex flex-wrap justify-content-center gap-2 mb-3"><?php foreach ($clarificationOptions as $option): ?><span class="service-chip"><strong><?= htmlspecialchars((string)$option, ENT_QUOTES, 'UTF-8') ?></strong></span><?php endforeach; ?></div><?php endif; ?>
                    <a href="edit_request.php?id=<?= (int)$requestId ?>" class="btn btn-primary">Clarify the request</a>
                <?php elseif ($matchingAttributes['category_id'] === null): ?>
                    <h3 class="empty-state-title">This service is not currently supported.</h3>
                    <p class="empty-state-desc">No supported service category could be identified, so ServeIQ has not shown unrelated providers. Review the supported categories or revise your description.</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2"><a href="../index.php#services" class="btn btn-outline-primary">Explore supported services</a><a href="edit_request.php?id=<?= (int)$requestId ?>" class="btn btn-primary">Update request</a></div>
                <?php else: ?>
                    <h3 class="empty-state-title">No strongly relevant provider was found.</h3>
                    <p class="empty-state-desc">We only show approved, non-offline providers with an active service in <?= htmlspecialchars((string)($request['category_name'] ?? 'the identified category'), ENT_QUOTES, 'UTF-8') ?> in <?= htmlspecialchars((string)$request['city'], ENT_QUOTES, 'UTF-8') ?>. Try broadening the description or changing the service location.</p>
                    <div class="d-flex flex-wrap justify-content-center gap-2"><a href="edit_request.php?id=<?= (int)$requestId ?>" class="btn btn-primary">Change problem or location</a><a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">Back to request</a></div>
                <?php endif; ?>
            </div>
        <?php elseif ($filteredProviders === []): ?>
            <div class="empty-state-saas">
                <div class="empty-state-icon"><i class="bi bi-sliders"></i></div>
                <h3 class="empty-state-title">No providers match these filters</h3>
                <?php if ($ineligibleNotice !== ''): ?>
                    <div class="alert alert-warning border-0 rounded-4 text-start mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $ineligibleNotice ?>
                    </div>
                <?php elseif ($filters['q'] !== ''): ?>
                    <p class="empty-state-desc">No provider or business matching "<strong><?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?></strong>" was found in the database. Clear search or try a different term.</p>
                <?php else: ?>
                    <p class="empty-state-desc">Clear one or more filters to see more providers for this request.</p>
                <?php endif; ?>
                <a href="matches.php?id=<?= (int)$requestId ?>" class="btn btn-primary">Clear filters</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($providers as $provider): ?>
                    <?php
                        $storedAvatar = (string)($provider['profile_image'] ?? '');
                        $avatarPath = preg_match('~^uploads/profiles/[A-Za-z0-9_.-]+$~', $storedAvatar) && is_file(__DIR__ . '/../' . $storedAvatar)
                            ? '../' . $storedAvatar
                            : '../assets/images/default-avatar.svg';
                    ?>
                    <div class="col-12">
                        <article class="request-list-card provider-match-card<?= (int)$provider['ranking_position'] === 1 ? ' top-match' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div class="d-flex align-items-start gap-3">
                                    <img class="provider-avatar" src="<?= htmlspecialchars($avatarPath, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" width="56" height="56">
                                    <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="badge bg-primary text-white px-2 py-1">Rank #<?= (int)$provider['ranking_position'] ?></span>
                                        <h2 class="h4 mb-0"><?= htmlspecialchars($provider['business_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                        <?php if (($provider['verification_status'] ?? '') === 'approved'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle" title="Verified Provider">
                                                <i class="bi bi-patch-check-fill"></i> Verified
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-muted mb-1 small">
                                        <i class="bi bi-person me-1"></i><?= htmlspecialchars($provider['provider_name'], ENT_QUOTES, 'UTF-8') ?>
                                        &bull; <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($provider['city'], ENT_QUOTES, 'UTF-8') ?><?= $provider['area'] !== '' ? ', ' . htmlspecialchars($provider['area'], ENT_QUOTES, 'UTF-8') : '' ?>
                                        &bull; <i class="bi bi-briefcase me-1"></i><?= (int)$provider['experience_years'] ?> yrs experience
                                        &bull; <span class="badge bg-light text-dark border"><?= htmlspecialchars(ucfirst($provider['availability_status']), ENT_QUOTES, 'UTF-8') ?></span>
                                    </p>
                                    <p class="small mb-1"><strong>Starting from ₹<?= $provider['starting_price'] !== null ? number_format((float)$provider['starting_price'], 0) : 'Price on request' ?></strong><?php if ($provider['distance_km'] !== null): ?> <span class="text-muted">· approximately <?= number_format((float)$provider['distance_km'], 1) ?> km<?= $provider['location_source'] === 'demo_estimate' ? ' (demo location estimate)' : '' ?></span><?php endif; ?></p>
                                    <?php if ($provider['response_time_minutes'] !== null): ?><p class="small text-muted mb-1">Estimated response ~<?= (int)$provider['response_time_minutes'] ?> min<?= $provider['response_time_source'] === 'demo_estimate' ? ' (demo estimate)' : ' (provider estimate)' ?></p><?php endif; ?>
                                    <?php if (!empty($provider['services'])): ?>
                                        <div class="d-flex gap-2 flex-wrap mt-2" aria-label="Provider services">
                                            <?php foreach (array_slice($provider['services'], 0, 4) as $service): ?>
                                                <span class="service-chip"><span><?= htmlspecialchars((string)$service['category_name'], ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars((string)$service['service_name'], ENT_QUOTES, 'UTF-8') ?><?= $service['base_price'] !== null ? ' · ₹' . number_format((float)$service['base_price'], 0) : '' ?></strong></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <span class="small text-muted d-block">Match score</span>
                                    <strong class="match-score-value"><?= round((float)$provider['score']) ?><span>%</span></strong>
                                    <div class="match-score-track" role="meter" aria-label="Provider match score" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round((float)$provider['score']) ?>">
                                        <span style="width: <?= max(0, min(100, (float)$provider['score'])) ?>%"></span>
                                    </div>
                                    <?php if ($provider['average_rating'] > 0): ?>
                                        <div class="mt-2 small">
                                            <span class="text-warning"><i class="bi bi-star-fill"></i></span>
                                            <strong><?= number_format((float)$provider['average_rating'], 1) ?></strong>/5
                                            <span class="text-muted">(<?= (int)$provider['review_count'] ?> review<?= (int)$provider['review_count'] === 1 ? '' : 's' ?>)</span>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-2 small text-muted">No reviews yet</div>
                                    <?php endif; ?>
                                    <?php if ((int)($provider['completed_jobs'] ?? 0) > 0): ?>
                                        <div class="small text-muted mt-1"><?= (int)$provider['completed_jobs'] ?> completed service<?= (int)$provider['completed_jobs'] === 1 ? '' : 's' ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <hr class="my-3">

                            <!-- Explainable Match Reasons -->
                            <div class="match-explanation mb-3">
                                <h3 class="h6 text-uppercase text-muted mb-2">Why this provider matched</h3>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($provider['reasons'] as $reason): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 text-start">
                                            <i class="bi bi-check-circle-fill me-1 text-primary"></i> <?= htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Expandable 6-Factor Score Breakdown Accordion -->
                            <details class="mb-3">
                                <summary class="small text-primary fw-bold cursor-pointer">
                                    <i class="bi bi-bar-chart me-1"></i>View 6-Factor Score Breakdown
                                </summary>
                                <div class="bg-light p-3 rounded-3 border mt-2">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Category Alignment (25% max)</span>
                                                <strong><?= htmlspecialchars((string)$provider['breakdown']['category'], ENT_QUOTES, 'UTF-8') ?> pts</strong>
                                            </div>
                                            <div class="breakdown-bar"><div class="breakdown-bar-fill" style="width: <?= min(100, ((float)$provider['breakdown']['category'] / 25) * 100) ?>%;"></div></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Service Type Match (20% max)</span>
                                                <strong><?= htmlspecialchars((string)$provider['breakdown']['service_type'], ENT_QUOTES, 'UTF-8') ?> pts</strong>
                                            </div>
                                            <div class="breakdown-bar"><div class="breakdown-bar-fill" style="width: <?= min(100, ((float)$provider['breakdown']['service_type'] / 20) * 100) ?>%;"></div></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Symptom Technical Match (20% max)</span>
                                                <strong><?= htmlspecialchars((string)$provider['breakdown']['problem_symptoms'], ENT_QUOTES, 'UTF-8') ?> pts</strong>
                                            </div>
                                            <div class="breakdown-bar"><div class="breakdown-bar-fill" style="width: <?= min(100, ((float)$provider['breakdown']['problem_symptoms'] / 20) * 100) ?>%;"></div></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Keywords &amp; Skills (15% max)</span>
                                                <strong><?= htmlspecialchars((string)$provider['breakdown']['keyword_skill'], ENT_QUOTES, 'UTF-8') ?> pts</strong>
                                            </div>
                                            <div class="breakdown-bar"><div class="breakdown-bar-fill" style="width: <?= min(100, ((float)$provider['breakdown']['keyword_skill'] / 15) * 100) ?>%;"></div></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Location Proximity (10% max)</span>
                                                <strong><?= htmlspecialchars((string)$provider['breakdown']['location'], ENT_QUOTES, 'UTF-8') ?> pts</strong>
                                            </div>
                                            <div class="breakdown-bar"><div class="breakdown-bar-fill" style="width: <?= min(100, ((float)$provider['breakdown']['location'] / 10) * 100) ?>%;"></div></div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="d-flex justify-content-between small mb-1">
                                                <span>Provider Quality (10% max)</span>
                                                <strong><?= htmlspecialchars((string)$provider['breakdown']['provider_quality'], ENT_QUOTES, 'UTF-8') ?> pts</strong>
                                            </div>
                                            <div class="breakdown-bar"><div class="breakdown-bar-fill" style="width: <?= min(100, ((float)$provider['breakdown']['provider_quality'] / 10) * 100) ?>%;"></div></div>
                                        </div>
                                    </div>
                                </div>
                            </details>

                            <!-- Card Actions -->
                            <div class="d-flex gap-2 flex-wrap pt-2 border-top">
                                <a href="provider_details.php?id=<?= (int)$provider['provider_id'] ?>&request_id=<?= (int)$requestId ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-person-lines-fill me-1"></i>View Profile &amp; Services
                                </a>
                                <?php if ($canBook): ?>
                                    <a href="create_booking.php?request_id=<?= (int)$requestId ?>&provider_id=<?= (int)$provider['provider_id'] ?>" class="btn btn-primary btn-sm ms-auto">
                                        <i class="bi bi-calendar-check me-1"></i>Book This Provider
                                    </a>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($pageCount > 1): ?>
                <?php $pageParams = ['id' => (int)$requestId, 'sort' => $filters['sort'], 'q' => $filters['q'], 'min_price' => $filters['min_price'], 'max_price' => $filters['max_price'], 'rating' => $filters['rating'], 'available' => $filters['available'] ? 1 : null, 'distance' => $filters['distance'], 'category' => $filters['category']]; ?>
                <nav class="mt-4" aria-label="Provider results pages"><ul class="pagination justify-content-center flex-wrap">
                    <li class="page-item <?= $filters['page'] <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($pageParams, ['page' => max(1, $filters['page'] - 1)])), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">Previous</a></li>
                    <?php for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++): ?><li class="page-item <?= $pageNumber === $filters['page'] ? 'active' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($pageParams, ['page' => $pageNumber])), ENT_QUOTES, 'UTF-8') ?>" <?= $pageNumber === $filters['page'] ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a></li><?php endfor; ?>
                    <li class="page-item <?= $filters['page'] >= $pageCount ? 'disabled' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($pageParams, ['page' => min($pageCount, $filters['page'] + 1)])), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">Next</a></li>
                </ul></nav>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
