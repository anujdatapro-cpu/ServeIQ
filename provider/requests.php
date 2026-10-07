<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireProvider();

$pdo = getDatabaseConnection();
$userId = (int)getUserId();

$profileStmt = $pdo->prepare('SELECT id, business_name, availability_status FROM provider_profiles WHERE user_id = :user_id LIMIT 1');
$profileStmt->execute(['user_id' => $userId]);
$providerProfile = $profileStmt->fetch();

if (!$providerProfile) {
    http_response_code(403);
    exit('Please complete your service provider business profile first.');
}

$providerId = (int)$providerProfile['id'];
$tab = (string)($_GET['tab'] ?? 'bookings');
if (!in_array($tab, ['bookings', 'matched'], true)) {
    $tab = 'bookings';
}

// 1. Incoming & active bookings for this provider
$bookingsStmt = $pdo->prepare(
    "SELECT b.id, b.status, b.scheduled_date, b.scheduled_time, b.created_at, b.notes,
            r.id AS request_id, r.title, r.description, r.city, r.area, r.urgency,
            s.service_name, s.base_price,
            cu.name AS customer_name, cu.email AS customer_email
     FROM bookings b
     INNER JOIN service_requests r ON r.id = b.request_id
     INNER JOIN users cu ON cu.id = b.customer_id
     LEFT JOIN services s ON s.id = b.service_id
     WHERE b.provider_id = :provider_id
     ORDER BY 
        CASE b.status
            WHEN 'pending' THEN 1
            WHEN 'accepted' THEN 2
            WHEN 'in_progress' THEN 3
            ELSE 4
        END ASC,
        b.created_at DESC"
);
$bookingsStmt->execute(['provider_id' => $providerId]);
$bookings = $bookingsStmt->fetchAll();

// 2. Matched service requests in provider's domain
$matchedStmt = $pdo->prepare(
    "SELECT mr.request_id, mr.match_score, mr.ranking_position, mr.match_reasons,
            r.title, r.description, r.city, r.area, r.urgency, r.status AS request_status, r.created_at,
            c.category_name,
            f.problem_type, f.affected_entity, f.symptoms, f.confidence_score,
            pa.status AS assessment_status
     FROM matching_results mr
     INNER JOIN service_requests r ON r.id = mr.request_id
     LEFT JOIN service_categories c ON c.id = r.category_id
     LEFT JOIN problem_fingerprints f ON f.request_id = r.id
     LEFT JOIN provider_assessments pa ON pa.request_id = mr.request_id AND pa.provider_id = :assessment_provider_id
     WHERE mr.provider_id = :provider_id 
       AND mr.matching_method = 'weighted_rule_based_v1' 
       AND mr.version = 1
       AND r.status NOT IN ('cancelled', 'completed')
     ORDER BY mr.match_score DESC, r.created_at DESC"
);
$matchedStmt->execute(['provider_id' => $providerId, 'assessment_provider_id' => $providerId]);
$matchedRequests = $matchedStmt->fetchAll();

$pendingBookingsCount = count(array_filter($bookings, fn($b) => $b['status'] === 'pending'));

