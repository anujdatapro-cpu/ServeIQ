<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$providerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
if (!$providerId || $providerId < 1) {
    http_response_code(404);
    exit('Provider not found.');
}

$pdo = getDatabaseConnection();
$stmt = $pdo->prepare(
    'SELECT pp.id, pp.business_name, pp.profile_image, pp.phone, pp.address, pp.city, pp.area, pp.description,
            pp.experience_years, pp.availability_status, pp.verification_status,
            u.name AS provider_name
     FROM provider_profiles pp
     INNER JOIN users u ON u.id = pp.user_id AND u.role = \'provider\'
     WHERE pp.id = :provider_id AND pp.verification_status = \'approved\'
     LIMIT 1'
);
$stmt->execute(['provider_id' => (int)$providerId]);
$provider = $stmt->fetch();
if (!$provider) {
    http_response_code(404);
    exit('Provider not found.');
}
$provider = decryptSensitiveFields($provider, ['phone', 'address']);
$storedProfileImage = (string)($provider['profile_image'] ?? '');
$profileImagePath = preg_match('~^uploads/profiles/[A-Za-z0-9_.-]+$~', $storedProfileImage) && is_file(__DIR__ . '/../' . $storedProfileImage)
    ? '../' . $storedProfileImage
    : '../assets/images/default-avatar.svg';

// Fetch active services
$serviceStmt = $pdo->prepare(
    'SELECT s.id, s.service_name, s.description, s.base_price, c.category_name
     FROM services s 
     INNER JOIN service_categories c ON c.id = s.category_id
     WHERE s.provider_id = :provider_id AND s.is_active = 1
     ORDER BY s.service_name ASC'
);
$serviceStmt->execute(['provider_id' => (int)$providerId]);
$services = $serviceStmt->fetchAll();

// Reputation and verified reviews
$reputation = getProviderReputation($pdo, (int)$providerId);
$reviews = getProviderReviews($pdo, (int)$providerId, true, 25);

// Booking eligibility check if viewed in context of a service request
$canBook = false;
$existingBooking = null;
$request = null;
if ($requestId && $requestId > 0) {
    $request = findCustomerRequest($pdo, (int)$requestId, (int)getUserId());
    if ($request && !in_array($request['status'], ['cancelled', 'completed'], true)) {
        $existingBooking = findBookingForRequest($pdo, (int)$requestId);
        if (!$existingBooking && $provider['availability_status'] !== 'offline') {
            $canBook = true;
        }
    }
}

