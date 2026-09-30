<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../config/database.php';

requireProvider();

$pdo = getDatabaseConnection();
$userId = (int) getUserId();

$profileStmt = $pdo->prepare('SELECT id, business_name, availability_status, profile_image FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
$profileStmt->execute(['user_id' => $userId]);
$providerProfile = $profileStmt->fetch();

$profileStatus = $providerProfile ? 'Complete' : 'Incomplete';
$providerId = (int)($providerProfile['id'] ?? 0);

$totalServices = 0;
$activeServices = 0;
if ($providerId > 0) {
    $serviceStatsStmt = $pdo->prepare('SELECT COUNT(*) AS total_services, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_services FROM services WHERE provider_id = :provider_id');
    $serviceStatsStmt->execute(['provider_id' => $providerId]);
    $serviceStats = $serviceStatsStmt->fetch();
    $totalServices = (int)($serviceStats['total_services'] ?? 0);
    $activeServices = (int)($serviceStats['active_services'] ?? 0);
}

// Booking statistics
$bookingStats = [
    'pending' => 0,
    'accepted' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'total' => 0,
];
$recentBookings = [];
$reputation = [
    'average_rating' => 0.0,
    'review_count' => 0,
    'completed_services' => 0,
    'rating_distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
];

if ($providerId > 0) {
    $bookingStatsStmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed
         FROM bookings
         WHERE provider_id = :provider_id"
    );
    $bookingStatsStmt->execute(['provider_id' => $providerId]);
    $bStats = $bookingStatsStmt->fetch();
    if ($bStats) {
        $bookingStats = [
            'pending' => (int)($bStats['pending'] ?? 0),
            'accepted' => (int)($bStats['accepted'] ?? 0),
            'in_progress' => (int)($bStats['in_progress'] ?? 0),
            'completed' => (int)($bStats['completed'] ?? 0),
            'total' => (int)($bStats['total'] ?? 0),
        ];
    }

    $recentBookingsStmt = $pdo->prepare(
        "SELECT b.id, b.status, b.scheduled_date, b.scheduled_time, r.title, r.city, r.area, s.service_name, cu.name AS customer_name
         FROM bookings b
         INNER JOIN service_requests r ON r.id = b.request_id
         INNER JOIN users cu ON cu.id = b.customer_id
         LEFT JOIN services s ON s.id = b.service_id
         WHERE b.provider_id = :provider_id
         ORDER BY b.created_at DESC
         LIMIT 5"
    );
    $recentBookingsStmt->execute(['provider_id' => $providerId]);
    $recentBookings = $recentBookingsStmt->fetchAll();

    // Fetch real reputation metrics
    $reputation = getProviderReputation($pdo, $providerId);
}

$availabilityStatus = $providerProfile['availability_status'] ?? 'offline';
$businessName = $providerProfile['business_name'] ?? 'Your Business';

