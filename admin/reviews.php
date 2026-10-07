<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../config/database.php';

requireAdmin();

$pdo = getDatabaseConnection();
$adminId = (int)getUserId();
$message = '';
$error = '';

// Check if moderation columns exist
$hasStatusCol = true;
try {
    $colCheck = $pdo->query("SHOW COLUMNS FROM reviews LIKE 'status'");
    if ($colCheck && !$colCheck->fetch()) {
        $hasStatusCol = false;
    }
} catch (Throwable) {
    $hasStatusCol = false;
}

// Handle Moderation Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $reviewId = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);
    $action = (string)($_POST['action'] ?? '');
    $note = trim((string)($_POST['moderation_note'] ?? ''));

    if (!$reviewId || !in_array($action, ['publish', 'hide'], true)) {
        $error = 'Invalid moderation action.';
    } elseif (!validateModerationNote($note)) {
        $error = 'Moderation note cannot exceed 2000 characters.';
    } elseif (!$hasStatusCol) {
        $error = 'Review status column is not present. Run database/phase9_reviews.sql to enable moderation.';
    } else {
        $newStatus = $action === 'hide' ? 'hidden' : 'published';
        try {
            $oldStmt = $pdo->prepare('SELECT status FROM reviews WHERE id = :id LIMIT 1');
            $oldStmt->execute(['id' => $reviewId]);
            $oldStatus = $oldStmt->fetchColumn();
            $stmt = $pdo->prepare(
                'UPDATE reviews 
                 SET status = :status, 
                     moderation_note = :note, 
                     moderated_by = :moderator, 
                     moderated_at = CURRENT_TIMESTAMP 
                 WHERE id = :id'
            );
            $stmt->execute([
                'status' => $newStatus,
                'note' => $note !== '' ? $note : null,
                'moderator' => $adminId,
                'id' => $reviewId,
            ]);
            if ($oldStatus !== false) {
                writeAuditLog($pdo, 'review_moderated', 'review', (int)$reviewId, ['status' => $oldStatus], ['status' => $newStatus, 'moderation_note_set' => $note !== '']);
                $message = 'Review #' . $reviewId . ' status updated to ' . $newStatus . '.';
            } else {
                $error = 'The selected review was not found.';
            }
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $error = 'Failed to update review status.';
        }
    }
}

// Filter
$filter = (string)($_GET['status'] ?? '');
$where = [];
$params = [];

if ($hasStatusCol && in_array($filter, ['published', 'hidden'], true)) {
    $where[] = 'r.status = :status';
    $params['status'] = $filter;
} else {
    $filter = '';
}

$statusSelect = $hasStatusCol ? 'r.status, r.moderation_note, r.moderated_at, mod_u.name AS moderator_name,' : "'published' AS status, NULL AS moderation_note, NULL AS moderated_at, NULL AS moderator_name,";
$modJoin = $hasStatusCol ? 'LEFT JOIN users mod_u ON mod_u.id = r.moderated_by' : '';

$sql = "SELECT r.id, r.booking_id, r.customer_id, r.provider_id, r.rating, r.review, r.created_at,
               {$statusSelect}
               cu.name AS customer_name, cu.email AS customer_email,
               pp.business_name,
               s.service_name,
               req.title AS request_title
        FROM reviews r
        INNER JOIN users cu ON cu.id = r.customer_id
        INNER JOIN provider_profiles pp ON pp.id = r.provider_id
        INNER JOIN bookings b ON b.id = r.booking_id
        LEFT JOIN services s ON s.id = b.service_id
        LEFT JOIN service_requests req ON req.id = b.request_id
        {$modJoin}" .
        (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '') .
        ' ORDER BY r.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll();

$pageTitle = 'Review Moderation | ServeIQ Admin';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Platform Administration</span>
                <h1 class="mb-1">Review Moderation</h1>
                <p class="text-muted mb-0">Audit verified customer reviews, inspect service linkages, and moderate visibility.</p>
            </div>
            <a href="dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success rounded-4 mb-4">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-4 mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Filter tabs -->
        <?php if ($hasStatusCol): ?>
            <div class="card shadow-sm border-0 rounded-4 p-3 mb-4">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <span class="text-muted small fw-bold me-2">Filter:</span>
                    <a href="reviews.php" class="btn btn-sm <?= $filter === '' ? 'btn-dark' : 'btn-outline-secondary' ?>">All Reviews</a>
                    <a href="reviews.php?status=published" class="btn btn-sm <?= $filter === 'published' ? 'btn-dark' : 'btn-outline-secondary' ?>">Published</a>
                    <a href="reviews.php?status=hidden" class="btn btn-sm <?= $filter === 'hidden' ? 'btn-dark' : 'btn-outline-secondary' ?>">Hidden / Moderated</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($reviews)): ?>
            <div class="card shadow-sm border-0 rounded-4 p-4 text-center text-muted">
                <i class="bi bi-chat-square-text fs-1 mb-2"></i>
                <h2 class="h5">No reviews found</h2>
                <p class="mb-0">There are currently no customer reviews matching your criteria.</p>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Rating</th>
                                <th>Customer</th>
                                <th>Provider & Service</th>
                                <th>Review Text</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reviews as $rev): ?>
                                <tr>
                                    <td>
                                        <strong>#<?= (int)$rev['id'] ?></strong>
                                        <div class="text-muted small">Bk #<?= (int)$rev['booking_id'] ?></div>
                                    </td>
                                    <td>
                                        <div><?= renderStarRating((float)$rev['rating']) ?></div>
                                        <strong><?= (int)$rev['rating'] ?>/5</strong>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($rev['customer_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($rev['customer_email'], ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($rev['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($rev['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td style="max-width: 300px;">
                                        <?php if (!empty($rev['review'])): ?>
                                            <p class="small mb-0 text-truncate"><?= htmlspecialchars($rev['review'], ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">No written comment</span>
                                        <?php endif; ?>
                                        <?php if (!empty($rev['moderation_note'])): ?>
                                            <div class="text-danger small mt-1">
                                                <strong>Mod Note:</strong> <?= htmlspecialchars($rev['moderation_note'], ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($rev['status'] === 'hidden'): ?>
                                            <span class="badge text-bg-danger">Hidden</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-success">Published</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars(date('M j, Y', strtotime($rev['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($hasStatusCol): ?>
                                            <form method="POST" action="reviews.php" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="review_id" value="<?= (int)$rev['id'] ?>">
                                                <label class="visually-hidden" for="moderation-note-<?= (int)$rev['id'] ?>">Optional moderation note</label>
                                                <input id="moderation-note-<?= (int)$rev['id'] ?>" type="text" name="moderation_note" class="form-control form-control-sm mb-2" maxlength="2000" placeholder="Optional note">
                                                <?php if ($rev['status'] === 'hidden'): ?>
                                                    <input type="hidden" name="action" value="publish">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                                        <i class="bi bi-eye me-1"></i>Publish
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="action" value="hide">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-eye-slash me-1"></i>Hide
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small">No action</span>
                                        <?php endif; ?>
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
