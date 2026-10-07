<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$customerId = (int)getUserId();

// Request statistics
$countStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_requests,
        SUM(CASE WHEN status IN ('submitted', 'analyzing', 'matched', 'in_progress') THEN 1 ELSE 0 END) AS active_requests,
        SUM(CASE WHEN status IN ('submitted', 'analyzing') THEN 1 ELSE 0 END) AS awaiting_analysis,
        SUM(CASE WHEN status IN ('completed', 'cancelled') THEN 1 ELSE 0 END) AS closed_requests
     FROM service_requests
     WHERE customer_id = :customer_id"
);
$countStmt->execute(['customer_id' => $customerId]);
$counts = $countStmt->fetch() ?: [];

// Booking statistics
$bookingCountStmt = $pdo->prepare(
    "SELECT
        COUNT(*) AS total_bookings,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_bookings,
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted_bookings,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_bookings,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_bookings,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_bookings
     FROM bookings
     WHERE customer_id = :customer_id"
);
$bookingCountStmt->execute(['customer_id' => $customerId]);
$bookingCounts = $bookingCountStmt->fetch() ?: [];

// Recent requests
$recentStmt = $pdo->prepare(
    'SELECT r.id, r.title, r.status, r.urgency, r.city, r.area, r.created_at, c.category_name
     FROM service_requests r
     LEFT JOIN service_categories c ON c.id = r.category_id
     WHERE r.customer_id = :customer_id
     ORDER BY r.created_at DESC
     LIMIT 5'
);
$recentStmt->execute(['customer_id' => $customerId]);
$recentRequests = $recentStmt->fetchAll();

// Recent bookings
$recentBookingsStmt = $pdo->prepare(
    "SELECT b.id, b.status, b.scheduled_date, b.scheduled_time, r.title, pp.business_name, s.service_name
     FROM bookings b
     INNER JOIN service_requests r ON r.id = b.request_id
     INNER JOIN provider_profiles pp ON pp.id = b.provider_id
     LEFT JOIN services s ON s.id = b.service_id
     WHERE b.customer_id = :customer_id
       AND b.status IN ('pending', 'accepted', 'in_progress')
       AND b.scheduled_date >= CURRENT_DATE
     ORDER BY b.scheduled_date ASC, b.scheduled_time ASC
     LIMIT 4"
);
$recentBookingsStmt->execute(['customer_id' => $customerId]);
$recentBookings = $recentBookingsStmt->fetchAll();

