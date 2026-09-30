<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId || $requestId < 1) {
    http_response_code(404);
    exit('Request not found.');
}

$pdo = getDatabaseConnection();
$request = findCustomerRequest($pdo, (int)$requestId, (int)getUserId());
if (!$request) {
    http_response_code(404);
    exit('Request not found.');
}

$providers = getRankedProvidersForRequest($pdo, (int)$requestId, (int)getUserId());
$existingBooking = findBookingForRequest($pdo, (int)$requestId);
$canBook = !$existingBooking && !in_array($request['status'], ['cancelled', 'completed'], true);

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
                <p class="text-muted mb-0">Ranked using 6-factor weighted correlation from your ServiceDNA and verified provider profiles.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back to Request
                </a>
                <?php if ($existingBooking): ?>
                    <a href="booking_details.php?id=<?= (int)$existingBooking['id'] ?>" class="btn btn-outline-primary">
                        <i class="bi bi-calendar-check me-1"></i>View Current Booking
                    </a>
                <?php endif; ?>
            </div>
        </div>

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
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-light">
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

        <?php if (empty($providers)): ?>
            <div class="empty-state-saas">
                <div class="empty-state-icon"><i class="bi bi-person-x"></i></div>
                <h3 class="empty-state-title">No Strong Matches Found Yet</h3>
                <p class="empty-state-desc">
                    Our matching algorithm requires at least a 40% composite score across category, technical symptoms, and location.
                    As more providers register and configure services in your area, matches will appear here.
                </p>
                <a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-outline-primary">Return to Request Details</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($providers as $provider): ?>
                    <div class="col-12">
                        <article class="request-list-card">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
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
                                </div>
                                <div class="text-end">
                                    <span class="badge-match rounded-pill">
                                        <i class="bi bi-bullseye me-1"></i><?= round((float)$provider['score']) ?>% MATCH
                                    </span>
                                    <?php if ($provider['average_rating'] > 0): ?>
                                        <div class="mt-2 small">
                                            <span class="text-warning"><i class="bi bi-star-fill"></i></span>
                                            <strong><?= number_format((float)$provider['average_rating'], 1) ?></strong>/5
                                            <span class="text-muted">(<?= (int)$provider['review_count'] ?> review<?= (int)$provider['review_count'] === 1 ? '' : 's' ?>)</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <hr class="my-3">

                            <!-- Explainable Match Reasons -->
                            <div class="mb-3">
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
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
