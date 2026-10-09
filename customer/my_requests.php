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
$search = trim((string)($_GET['search'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$urgency = (string)($_GET['urgency'] ?? '');
$categoryId = (string)($_GET['category_id'] ?? '');
$allowedStatuses = ['draft', 'submitted', 'analyzing', 'matched', 'in_progress', 'completed', 'cancelled'];
$allowedUrgencies = ['low', 'medium', 'high', 'emergency'];
$conditions = ['r.customer_id = :customer_id'];
$params = ['customer_id' => $customerId];

if ($search !== '') {
    $conditions[] = 'r.title LIKE :search';
    $params['search'] = '%' . $search . '%';
}
if (in_array($status, $allowedStatuses, true)) {
    $conditions[] = 'r.status = :status';
    $params['status'] = $status;
} else {
    $status = '';
}
if (in_array($urgency, $allowedUrgencies, true)) {
    $conditions[] = 'r.urgency = :urgency';
    $params['urgency'] = $urgency;
} else {
    $urgency = '';
}
if ($categoryId !== '' && ctype_digit($categoryId)) {
    $conditions[] = 'r.category_id = :category_id';
    $params['category_id'] = (int)$categoryId;
} else {
    $categoryId = '';
}

$sql = 'SELECT r.*, COALESCE(c.category_name, fc.category_name) AS category_name, b.id AS booking_id, b.status AS booking_status
        FROM service_requests r 
        LEFT JOIN service_categories c ON c.id = r.category_id 
        LEFT JOIN problem_fingerprints f ON f.request_id = r.id
        LEFT JOIN service_categories fc ON fc.id = f.detected_category_id
        LEFT JOIN bookings b ON b.request_id = r.id
        WHERE ' . implode(' AND ', $conditions) . ' 
        ORDER BY r.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();
$categories = $pdo->query('SELECT id, category_name FROM service_categories ORDER BY category_name ASC')->fetchAll();

$pageTitle = 'My Requests | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div><span class="section-kicker">Customer Workspace</span><h1 class="mb-0">My Requests</h1></div>
            <div class="d-flex gap-2"><a href="create_request.php" class="btn btn-primary rounded-pill">Describe New Problem</a><a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a></div>
        </div>

        <div class="card shadow-sm border-0 rounded-4 p-3 p-md-4 mb-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-4"><label for="search" class="form-label">Search title</label><input type="search" id="search" name="search" class="form-control" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search your requests"></div>
                <div class="col-md-4 col-lg-2"><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-select"><option value="">All statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>><?= htmlspecialchars(requestStatusLabel($option), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 col-lg-2"><label for="urgency" class="form-label">Urgency</label><select id="urgency" name="urgency" class="form-select"><option value="">All urgency</option><?php foreach ($allowedUrgencies as $option): ?><option value="<?= $option ?>" <?= $urgency === $option ? 'selected' : '' ?>><?= htmlspecialchars(requestUrgencyLabel($option), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="col-md-4 col-lg-2"><label for="category_id" class="form-label">Category</label><select id="category_id" name="category_id" class="form-select"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $categoryId === (string)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="col-lg-2 d-flex gap-2"><button type="submit" class="btn btn-dark flex-grow-1">Filter</button><a href="my_requests.php" class="btn btn-outline-secondary">Reset</a></div>
            </form>
        </div>

        <?php if (empty($requests)): ?>
            <div class="empty-state-card text-center"><i class="bi bi-inbox fs-1 text-muted"></i><h2 class="h4 mt-3">No requests found</h2><p class="text-muted">Start with a clear description of the problem you need help with.</p><a href="create_request.php" class="btn btn-primary rounded-pill">Describe Your Problem</a></div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($requests as $request): ?>
                    <div class="col-12">
                        <div class="request-list-card">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div>
                                    <h2 class="h5 mb-2"><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                                    <p class="text-muted mb-2"><?= htmlspecialchars($request['category_name'] ?: 'Service category being analyzed', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($request['city'], ENT_QUOTES, 'UTF-8') ?><?= $request['area'] ? ' · ' . htmlspecialchars($request['area'], ENT_QUOTES, 'UTF-8') : '' ?></p>
                                    <small class="text-muted">Created <?= htmlspecialchars(date('M j, Y g:i A', strtotime($request['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <?php if ($request['booking_id']): ?>
                                        <span class="badge <?= bookingStatusClass($request['booking_status']) ?>">
                                            Booking: <?= htmlspecialchars(bookingStatusLabel($request['booking_status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="badge <?= requestStatusClass($request['status']) ?>"><?= htmlspecialchars(requestStatusLabel($request['status']), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="badge <?= requestUrgencyClass($request['urgency']) ?>"><?= htmlspecialchars(requestUrgencyLabel($request['urgency']), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <div class="mt-3 d-flex gap-2 flex-wrap">
                                <a href="request_details.php?id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-outline-primary">View Details</a>
                                <?php if ($request['booking_id']): ?>
                                    <a href="booking_details.php?id=<?= (int)$request['booking_id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="bi bi-calendar2-check me-1"></i>View Booking
                                    </a>
                                <?php elseif (!in_array($request['status'], ['cancelled', 'completed'], true)): ?>
                                    <a href="matches.php?id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-calendar-plus me-1"></i>Find Provider to Book
                                    </a>
                                <?php endif; ?>
                                <?php if (in_array($request['status'], ['draft', 'submitted'], true) && !$request['booking_id']): ?>
                                    <a href="edit_request.php?id=<?= (int)$request['id'] ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
