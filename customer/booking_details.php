<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$customerId = (int)getUserId();
$bookingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$bookingId || $bookingId < 1) {
    http_response_code(404);
    exit('Booking not found.');
}

$booking = findCustomerBooking($pdo, $bookingId, $customerId);
if (!$booking) {
    http_response_code(404);
    exit('Booking not found or access denied.');
}

$error = '';
$success = '';

if (isset($_GET['created'])) {
    $success = 'Your service booking was submitted successfully. The provider has been notified to review your request.';
} elseif (isset($_GET['cancelled'])) {
    $success = 'Your booking has been cancelled.';
} elseif (isset($_GET['reviewed'])) {
    $success = 'Thank you! Your verified service review has been published.';
} elseif (isset($_GET['already_reviewed'])) {
    $success = 'You have already reviewed this completed booking.';
}

// Handle Customer Cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'cancel') {
        if (!canUserTransitionBooking('customer', (string)$booking['status'], 'cancelled')) {
            $error = 'This booking cannot be cancelled in its current state (' . bookingStatusLabel((string)$booking['status']) . ').';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "UPDATE bookings 
                     SET status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP 
                     WHERE id = :id AND customer_id = :customer_id AND status IN ('pending', 'accepted')"
                );
                $stmt->execute(['id' => $bookingId, 'customer_id' => $customerId]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Booking could not be cancelled.');
                }

                recordBookingStatus($pdo, $bookingId, (string)$booking['status'], 'cancelled', $customerId, 'Cancelled by customer');
                writeAuditLog($pdo, 'booking_cancelled', 'booking', (int)$bookingId, ['status' => (string)$booking['status']], ['status' => 'cancelled']);

                $pdo->commit();
                header('Location: booking_details.php?id=' . $bookingId . '&cancelled=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Booking cancellation could not be processed right now.';
            }
        }
    }
}

// ServiceDNA summary
$dna = getServiceDnaForRequest($pdo, (int)$booking['request_id']);

// ADCS consensus summary
$adcs = null;
try {
    $adcs = getADCSForRequest($pdo, (int)$booking['request_id'], $customerId);
} catch (Throwable $e) {
    error_log('ADCS lookup notice: ' . $e->getMessage());
}

// Booking status history timeline
$history = getBookingStatusHistory($pdo, $bookingId);

// Review check for completed bookings
$review = findReviewForBooking($pdo, $bookingId);