$pageTitle = 'Customer Dashboard | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <!-- Header -->
        <div class="workspace-welcome d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom">
            <div>
                <span class="section-kicker">Customer Workspace</span>
                <h1 class="mb-1">What can we solve for you, <?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?>?</h1>
                <p class="text-muted mb-0">Start with what is happening. Your requests, provider matches, and bookings stay connected here.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="create_request.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>New Request
                </a>
                <form method="POST" action="../logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-danger">Log Out</button></form>
            </div>
        </div>

        <!-- PART E: PRIMARY PROBLEM-SOLVER HERO CARD -->
        <div class="hero-solve-card mb-5">
            <div class="row g-4 align-items-center">
                <div class="col-lg-7">
                    <span class="section-kicker"><i class="bi bi-chat-square-text me-1"></i>New service request</span>
                    <h2 class="h3 mb-2">What problem can we solve for you?</h2>
                    <p class="text-muted mb-3">
                        Describe what is wrong in plain language. After you submit, ServeIQ creates a rule-based ServiceDNA summary and finds eligible providers using the available request and provider details.
                    </p>
                    <div class="mb-3">
                        <textarea id="solveTextarea" class="form-control solve-textarea" maxlength="5000" placeholder="E.g., My laptop is overheating while gaming and the fan is making a loud noise..."></textarea>
                        <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
                            <span id="solveCharCount">0 / 5000</span>
                            <span>Describe the issue in at least 20 characters</span>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <span class="small text-muted fw-bold">Example descriptions:</span>
                        <button type="button" class="quick-chip" data-problem="My laptop is overheating while gaming and the fan is making a loud noise.">
                            <i class="bi bi-laptop"></i> Laptop Overheating
                        </button>
                        <button type="button" class="quick-chip" data-problem="My AC is not cooling and the indoor unit is blowing warm air.">
                            <i class="bi bi-snow"></i> AC Not Cooling
                        </button>
                        <button type="button" class="quick-chip" data-problem="My washing machine is making loud noise and leaking water during spinning.">
                            <i class="bi bi-water"></i> Washer Noise &amp; Leak
                        </button>
                        <button type="button" class="quick-chip" data-problem="My bathroom pipe is leaking water continuously under the sink.">
                            <i class="bi bi-droplet"></i> Pipe Leak
                        </button>
                    </div>
                    <div>
                        <a id="solveSubmitBtn" href="create_request.php" class="btn btn-primary btn-lg disabled">
                            Analyze Problem &amp; Find Services <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="p-3 bg-light rounded-4 border">
                        <span class="section-kicker text-primary"><i class="bi bi-diagram-3 me-1"></i>ServeIQ 7-Stage Pipeline</span>
                        <h3 class="h6 mb-3">How Your Problem Gets Resolved</h3>
                        
                        <div class="d-flex flex-column gap-2 small">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary rounded-circle p-1" style="width: 22px; height: 22px;">1</span>
                                <span><strong>Problem Description</strong> — Natural language input</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary rounded-circle p-1" style="width: 22px; height: 22px;">2</span>
                                <span><strong>ServiceDNA</strong> — Deterministic diagnostic extraction</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary rounded-circle p-1" style="width: 22px; height: 22px;">3</span>
                                <span><strong>Optional enhancement</strong> — A local rule-enhanced layer may add context and follow-up questions</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary rounded-circle p-1" style="width: 22px; height: 22px;">4</span>
                                <span><strong>Provider Matching</strong> — 6-factor weighted ranking</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary rounded-circle p-1" style="width: 22px; height: 22px;">5</span>
                                <span><strong>ADCS Consensus</strong> — Independent provider consensus</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary rounded-circle p-1" style="width: 22px; height: 22px;">6</span>
                                <span><strong>Service Booking</strong> — 4-stage booking state lifecycle</span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-success rounded-circle p-1" style="width: 22px; height: 22px;">7</span>
                                <span><strong>Trust &amp; Review</strong> — Verified customer ratings</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metrics Overview -->
        <div class="row g-4 mb-5">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number"><?= (int)($counts['active_requests'] ?? 0) ?></div>
                    <div class="stat-label">Active Requests</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number"><?= (int)($bookingCounts['pending_bookings'] ?? 0) ?></div>
                    <div class="stat-label">Pending Bookings</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number"><?= (int)(($bookingCounts['accepted_bookings'] ?? 0) + ($bookingCounts['in_progress_bookings'] ?? 0)) ?></div>
                    <div class="stat-label">Active Services</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-number"><?= (int)($bookingCounts['completed_bookings'] ?? 0) ?></div>
                    <div class="stat-label">Completed Services</div>
                </div>
            </div>
        </div>

        <!-- Upcoming Bookings Section -->
        <div class="mb-5">
            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h2 class="h4 mb-0">Upcoming Bookings</h2>
                    <p class="text-muted small mb-0">Track future appointments and their current booking status.</p>
                </div>
                <a href="bookings.php" class="btn btn-sm btn-outline-secondary">View all bookings <i class="bi bi-arrow-right"></i></a>
            </div>

            <?php if (empty($recentBookings)): ?>
                <div class="empty-state-saas">
                    <div class="empty-state-icon"><i class="bi bi-calendar-check"></i></div>
                    <h3 class="empty-state-title">No upcoming bookings</h3>
                    <p class="empty-state-desc">Future pending, accepted, or in-progress appointments will appear here.</p>
                    <a href="create_request.php" class="btn btn-primary">Start a Request</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($recentBookings as $booking): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="card border-0 shadow-sm rounded-4 p-3 h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <span class="badge <?= bookingStatusClass($booking['status']) ?>">
                                            <span class="badge-dot"></span>
                                            <?= htmlspecialchars(bookingStatusLabel($booking['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <small class="text-muted fw-bold">#<?= (int)$booking['id'] ?></small>
                                    </div>
                                    <h3 class="h6 mb-1 text-truncate"><?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></p>
                                    <p class="small mb-2 text-truncate text-secondary">
                                        <i class="bi bi-clipboard me-1"></i><?= htmlspecialchars($booking['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                    <p class="small text-muted mb-0">
                                        <i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($booking['scheduled_date'] . ' ' . substr((string)$booking['scheduled_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <div class="mt-3 pt-2 border-top">
                                    <a href="booking_details.php?id=<?= (int)$booking['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                                        Manage Booking
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Recent Requests Section -->
        <div>
            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                <div>
                    <h2 class="h4 mb-0">Recent Service Requests</h2>
                    <p class="text-muted small mb-0">Inspect the structured problem summary, any enhancement output, and eligible provider matches.</p>
                </div>
                <a href="my_requests.php" class="btn btn-sm btn-outline-secondary">View all requests <i class="bi bi-arrow-right"></i></a>
            </div>

            <?php if (empty($recentRequests)): ?>
                <div class="empty-state-saas">
                    <div class="empty-state-icon"><i class="bi bi-chat-left-dots"></i></div>
                    <h3 class="empty-state-title">No requests submitted yet</h3>
                    <p class="empty-state-desc">Describe your first appliance or computer issue to receive automated ServiceDNA analysis and provider recommendations.</p>
                    <a href="create_request.php" class="btn btn-primary">Create Service Request</a>
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($recentRequests as $request): ?>
                        <div class="col-12">
                            <div class="request-list-card">
                                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <h3 class="h5 mb-0"><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                            <span class="text-muted small">#<?= (int)$request['id'] ?></span>
                                        </div>
                                        <p class="text-muted mb-1 small">
                                            <i class="bi bi-tag me-1"></i><?= htmlspecialchars($request['category_name'] ?: 'Unclassified', ENT_QUOTES, 'UTF-8') ?>
                                            &bull; <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($request['city'], ENT_QUOTES, 'UTF-8') ?><?= $request['area'] ? ', ' . htmlspecialchars($request['area'], ENT_QUOTES, 'UTF-8') : '' ?>
                                            &bull; <i class="bi bi-clock me-1"></i><?= htmlspecialchars(date('M j, Y', strtotime($request['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                        </p>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap align-items-center">
                                        <span class="badge <?= requestStatusClass($request['status']) ?>">
                                            <span class="badge-dot"></span>
                                            <?= htmlspecialchars(requestStatusLabel($request['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="badge <?= requestUrgencyClass($request['urgency']) ?>">
                                            <?= htmlspecialchars(requestUrgencyLabel($request['urgency']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <a href="request_details.php?id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-outline-primary ms-2">
                                            View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
