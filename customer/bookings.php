<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$customerId = (int)getUserId();

$filterStatus = (string)($_GET['status'] ?? '');
$allowedStatuses = bookingStatuses();

$conditions = ['b.customer_id = :customer_id'];
$params = ['customer_id' => $customerId];

if (in_array($filterStatus, $allowedStatuses, true)) {
    $conditions[] = 'b.status = :status';
    $params['status'] = $filterStatus;
} else {
    $filterStatus = '';
}

$sql = 'SELECT b.id, b.status, b.scheduled_date, b.scheduled_time, b.created_at,
               r.id AS request_id, r.title, r.city, r.area,
               pp.business_name, pp.phone AS provider_phone,
               s.service_name, s.base_price,
               rv.id AS review_id, rv.rating AS review_rating
        FROM bookings b
        INNER JOIN service_requests r ON r.id = b.request_id
        INNER JOIN provider_profiles pp ON pp.id = b.provider_id
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN reviews rv ON rv.booking_id = b.id
        WHERE ' . implode(' AND ', $conditions) . '
        ORDER BY b.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();
$bookings = array_map(static fn(array $booking): array => decryptSensitiveFields($booking, ['provider_phone']), $bookings);

$pageTitle = 'My Bookings | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Customer Workspace</span>
                <h1 class="mb-0">My Bookings</h1>
                <p class="text-muted mb-0">Track and manage your scheduled service appointments.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="my_requests.php" class="btn btn-outline-primary rounded-pill">View Requests</a>
                <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
            </div>
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
                <i class="bi bi-calendar-x fs-1 text-muted"></i>
                <h2 class="h4 mt-3">No bookings found</h2>
                <p class="text-muted">
                    <?= $filterStatus !== '' ? 'No bookings match the selected status filter.' : 'You have not booked any services yet. Start by reviewing matches on your service requests.' ?>
                </p>
                <a href="my_requests.php" class="btn btn-primary rounded-pill">View My Requests</a>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Booking</th>
                                <th>Service & Provider</th>
                                <th>Service Request</th>
                                <th>Scheduled Appointment</th>
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
                                        <div class="fw-bold"><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted small">
                                            <i class="bi bi-person-workspace me-1"></i><?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="request_details.php?id=<?= (int)$booking['request_id'] ?>" class="text-decoration-none text-dark fw-semibold">
                                            <?= htmlspecialchars($booking['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                        <div class="text-muted small">
                                            <?= htmlspecialchars($booking['city'] . ($booking['area'] ? ', ' . $booking['area'] : ''), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div><i class="bi bi-calendar-event me-1"></i><?= htmlspecialchars($booking['scheduled_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="text-muted small"><i class="bi bi-clock me-1"></i><?= htmlspecialchars(substr((string)$booking['scheduled_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?= bookingStatusClass($booking['status']) ?>">
                                            <?= htmlspecialchars(bookingStatusLabel($booking['status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($booking['status'] === 'completed'): ?>
                                            <div class="mt-1">
                                                <?php if ($booking['review_id']): ?>
                                                    <span class="badge text-bg-light border text-success small">
                                                        <i class="bi bi-patch-check-fill me-1"></i>Reviewed (<?= (int)$booking['review_rating'] ?>★)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge text-bg-warning text-dark small">
                                                        <i class="bi bi-star me-1"></i>Unreviewed
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-inline-flex gap-1">
                                            <a href="booking_details.php?id=<?= (int)$booking['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                Details
                                            </a>
                                            <?php if ($booking['status'] === 'completed' && !$booking['review_id']): ?>
                                                <a href="review_booking.php?booking_id=<?= (int)$booking['id'] ?>" class="btn btn-sm btn-success">
                                                    <i class="bi bi-star-fill me-1"></i>Review
                                                </a>
                                            <?php endif; ?>
                                        </div>
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