$pageTitle = 'Incoming Requests & Bookings | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Provider Workspace</span>
                <h1 class="mb-1">Customer Requests &amp; Bookings</h1>
                <p class="text-muted mb-0">Review incoming customer bookings and explore domain requests matched with your profile.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
                <a href="bookings.php" class="btn btn-outline-primary">All Bookings</a>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-pills mb-4 gap-2">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'bookings' ? 'active' : '' ?>" href="requests.php?tab=bookings">
                    <i class="bi bi-calendar-check me-1"></i>Direct Customer Bookings
                    <?php if ($pendingBookingsCount > 0): ?>
                        <span class="badge text-bg-warning ms-1"><?= $pendingBookingsCount ?> Pending</span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'matched' ? 'active' : '' ?>" href="requests.php?tab=matched">
                    <i class="bi bi-diagram-3 me-1"></i>Matched Domain Requests
                    <span class="badge text-bg-secondary ms-1"><?= count($matchedRequests) ?></span>
                </a>
            </li>
        </ul>

        <?php if ($tab === 'bookings'): ?>
            <!-- TAB 1: Direct Customer Bookings -->
            <?php if (empty($bookings)): ?>
                <div class="empty-state-card text-center p-5 card border-0 shadow-sm rounded-4">
                    <i class="bi bi-inbox fs-1 text-muted mb-3"></i>
                    <h2 class="h4">No direct bookings yet</h2>
                    <p class="text-muted mb-3">When customers select your business from intelligent matches, their booking requests appear here for your review and confirmation.</p>
                    <a href="requests.php?tab=matched" class="btn btn-primary rounded-pill">Explore Matched Requests</a>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($bookings as $b): ?>
                        <?php
                            $dna = getServiceDnaForRequest($pdo, (int)$b['request_id']);
                        ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm rounded-4 p-4 <?= $b['status'] === 'pending' ? 'border-start border-4 border-warning' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge <?= bookingStatusClass($b['status']) ?> fs-6">
                                                <?= htmlspecialchars(bookingStatusLabel($b['status']), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <span class="text-muted small">Booking #<?= (int)$b['id'] ?></span>
                                            <span class="text-muted small">· Created <?= htmlspecialchars(date('M j, Y g:i A', strtotime($b['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <h2 class="h5 mb-1"><?= htmlspecialchars($b['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                                        <div class="text-muted small">
                                            <i class="bi bi-person me-1"></i>Customer: <strong><?= htmlspecialchars($b['customer_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            · <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($b['city'] . ($b['area'] ? ', ' . $b['area'] : ''), ENT_QUOTES, 'UTF-8') ?>
                                            · <span class="badge <?= requestUrgencyClass($b['urgency']) ?>"><?= htmlspecialchars(requestUrgencyLabel($b['urgency']), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold fs-6 text-primary"><?= htmlspecialchars($b['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if ($b['base_price'] !== null): ?>
                                            <div class="text-muted small">Listed Rate: ₹<?= number_format((float)$b['base_price'], 2) ?></div>
                                        <?php endif; ?>
                                        <div class="small fw-semibold mt-1">
                                            <i class="bi bi-calendar-event me-1 text-primary"></i><?= htmlspecialchars($b['scheduled_date'], ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(substr((string)$b['scheduled_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                </div>

                                <hr class="my-2">

                                <div class="row g-3 py-2">
                                    <div class="col-md-7">
                                        <strong class="small text-muted text-uppercase d-block mb-1">Original Customer Problem:</strong>
                                        <p class="small text-dark mb-2"><?= nl2br(htmlspecialchars($b['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                                        <?php if (!empty($b['notes'])): ?>
                                            <div class="p-2 bg-light rounded-2 border small">
                                                <strong>Customer appointment note:</strong> <?= nl2br(htmlspecialchars($b['notes'], ENT_QUOTES, 'UTF-8')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-5">
                                        <strong class="small text-muted text-uppercase d-block mb-1">ServiceDNA Understanding:</strong>
                                        <?php if ($dna): ?>
                                            <div class="p-2 bg-light rounded-2 border small">
                                                <div><strong>Problem:</strong> <?= htmlspecialchars($dna['problem_type'] ?: 'Technical Request', ENT_QUOTES, 'UTF-8') ?></div>
                                                <div><strong>Entity:</strong> <?= htmlspecialchars($dna['affected_entity'] ?: 'General', ENT_QUOTES, 'UTF-8') ?></div>
                                                <div><strong>Symptoms:</strong> <?= htmlspecialchars(implode(', ', $dna['symptoms']) ?: 'None isolated', ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-muted mt-1">Confidence: <?= (int)$dna['confidence_score'] ?>%</div>
                                            </div>
                                        <?php else: ?>
                                            <p class="small text-muted mb-0">No ServiceDNA recorded.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-3 border-top mt-2">
                                    <div class="d-flex gap-2">
                                        <a href="booking_details.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            Manage Booking
                                        </a>
                                        <a href="../customer/booking_receipt.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-file-earmark-pdf me-1"></i>Receipt
                                        </a>
                                    </div>
                                    <?php if ($b['status'] === 'pending'): ?>
                                        <div class="d-flex gap-2">
                                            <form method="POST" action="booking_details.php?id=<?= (int)$b['id'] ?>" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="accept">
                                                <button type="submit" class="btn btn-sm btn-primary">
                                                    <i class="bi bi-check-circle me-1"></i>Accept Appointment
                                                </button>
                                            </form>
                                            <a href="booking_details.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-danger">
                                                Decline
                                            </a>
                                        </div>
                                    <?php elseif ($b['status'] === 'accepted'): ?>
                                        <form method="POST" action="booking_details.php?id=<?= (int)$b['id'] ?>" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="start">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="bi bi-play-circle me-1"></i>Start Service
                                            </button>
                                        </form>
                                    <?php elseif ($b['status'] === 'in_progress'): ?>
                                        <form method="POST" action="booking_details.php?id=<?= (int)$b['id'] ?>" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="complete">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-all me-1"></i>Complete Service
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- TAB 2: Matched Domain Requests -->
            <?php if (empty($matchedRequests)): ?>
                <div class="empty-state-card text-center p-5 card border-0 shadow-sm rounded-4">
                    <i class="bi bi-diagram-3 fs-1 text-muted mb-3"></i>
                    <h2 class="h4">No active matched requests</h2>
                    <p class="text-muted mb-0">When customer problem descriptions match your services in this area, they will appear here with diagnostic details.</p>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <?php foreach ($matchedRequests as $mr): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm rounded-4 p-4">
                                <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-2">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge bg-primary text-white">Match <?= round((float)$mr['match_score']) ?>%</span>
                                            <span class="badge text-bg-light border"><?= htmlspecialchars((string)($mr['category_name'] ?? 'General'), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="text-muted small">Submitted <?= htmlspecialchars(date('M j, Y', strtotime($mr['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <h2 class="h5 mb-1"><?= htmlspecialchars($mr['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                                        <div class="text-muted small">
                                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($mr['city'] . ($mr['area'] ? ', ' . $mr['area'] : ''), ENT_QUOTES, 'UTF-8') ?>
                                            · <span class="badge <?= requestUrgencyClass($mr['urgency']) ?>"><?= htmlspecialchars(requestUrgencyLabel($mr['urgency']), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                    <div>
                                        <?php if ($mr['assessment_status']): ?>
                                            <span class="badge text-bg-success"><i class="bi bi-check2-circle me-1"></i>Assessed (<?= ucfirst($mr['assessment_status']) ?>)</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Assessment Pending</span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <p class="small text-secondary mb-3"><?= nl2br(htmlspecialchars($mr['description'], ENT_QUOTES, 'UTF-8')) ?></p>

                                <?php if (!empty($mr['problem_type'])): ?>
                                    <div class="p-2 bg-light rounded-2 border small mb-3">
                                        <strong>ServiceDNA:</strong> <?= htmlspecialchars($mr['problem_type'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if (!empty($mr['affected_entity'])): ?> · Entity: <?= htmlspecialchars($mr['affected_entity'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                        · Confidence: <?= (int)$mr['confidence_score'] ?>%
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                                    <a href="assessments.php?request_id=<?= (int)$mr['request_id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-clipboard2-pulse me-1"></i><?= $mr['assessment_status'] ? 'Update Assessment' : 'Submit Independent Assessment' ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
