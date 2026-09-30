<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../services/service_dna.php';
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
$serviceDna = getServiceDnaForRequest($pdo, (int)$requestId);
$rankedProviders = getRankedProvidersForRequest($pdo, (int)$requestId, (int)getUserId());
$adcsSummary = getADCSForRequest($pdo, (int)$requestId, (int)getUserId());
$booking = findBookingForRequest($pdo, (int)$requestId);

$imageStmt = $pdo->prepare('SELECT id, original_name, stored_name, mime_type FROM request_images WHERE request_id = :request_id ORDER BY id ASC');
$imageStmt->execute(['request_id' => (int)$requestId]);
$images = $imageStmt->fetchAll();
$created = isset($_GET['created']);

$canBook = !$booking && !in_array($request['status'], ['cancelled', 'completed'], true);

$pageTitle = 'Request Details | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Customer Workspace</span>
                <h1 class="mb-0">Request Details</h1>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="my_requests.php" class="btn btn-outline-secondary">My Requests</a>
                <?php if (in_array($request['status'], ['draft', 'submitted'], true) && !$booking): ?>
                    <a href="edit_request.php?id=<?= (int)$request['id'] ?>" class="btn btn-outline-primary">Edit Request</a>
                <?php endif; ?>
                <?php if ($booking): ?>
                    <a href="booking_details.php?id=<?= (int)$booking['id'] ?>" class="btn btn-primary">
                        <i class="bi bi-calendar2-check me-1"></i>View Booking #<?= (int)$booking['id'] ?>
                    </a>
                <?php elseif ($canBook && !empty($rankedProviders)): ?>
                    <a href="matches.php?id=<?= (int)$requestId ?>" class="btn btn-primary">
                        <i class="bi bi-calendar-plus me-1"></i>Book a Provider
                    </a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
            </div>
        </div>

        <?php if ($created): ?>
            <div class="alert alert-success">Your problem request was submitted successfully.</div>
        <?php endif; ?>
        <?php if (isset($_GET['cancelled'])): ?>
            <div class="alert alert-success">Your request was cancelled. Its history remains available here.</div>
        <?php endif; ?>
        <?php if (isset($_GET['cancel_error'])): ?>
            <div class="alert alert-warning">This request could not be cancelled because its status no longer allows cancellation.</div>
        <?php endif; ?>

        <!-- Active Booking Banner if exists -->
        <?php if ($booking): ?>
            <div class="card shadow-sm border-0 border-start border-4 border-primary rounded-4 p-4 mb-4 bg-light">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <span class="section-kicker">Phase 8 · Active Booking</span>
                        <h2 class="h5 mb-1">Booked with <?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="text-muted mb-0">
                            Service: <strong><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></strong> · 
                            Scheduled: <strong><?= htmlspecialchars($booking['scheduled_date'] . ' ' . substr((string)$booking['scheduled_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?></strong>
                        </p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?= bookingStatusClass($booking['status']) ?> fs-6">
                            <?= htmlspecialchars(bookingStatusLabel($booking['status']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <a href="booking_details.php?id=<?= (int)$booking['id'] ?>" class="btn btn-primary">
                            Manage Booking
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Request Details Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <div>
                    <h2 class="mb-2"><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="text-muted mb-0">Submitted <?= htmlspecialchars(date('M j, Y g:i A', strtotime($request['created_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="badge <?= requestStatusClass($request['status']) ?>"><?= htmlspecialchars(requestStatusLabel($request['status']), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="badge <?= requestUrgencyClass($request['urgency']) ?>"><?= htmlspecialchars(requestUrgencyLabel($request['urgency']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <hr>
            <div class="row g-4">
                <div class="col-md-6">
                    <h3 class="h6 text-uppercase text-muted">Description</h3>
                    <p class="request-description"><?= nl2br(htmlspecialchars($request['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
                <div class="col-md-6">
                    <h3 class="h6 text-uppercase text-muted">Service Information</h3>
                    <p class="mb-2"><strong>Category:</strong> <?= htmlspecialchars($request['category_name'] ?: 'Not selected', ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-2"><strong>Contact:</strong> <?= htmlspecialchars(ucfirst($request['contact_preference']), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-2"><strong>Location:</strong> <?= htmlspecialchars($request['city'], ENT_QUOTES, 'UTF-8') ?><?= $request['area'] ? ', ' . htmlspecialchars($request['area'], ENT_QUOTES, 'UTF-8') : '' ?></p>
                    <?php if ($request['address']): ?>
                        <p class="mb-2"><strong>Address:</strong> <?= htmlspecialchars($request['address'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <p class="mb-0"><strong>Updated:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($request['updated_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <!-- ServiceDNA Card -->
        <?php if ($serviceDna): ?>
            <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <span class="section-kicker">Phase 10 · ServiceDNA</span>
                        <h2 class="h4 mb-1">Diagnostic Understanding</h2>
                        <p class="text-muted mb-0">Advisory service suggestions derived from your description.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <?php if (!empty($serviceDna['ai_used'])): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small">
                                <i class="bi bi-cpu me-1"></i>AI Enhanced
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border px-2 py-1 small">
                                <i class="bi bi-shield-check me-1"></i>Baseline
                            </span>
                        <?php endif; ?>
                        <a href="service_dna.php?id=<?= (int)$requestId ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-search me-1"></i>Full Analysis
                        </a>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <strong>Category:</strong> <?= htmlspecialchars($serviceDna['category_name'] ?: 'Unclassified', ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Problem type:</strong> <?= htmlspecialchars($serviceDna['problem_type'] ?: 'Not recognized', ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Affected entity:</strong> <?= htmlspecialchars($serviceDna['affected_entity'] ?: 'Not recognized', ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Symptoms:</strong> <?= htmlspecialchars(implode(', ', $serviceDna['symptoms']) ?: 'None detected', ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Context:</strong> <?= htmlspecialchars(implode(', ', $serviceDna['context']) ?: 'None detected', ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Confidence:</strong>
                        <span class="fw-bold text-primary"><?= (int)$serviceDna['confidence_score'] ?>%</span>
                        <?php if (!empty($serviceDna['ai_used'])): ?>
                            <span class="text-muted small">(Det: <?= (int)($serviceDna['deterministic_confidence'] ?? $serviceDna['confidence_score']) ?>%, AI: <?= (int)($serviceDna['ai_confidence'] ?? 0) ?>%)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($serviceDna['follow_up_questions'])): ?>
                    <div class="alert alert-info mt-3 mb-0 small">
                        <strong><i class="bi bi-question-circle me-1"></i>Helpful diagnostic questions for your provider:</strong>
                        <ul class="mb-0 mt-1 ps-3">
                            <?php foreach (array_slice($serviceDna['follow_up_questions'], 0, 2) as $q): ?>
                                <li><?= htmlspecialchars((string)$q, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (count($serviceDna['follow_up_questions']) > 2): ?>
                            <p class="mb-0 mt-1"><a href="service_dna.php?id=<?= (int)$requestId ?>">View all questions →</a></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ADCS Consensus Card -->
        <?php if ($adcsSummary): ?>
            <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                    <div>
                        <span class="section-kicker">Phase 7</span>
                        <h2 class="h4 mb-1">Provider Assessment Consensus</h2>
                        <p class="text-muted mb-0">Compare independent preliminary assessments. This is decision support, not a final diagnosis.</p>
                    </div>
                    <a href="adcs.php?id=<?= (int)$requestId ?>" class="btn btn-outline-primary">View Consensus Details</a>
                </div>
                <hr>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="badge text-bg-primary fs-6"><?= htmlspecialchars((string)$adcsSummary['consensus_score'], ENT_QUOTES, 'UTF-8') ?>/100 Consensus Score</span>
                    <span class="badge text-bg-secondary fs-6"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$adcsSummary['consensus_status'])), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="text-muted"><?= (int)$adcsSummary['assessment_count'] ?> independent provider assessment(s) evaluated</span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recommended Providers Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <div>
                    <span class="section-kicker">Phase 6</span>
                    <h2 class="h4 mb-1">Recommended Service Providers</h2>
                    <p class="text-muted mb-0">Ranked using category, technical, location, and provider-quality evidence.</p>
                </div>
                <a href="matches.php?id=<?= (int)$requestId ?>" class="btn btn-outline-primary">View All Matches</a>
            </div>
            <?php if (empty($rankedProviders)): ?>
                <p class="text-muted mt-3 mb-0">No strong provider match was found yet.</p>
            <?php else: ?>
                <div class="row g-3 mt-1">
                    <?php foreach (array_slice($rankedProviders, 0, 3) as $provider): ?>
                        <div class="col-lg-4">
                            <div class="border rounded-3 p-3 h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between gap-2">
                                        <h3 class="h6 mb-1"><?= htmlspecialchars($provider['business_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <strong><?= htmlspecialchars((string)$provider['score'], ENT_QUOTES, 'UTF-8') ?>/100</strong>
                                    </div>
                                    <p class="small text-muted mb-2"><?= htmlspecialchars($provider['city'], ENT_QUOTES, 'UTF-8') ?><?= $provider['area'] ? ' · ' . htmlspecialchars($provider['area'], ENT_QUOTES, 'UTF-8') : '' ?></p>
                                    <p class="small mb-3"><?= htmlspecialchars($provider['reasons'][0] ?? 'Evidence-based provider match', ENT_QUOTES, 'UTF-8') ?></p>
                                </div>
                                <div class="d-flex gap-2 pt-2 border-top">
                                    <a href="provider_details.php?id=<?= (int)$provider['provider_id'] ?>&request_id=<?= (int)$requestId ?>" class="btn btn-sm btn-outline-primary flex-grow-1">Profile</a>
                                    <?php if ($canBook): ?>
                                        <a href="create_booking.php?request_id=<?= (int)$requestId ?>&provider_id=<?= (int)$provider['provider_id'] ?>" class="btn btn-sm btn-primary flex-grow-1">Book</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Supporting Images Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
            <h2 class="h4 mb-3">Supporting Images</h2>
            <?php if (empty($images)): ?>
                <p class="text-muted mb-0">No supporting images were uploaded.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($images as $image): ?>
                        <div class="col-6 col-md-4 col-lg-3">
                            <a href="../uploads/requests/<?= rawurlencode(basename($image['stored_name'])) ?>" target="_blank" rel="noopener">
                                <img src="../uploads/requests/<?= rawurlencode(basename($image['stored_name'])) ?>" alt="<?= htmlspecialchars($image['original_name'], ENT_QUOTES, 'UTF-8') ?>" class="img-fluid rounded-3 border request-image">
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Cancel Request Modal (if applicable) -->
        <?php if (!in_array($request['status'], ['cancelled', 'completed'], true) && !$booking): ?>
            <div class="card shadow-sm border-0 rounded-4 p-4">
                <h2 class="h5">Need to stop this request?</h2>
                <p class="text-muted">Cancellation keeps the history but prevents the request from moving forward.</p>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelRequestModal">Cancel Request</button>
            </div>
            <div class="modal fade" id="cancelRequestModal" tabindex="-1" aria-labelledby="cancelRequestModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title h5" id="cancelRequestModalLabel">Cancel this request?</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">Your request will remain in your history, but it will no longer move forward.</div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Request</button>
                            <form method="POST" action="cancel_request.php">
                                <?= csrfField() ?>
                                <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                                <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