$pageTitle = 'Booking #' . $bookingId . ' | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Phase 8 & 9 · Service Workflow</span>
                <h1 class="mb-1">Booking #<?= (int)$booking['id'] ?></h1>
                <p class="text-muted mb-0">Scheduled with <?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="booking_receipt.php?id=<?= (int)$booking['id'] ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-pdf me-1"></i>Download receipt</a>
                <a href="bookings.php" class="btn btn-outline-secondary">My Bookings</a>
                <a href="request_details.php?id=<?= (int)$booking['request_id'] ?>" class="btn btn-outline-primary">View Original Request</a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success rounded-4 mb-4">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-4 mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <!-- Visual Booking Lifecycle Stepper (PART J) -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <span class="section-kicker mb-1">Booking Lifecycle Progress</span>
            <?php
                $status = (string)$booking['status'];
                $isTerminalBad = in_array($status, ['cancelled', 'rejected'], true);
            ?>
            <?php if (!$isTerminalBad): ?>
                <div class="workflow-stepper">
                    <div class="step-connector">
                        <?php
                            $progressWidth = match ($status) {
                                'pending' => '0%',
                                'accepted' => '33.3%',
                                'in_progress' => '66.6%',
                                'completed' => '100%',
                                default => '0%',
                            };
                        ?>
                        <div class="step-connector-progress" style="width: <?= $progressWidth ?>;"></div>
                    </div>

                    <div class="workflow-step <?= $status === 'pending' ? 'active' : 'completed' ?>">
                        <div class="step-node"><i class="bi bi-send"></i></div>
                        <div class="step-title">1. Pending</div>
                    </div>

                    <div class="workflow-step <?= $status === 'accepted' ? 'active' : (in_array($status, ['in_progress', 'completed'], true) ? 'completed' : '') ?>">
                        <div class="step-node"><i class="bi bi-check2-circle"></i></div>
                        <div class="step-title">2. Accepted</div>
                    </div>

                    <div class="workflow-step <?= $status === 'in_progress' ? 'active' : ($status === 'completed' ? 'completed' : '') ?>">
                        <div class="step-node"><i class="bi bi-tools"></i></div>
                        <div class="step-title">3. In Progress</div>
                    </div>

                    <div class="workflow-step <?= $status === 'completed' ? 'active completed' : '' ?>">
                        <div class="step-node"><i class="bi bi-patch-check"></i></div>
                        <div class="step-title">4. Completed</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex align-items-center gap-3 p-3 rounded-3 <?= $status === 'cancelled' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-emphasis' ?> mt-2">
                    <i class="bi <?= $status === 'cancelled' ? 'bi-x-circle-fill' : 'bi-slash-circle-fill' ?> fs-3"></i>
                    <div>
                        <strong class="d-block">Booking <?= ucfirst($status) ?></strong>
                        <span class="small">This booking is in a terminal state (<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>). No further transitions can occur.</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Completed Booking Review Invitation or Display -->
        <?php if ($booking['status'] === 'completed'): ?>
            <?php if ($review): ?>
                <div class="card shadow-sm border-0 border-start border-4 border-success rounded-4 p-4 mb-4 bg-light">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                        <div>
                            <span class="section-kicker text-success">Phase 9 · Your Verified Review</span>
                            <h3 class="h5 mb-1">Your Rating for <?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        </div>
                        <div>
                            <span class="badge text-bg-success"><i class="bi bi-patch-check-fill me-1"></i>Reviewed ✓</span>
                        </div>
                    </div>
                    <div class="mb-2">
                        <span class="fs-5 me-2"><?= renderStarRating((float)$review['rating']) ?></span>
                        <strong><?= (int)$review['rating'] ?>/5 Stars</strong>
                        <span class="text-muted small ms-2">Submitted on <?= htmlspecialchars(date('M j, Y', strtotime($review['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <?php if (!empty($review['review'])): ?>
                        <p class="text-secondary mb-0 mt-2 p-3 bg-white rounded-3 border">
                            <?= nl2br(htmlspecialchars($review['review'], ENT_QUOTES, 'UTF-8')) ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="card shadow-sm border-0 border-start border-4 border-success rounded-4 p-4 mb-4 bg-light">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <span class="section-kicker text-success">Phase 9 · Service Completed</span>
                            <h3 class="h5 mb-1">How was your service experience?</h3>
                            <p class="text-muted mb-0">Your feedback helps fellow customers and builds verified provider trust.</p>
                        </div>
                        <a href="review_booking.php?booking_id=<?= (int)$booking['id'] ?>" class="btn btn-success px-4">
                            <i class="bi bi-star-fill me-1"></i>Rate & Review
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- Booking Status & Appointment Details -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 h-100">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <span class="text-muted small text-uppercase">Service Item</span>
                            <h2 class="h4 mb-1"><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></h2>
                            <?php if ($booking['base_price'] !== null): ?>
                                <p class="text-muted small mb-0">Base rate: ₹<?= htmlspecialchars(number_format((float)$booking['base_price'], 2), ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <span class="badge <?= bookingStatusClass($booking['status']) ?> fs-6 px-3 py-2">
                                <?= htmlspecialchars(bookingStatusLabel($booking['status']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    </div>

                    <hr>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="text-muted small">Appointment Date</label>
                            <div class="fw-bold fs-5">
                                <i class="bi bi-calendar-event me-2 text-primary"></i><?= htmlspecialchars($booking['scheduled_date'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <label class="text-muted small">Appointment Time</label>
                            <div class="fw-bold fs-5">
                                <i class="bi bi-clock me-2 text-primary"></i><?= htmlspecialchars(substr((string)$booking['scheduled_time'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($booking['notes'])): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Your Notes to Provider</label>
                            <div class="bg-light p-3 rounded-3 text-secondary">
                                <?= nl2br(htmlspecialchars($booking['notes'], ENT_QUOTES, 'UTF-8')) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($booking['status'] === 'rejected' && !empty($booking['rejection_reason'])): ?>
                        <div class="alert alert-warning rounded-3 mb-3">
                            <strong>Provider Note / Reason for Declining:</strong><br>
                            <?= nl2br(htmlspecialchars($booking['rejection_reason'], ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Lifecycle Milestones -->
                    <div class="small text-muted border-top pt-3 mt-3">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div><strong>Booked:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($booking['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if ($booking['accepted_at']): ?>
                                    <div><strong>Accepted:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($booking['accepted_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <?php if ($booking['started_at']): ?>
                                    <div><strong>Service Started:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($booking['started_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if ($booking['completed_at']): ?>
                                    <div class="text-success"><strong>Completed:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($booking['completed_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                                <?php if ($booking['cancelled_at']): ?>
                                    <div class="text-danger"><strong>Cancelled:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($booking['cancelled_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Cancellation Action -->
                    <?php if (in_array($booking['status'], ['pending', 'accepted'], true)): ?>
                        <div class="mt-4 pt-3 border-top">
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelBookingModal">
                                <i class="bi bi-x-circle me-1"></i>Cancel Booking
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Provider Contact Card -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 p-4 h-100">
                    <span class="section-kicker">Service Provider</span>
                    <h3 class="h5 mb-2"><?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="text-muted small mb-3">Contact: <?= htmlspecialchars($booking['provider_contact_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <hr>
                    <p class="mb-2"><strong><i class="bi bi-telephone me-2"></i>Phone:</strong> <?= htmlspecialchars($booking['provider_phone'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-2"><strong><i class="bi bi-geo-alt me-2"></i>City:</strong> <?= htmlspecialchars($booking['provider_city'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-0"><strong><i class="bi bi-pin-map me-2"></i>Address:</strong> <?= htmlspecialchars($booking['provider_address'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>

        <!-- Original Problem & Service Analysis Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm border-0 rounded-4 p-4 h-100">
                    <span class="section-kicker">Original Service Request</span>
                    <h3 class="h5 mb-2"><?= htmlspecialchars($booking['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="text-muted small mb-3">Problem Description:</p>
                    <p class="mb-0 request-description"><?= nl2br(htmlspecialchars($booking['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm border-0 rounded-4 p-4 h-100">
                    <span class="section-kicker">Service Analysis</span>
                    <?php if ($dna): ?>
                        <h3 class="h5 mb-2"><?= htmlspecialchars($dna['problem_type'] ?: 'Technical Request', ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="text-muted small mb-3">Entity: <?= htmlspecialchars($dna['affected_entity'] ?: 'General Hardware', ENT_QUOTES, 'UTF-8') ?></p>
                        <hr>
                        <p class="mb-1"><strong>Symptoms:</strong> <?= htmlspecialchars(implode(', ', $dna['symptoms']) ?: 'None isolated', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-1"><strong>Context:</strong> <?= htmlspecialchars(implode(', ', $dna['context']) ?: 'Standard usage', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-0"><strong>Analysis Confidence:</strong> <?= (int)$dna['confidence_score'] ?>%</p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No Service Analysis recorded for this request.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ADCS Provider Assessment Consensus Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <span class="section-kicker">Phase 7 · Diagnostic Consensus</span>
                    <h3 class="h5 mb-0">Provider Assessment Consensus (ADCS)</h3>
                </div>
                <?php if ($adcs): ?>
                    <a href="adcs.php?id=<?= (int)$booking['request_id'] ?>" class="btn btn-sm btn-outline-primary">Inspect Full ADCS</a>
                <?php endif; ?>
            </div>
            <?php if ($adcs && (int)$adcs['assessment_count'] > 0): ?>
                <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
                    <span class="badge text-bg-primary fs-6"><?= htmlspecialchars((string)$adcs['consensus_score'], ENT_QUOTES, 'UTF-8') ?>/100 Consensus Score</span>
                    <span class="badge text-bg-secondary fs-6"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$adcs['consensus_status'])), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="text-muted small"><?= (int)$adcs['assessment_count'] ?> independent provider assessment(s) evaluated</span>
                </div>
                <?php if (!empty($adcs['consensus_data']['summary'])): ?>
                    <div class="bg-light rounded-3 p-3 text-secondary small">
                        <?php foreach ((array)$adcs['consensus_data']['summary'] as $key => $val): ?>
                            <div><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$key)), ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars(is_array($val) ? implode(', ', $val) : (string)$val, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>No provider assessment consensus available yet. This booking proceeds directly with the selected provider.
                </p>
            <?php endif; ?>
        </div>

        <!-- Status History Timeline -->
        <div class="card shadow-sm border-0 rounded-4 p-4 mb-4">
            <h3 class="h5 mb-3">Booking History & Activity Log</h3>
            <?php if (empty($history)): ?>
                <p class="text-muted mb-0">No historical events logged yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Status Change</th>
                                <th>Updated By</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $event): ?>
                                <tr>
                                    <td class="text-muted small"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($event['changed_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($event['old_status']): ?>
                                            <span class="badge text-bg-light border"><?= htmlspecialchars(bookingStatusLabel($event['old_status']), ENT_QUOTES, 'UTF-8') ?></span>
                                            <i class="bi bi-arrow-right mx-1 text-muted"></i>
                                        <?php endif; ?>
                                        <span class="badge <?= bookingStatusClass($event['new_status']) ?>">
                                            <?= htmlspecialchars(bookingStatusLabel($event['new_status']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($event['changed_by_name'] . ' (' . ucfirst($event['changed_by_role']) . ')', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($event['note'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Customer Cancellation Modal -->
<?php if (in_array($booking['status'], ['pending', 'accepted'], true)): ?>
    <div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-labelledby="cancelBookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <div class="modal-header">
                    <h5 class="modal-title h6" id="cancelBookingModalLabel">Cancel Service Booking?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to cancel this booking with <strong><?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>? The scheduled appointment will be released.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep Booking</button>
                    <form method="POST" action="booking_details.php?id=<?= (int)$booking['id'] ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="cancel">
                        <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