$pageTitle = htmlspecialchars($provider['business_name'], ENT_QUOTES, 'UTF-8') . ' | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div class="d-flex align-items-center gap-3">
                <img class="provider-profile-avatar" src="<?= htmlspecialchars($profileImagePath, ENT_QUOTES, 'UTF-8') ?>" alt="" width="76" height="76">
                <div>
                    <span class="section-kicker">Service Provider Profile</span>
                    <h1 class="mb-1"><?= htmlspecialchars($provider['business_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="text-muted mb-0"><?= htmlspecialchars($provider['provider_name'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= $requestId && $requestId > 0 ? 'matches.php?id=' . (int)$requestId : 'dashboard.php' ?>" class="btn btn-outline-secondary">
                    <?= $requestId && $requestId > 0 ? 'Back to Matches' : 'Back to Dashboard' ?>
                </a>
                <?php if ($canBook): ?>
                    <a href="create_booking.php?request_id=<?= (int)$requestId ?>&provider_id=<?= (int)$providerId ?>" class="btn btn-primary">
                        <i class="bi bi-calendar-check me-1"></i>Book This Provider
                    </a>
                <?php elseif ($existingBooking): ?>
                    <a href="booking_details.php?id=<?= (int)$existingBooking['id'] ?>" class="btn btn-outline-primary">View Booking</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($existingBooking): ?>
            <div class="alert alert-info rounded-4 mb-4">
                <i class="bi bi-info-circle-fill me-2"></i>
                This request already has an active booking (Status: <strong><?= htmlspecialchars(bookingStatusLabel($existingBooking['status']), ENT_QUOTES, 'UTF-8') ?></strong>).
            </div>
        <?php endif; ?>

        <!-- Provider Overview & Reputation Metrics -->
        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 h-100">
                    <h2 class="h5 mb-3">Business Information</h2>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <p class="mb-2"><strong><i class="bi bi-geo-alt me-1 text-primary"></i>Location:</strong><br><?= htmlspecialchars($provider['city'], ENT_QUOTES, 'UTF-8') ?><?= $provider['area'] ? ', ' . htmlspecialchars($provider['area'], ENT_QUOTES, 'UTF-8') : '' ?></p>
                            <p class="mb-2"><strong><i class="bi bi-pin-map me-1 text-primary"></i>Address:</strong><br><?= htmlspecialchars($provider['address'], ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div class="col-sm-6">
                            <p class="mb-2"><strong><i class="bi bi-broadcast me-1 text-primary"></i>Availability:</strong><br><span class="badge text-bg-light border"><?= htmlspecialchars(ucfirst($provider['availability_status']), ENT_QUOTES, 'UTF-8') ?></span></p>
                            <p class="mb-2"><strong><i class="bi bi-award me-1 text-primary"></i>Experience:</strong><br><?= (int)$provider['experience_years'] ?> years</p>
                        </div>
                    </div>
                    <?php if ($provider['description']): ?>
                        <hr>
                        <h3 class="h6">About Provider</h3>
                        <p class="mb-0 text-secondary"><?= nl2br(htmlspecialchars($provider['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 h-100">
                    <span class="section-kicker">Trust & Reputation</span>
                    <h2 class="h5 mb-3">Customer Feedback</h2>

                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="display-5 fw-bold text-dark">
                            <?= $reputation['review_count'] > 0 ? number_format($reputation['average_rating'], 1) : '—' ?>
                        </div>
                        <div>
                            <div class="fs-5">
                                <?= renderStarRating($reputation['average_rating']) ?>
                            </div>
                            <div class="small text-muted">
                                <?= $reputation['review_count'] > 0 ? (int)$reputation['review_count'] . ' verified review(s)' : 'No reviews yet' ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 p-3 bg-light rounded-3 small">
                        <div class="d-flex justify-content-between mb-1">
                            <span><i class="bi bi-check2-circle text-success me-1"></i>Completed Services:</span>
                            <strong><?= (int)$reputation['completed_services'] ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span><i class="bi bi-patch-check-fill text-primary me-1"></i>Verified Review Ratio:</span>
                            <strong><?= $reputation['completed_services'] > 0 ? round(($reputation['review_count'] / $reputation['completed_services']) * 100) : 0 ?>%</strong>
                        </div>
                    </div>

                    <!-- Rating Distribution -->
                    <h3 class="h6 text-muted small text-uppercase mb-2">Rating Breakdown</h3>
                    <?php 
                    $totalReviews = max(1, $reputation['review_count']);
                    for ($s = 5; $s >= 1; $s--): 
                        $cnt = $reputation['rating_distribution'][$s] ?? 0;
                        $pct = round(($cnt / $totalReviews) * 100);
                    ?>
                        <div class="d-flex align-items-center gap-2 mb-1 small">
                            <span class="text-muted" style="width: 25px;"><?= $s ?>★</span>
                            <div class="progress flex-grow-1" style="height: 6px;">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $pct ?>%;" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            <span class="text-muted text-end" style="width: 30px;"><?= $cnt ?></span>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Active Services Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
            <h2 class="h5 mb-3">Active Services</h2>
            <?php if (empty($services)): ?>
                <p class="text-muted mb-0">No active services are currently listed for this provider.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($services as $service): ?>
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <h3 class="h6 mb-1"><?= htmlspecialchars($service['service_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <?php if ($service['base_price'] !== null): ?>
                                            <span class="badge text-bg-light border">From ₹<?= htmlspecialchars(number_format((float)$service['base_price'], 2), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($service['category_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="small mb-3"><?= nl2br(htmlspecialchars($service['description'] ?? '', ENT_QUOTES, 'UTF-8')) ?></p>
                                </div>
                                <?php if ($canBook): ?>
                                    <div class="pt-2 border-top">
                                        <a href="create_booking.php?request_id=<?= (int)$requestId ?>&provider_id=<?= (int)$providerId ?>&service_id=<?= (int)$service['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-calendar-plus me-1"></i>Book This Service
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Verified Reviews Section -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <span class="section-kicker">Customer Reviews</span>
                    <h2 class="h5 mb-0">Verified Completed Service Reviews</h2>
                </div>
                <span class="badge text-bg-light border text-muted">
                    Total: <?= count($reviews) ?>
                </span>
            </div>

            <?php if (empty($reviews)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-chat-square-quote fs-1 d-block mb-2"></i>
                    <p class="mb-0">No customer reviews yet. Reviews are permanently attached to completed bookings.</p>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($reviews as $rev): ?>
                        <div class="col-12">
                            <div class="border rounded-3 p-3 bg-light">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                    <div>
                                        <div class="fw-semibold text-dark">
                                            <?= formatCustomerDisplayName((string)$rev['customer_name']) ?>
                                            <span class="badge text-bg-success ms-2 small">
                                                <i class="bi bi-patch-check-fill me-1"></i>Verified Service
                                            </span>
                                        </div>
                                        <small class="text-muted">
                                            Service: <?= htmlspecialchars($rev['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?>
                                            · <?= htmlspecialchars(date('M j, Y', strtotime($rev['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                        </small>
                                    </div>
                                    <div>
                                        <span class="me-1"><?= renderStarRating((float)$rev['rating']) ?></span>
                                        <strong><?= (int)$rev['rating'] ?>/5</strong>
                                    </div>
                                </div>
                                <?php if (!empty($rev['review'])): ?>
                                    <p class="mb-0 mt-2 text-secondary bg-white p-3 rounded-2 border">
                                        <?= nl2br(htmlspecialchars($rev['review'], ENT_QUOTES, 'UTF-8')) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