$pageTitle = 'Provider Dashboard | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="dashboard-header">
                    <div>
                        <span class="section-kicker">Service Provider Workspace</span>
                        <h1 class="mb-1">Welcome back, <?= htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8') ?>!</h1>
                        <p class="text-muted mb-0"><?= htmlspecialchars($businessName, ENT_QUOTES, 'UTF-8') ?> · Service Operations</p>
                    </div>
                    <div>
                        <form method="POST" action="../logout.php" class="d-inline"><?= csrfField() ?><button type="submit" class="btn btn-outline-danger">Log Out</button></form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Booking Operations Metrics -->
        <h2 class="h5 mt-4 mb-3">Booking Operations</h2>
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card border-top border-4 border-warning">
                    <div class="stat-number"><?= $bookingStats['pending'] ?></div>
                    <div class="stat-label">Pending Requests</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card border-top border-4 border-info">
                    <div class="stat-number"><?= $bookingStats['accepted'] ?></div>
                    <div class="stat-label">Accepted Appointments</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card border-top border-4 border-primary">
                    <div class="stat-number"><?= $bookingStats['in_progress'] ?></div>
                    <div class="stat-label">In-Progress Services</div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="stat-card border-top border-4 border-success">
                    <div class="stat-number"><?= $bookingStats['completed'] ?></div>
                    <div class="stat-label">Completed Jobs</div>
                </div>
            </div>
        </div>

        <!-- Trust, Reputation & Business Profile -->
        <h2 class="h5 mt-5 mb-3">Trust, Reputation & Services</h2>
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="stat-card border-top border-4 border-warning">
                    <div class="stat-number">
                        <?= $reputation['review_count'] > 0 ? number_format($reputation['average_rating'], 1) : '—' ?>
                        <small class="fs-6 text-muted">/ 5</small>
                    </div>
                    <div class="stat-label">
                        <?= renderStarRating($reputation['average_rating']) ?>
                        <div class="mt-1 small"><?= $reputation['review_count'] ?> Verified Review(s)</div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon cyan">
                        <i class="bi bi-tools"></i>
                    </div>
                    <h3>Total Services</h3>
                    <p class="text-muted">You currently have <?= htmlspecialchars((string)$totalServices, ENT_QUOTES, 'UTF-8') ?> service<?= $totalServices === 1 ? '' : 's' ?> listed.</p>
                    <a href="services.php" class="btn btn-sm btn-primary">Manage Services</a>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon green">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <h3>Active Services</h3>
                    <p class="text-muted"><?= htmlspecialchars((string)$activeServices, ENT_QUOTES, 'UTF-8') ?> of your services are active and visible to customers.</p>
                    <a href="services.php" class="btn btn-sm btn-primary">View Services</a>
                </div>
            </div>

            <div class="col-md-6 col-lg-3">
                <div class="dashboard-card">
                    <div class="dashboard-card-icon amber">
                        <i class="bi bi-broadcast"></i>
                    </div>
                    <h3>Availability</h3>
                    <p class="text-muted">Current status: <?= htmlspecialchars(ucfirst($availabilityStatus), ENT_QUOTES, 'UTF-8') ?>.</p>
                    <a href="profile.php" class="btn btn-sm btn-primary">Update Status</a>
                </div>
            </div>
        </div>

        <!-- Recent Booking Requests -->
        <div class="row" style="margin-top: 3rem;">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h4 mb-0">Recent Booking Requests</h2>
                    <a href="bookings.php" class="text-link">View all bookings <i class="bi bi-arrow-right"></i></a>
                </div>
                <?php if (empty($recentBookings)): ?>
                    <div class="card shadow-sm border-0 rounded-4 p-4 text-center text-muted">
                        <i class="bi bi-inbox fs-2 mb-2"></i>
                        <p class="mb-0">No booking requests have been assigned to your profile yet.</p>
                    </div>
                <?php else: ?>
                    <div class="card shadow-sm border-0 rounded-4 p-3">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Customer</th>
                                        <th>Request / Service</th>
                                        <th>Scheduled For</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentBookings as $booking): ?>
                                        <tr>
                                            <td><strong>#<?= (int)$booking['id'] ?></strong></td>
                                            <td><?= htmlspecialchars($booking['customer_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <div class="fw-bold"><?= htmlspecialchars($booking['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></small>
                                            </td>
                                            <td>
                                                <div><?= htmlspecialchars($booking['scheduled_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <small class="text-muted"><?= htmlspecialchars(substr((string)$booking['scheduled_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></small>
                                            </td>
                                            <td>
                                                <span class="badge <?= bookingStatusClass($booking['status']) ?>">
                                                    <?= htmlspecialchars(bookingStatusLabel($booking['status']), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="booking_details.php?id=<?= (int)$booking['id'] ?>" class="btn btn-sm btn-outline-primary">Manage</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row" style="margin-top: 2.5rem;">
            <div class="col-12">
                <h2 style="margin-bottom: 1.5rem;">Quick Actions</h2>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><i class="bi bi-calendar2-check"></i></div>
                    <div class="stat-label"><a href="bookings.php" class="text-decoration-none text-dark">Manage All Bookings</a></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><i class="bi bi-inbox"></i></div>
                    <div class="stat-label"><a href="assessments.php" class="text-decoration-none text-dark">Independent Assessments</a></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><i class="bi bi-list-check"></i></div>
                    <div class="stat-label"><a href="services.php" class="text-decoration-none text-dark">Manage Services</a></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-card">
                    <div class="stat-number"><i class="bi bi-person-badge"></i></div>
                    <div class="stat-label"><a href="profile.php" class="text-decoration-none text-dark">Business Profile & Reviews</a></div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
