<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../config/database.php';

requireProvider();

$pdo = getDatabaseConnection();
$userId = (int)getUserId();

$profileStmt = $pdo->prepare('SELECT id, business_name FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
$profileStmt->execute(['user_id' => $userId]);
$provider = $profileStmt->fetch();

if (!$provider) {
    http_response_code(403);
    exit('Please complete your service provider business profile first.');
}

$providerId = (int)$provider['id'];
$filterStatus = (string)($_GET['status'] ?? '');
$allowedStatuses = bookingStatuses();

$conditions = ['b.provider_id = :provider_id'];
$params = ['provider_id' => $providerId];

if (in_array($filterStatus, $allowedStatuses, true)) {
    $conditions[] = 'b.status = :status';
    $params['status'] = $filterStatus;
} else {
    $filterStatus = '';
}

$sql = 'SELECT b.id, b.status, b.scheduled_date, b.scheduled_time, b.created_at,
               r.id AS request_id, r.title, r.city, r.area,
               cu.name AS customer_name,
               s.service_name
        FROM bookings b
        INNER JOIN service_requests r ON r.id = b.request_id
        INNER JOIN users cu ON cu.id = b.customer_id
        LEFT JOIN services s ON s.id = b.service_id
        WHERE ' . implode(' AND ', $conditions) . '
        ORDER BY b.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$pageTitle = 'Provider Bookings | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Service Provider Workspace</span>
                <h1 class="mb-1">Assigned Bookings & Service Jobs</h1>
                <p class="text-muted mb-0">Manage customer appointments, service progress, and completions.</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>

        <!-- Filter tabs -->
        <div class="card shadow-sm border-0 rounded-4 p-3 mb-4">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <span class="text-muted small fw-bold me-2">Status:</span>
                <a href="bookings.php" class="btn btn-sm <?= $filterStatus === '' ? 'btn-dark' : 'btn-outline-secondary' ?>">
                    All
                </a>
                <?php foreach ($allowedStatuses as $status): ?>
                    <a href="bookings.php?status=<?= urlencode($status) ?>" class="btn btn-sm <?= $filterStatus === $status ? 'btn-dark' : 'btn-outline-secondary' ?>">
                        <?= htmlspecialchars(bookingStatusLabel($status), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($bookings)): ?>
            <div class="empty-state-card text-center">
                <i class="bi bi-calendar2-x fs-1 text-muted"></i>
                <h2 class="h4 mt-3">No bookings found</h2>
                <p class="text-muted">
                    <?= $filterStatus !== '' ? 'No bookings match the selected status filter.' : 'You have not received any customer service booking requests yet.' ?>
                </p>
                <a href="dashboard.php" class="btn btn-primary rounded-pill">Dashboard</a>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Booking ID</th>
                                <th>Customer</th>
                                <th>Problem / Request</th>
                                <th>Service Item</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td>
                                        <strong>#<?= (int)$booking['id'] ?></strong>
                                        <div class="text-muted small"><?= htmlspecialchars(date('M j, Y', strtotime($booking['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($booking['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold text-truncate" style="max-width: 250px;">
                                            <?= htmlspecialchars($booking['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?= htmlspecialchars($booking['city'] . ($booking['area'] ? ', ' . $booking['area'] : ''), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <div><i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($booking['scheduled_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted small"><i class="bi bi-clock me-1"></i><?= htmlspecialchars(substr((string)$booking['scheduled_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?= bookingStatusClass($booking['status']) ?>">
                                            <?= htmlspecialchars(bookingStatusLabel($booking['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="booking_details.php?id=<?= (int)$booking['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            Manage
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
