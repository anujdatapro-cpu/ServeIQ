<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/adcs_helpers.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../services/service_dna.php';
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
$bookingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$bookingId || $bookingId < 1) {
    http_response_code(404);
    exit('Booking not found.');
}

$booking = findProviderBooking($pdo, $bookingId, $providerId);
if (!$booking) {
    http_response_code(404);
    exit('Booking not found or access denied.');
}

$error = '';
$success = '';

if (isset($_GET['updated'])) {
    $success = 'Booking status was updated successfully.';
}

// Handle Provider Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $action = (string)($_POST['action'] ?? '');
    $nextStatus = match ($action) {
        'accept' => 'accepted',
        'reject' => 'rejected',
        'start' => 'in_progress',
        'complete' => 'completed',
        default => null,
    };

    if (!$nextStatus || !canUserTransitionBooking('provider', (string)$booking['status'], $nextStatus)) {
        $error = 'Invalid booking action or status transition from ' . bookingStatusLabel((string)$booking['status']) . '.';
    } else {
        $rejectionReason = null;
        if ($nextStatus === 'rejected') {
            $rejectionReason = trim((string)($_POST['rejection_reason'] ?? ''));
            if (mb_strlen($rejectionReason) > 500) {
                $error = 'Rejection reason cannot exceed 500 characters.';
            }
        }

        if (empty($error)) {
            try {
                $pdo->beginTransaction();

                // Determine timestamp column to update
                $timestampCol = match ($nextStatus) {
                    'accepted' => 'accepted_at',
                    'in_progress' => 'started_at',
                    'completed' => 'completed_at',
                    default => null,
                };

                // Build exact SQL and parameter array to prevent PDO parameter mismatches
                $sqlParts = ['status = :next_status'];
                $params = [
                    'next_status' => $nextStatus,
                    'id' => $bookingId,
                    'provider_id' => $providerId,
                    'current_status' => $booking['status'],
                ];

                if ($timestampCol !== null) {
                    $sqlParts[] = "{$timestampCol} = CURRENT_TIMESTAMP";
                }

                if ($nextStatus === 'rejected') {
                    $sqlParts[] = 'rejection_reason = :rejection_reason';
                    $params['rejection_reason'] = $rejectionReason !== '' ? $rejectionReason : null;
                }

                $sql = 'UPDATE bookings SET ' . implode(', ', $sqlParts) . ' WHERE id = :id AND provider_id = :provider_id AND status = :current_status';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Booking could not be updated. Current status may have changed.');
                }

                // Log into booking_status_history
                $note = match ($nextStatus) {
                    'accepted' => 'Booking accepted by provider',
                    'rejected' => $rejectionReason !== '' ? 'Rejected: ' . $rejectionReason : 'Booking declined by provider',
                    'in_progress' => 'Service execution started',
                    'completed' => 'Service marked as completed by provider',
                    default => null,
                };
                recordBookingStatus($pdo, $bookingId, (string)$booking['status'], $nextStatus, $userId, $note);

                // Synchronize service request status with lifecycle
                if ($nextStatus === 'in_progress') {
                    $reqUpdate = $pdo->prepare(
                        "UPDATE service_requests 
                         SET status = 'in_progress' 
                         WHERE id = :request_id AND status NOT IN ('cancelled', 'completed')"
                    );
                    $reqUpdate->execute(['request_id' => (int)$booking['request_id']]);
                } elseif ($nextStatus === 'completed') {
                    $reqUpdate = $pdo->prepare(
                        "UPDATE service_requests 
                         SET status = 'completed' 
                         WHERE id = :request_id AND status NOT IN ('cancelled')"
                    );
                    $reqUpdate->execute(['request_id' => (int)$booking['request_id']]);
                }

                $pdo->commit();
                header('Location: booking_details.php?id=' . $bookingId . '&updated=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log($e->getMessage());
                $error = 'Failed to update booking status. Please try again.';
            }
        }
    }
}

// ServiceDNA diagnosis
$dna = getServiceDnaForRequest($pdo, (int)$booking['request_id']);

// ADCS consensus data
$adcs = null;
try {
    $adcs = getADCSForRequest($pdo, (int)$booking['request_id'], $userId);
} catch (Throwable $e) {
    error_log('ADCS lookup notice: ' . $e->getMessage());
}

// Booking status history
$history = getBookingStatusHistory($pdo, $bookingId);

// Review check
$review = findReviewForBooking($pdo, $bookingId);

$pageTitle = 'Manage Booking #' . $bookingId . ' | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Phase 8 & 9 · Service Operations</span>
                <h1 class="mb-1">Manage Booking #<?= (int)$booking['id'] ?></h1>
                <p class="text-muted mb-0">Customer: <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="bookings.php" class="btn btn-outline-secondary">Back to Bookings</a>
                <a href="dashboard.php" class="btn btn-outline-secondary">Dashboard</a>
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
            <span class="section-kicker mb-1">Service Execution Workflow</span>
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
                        <div class="step-title">1. Pending Review</div>
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
                        <div class="step-title">4. Service Completed</div>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex align-items-center gap-3 p-3 rounded-3 <?= $status === 'cancelled' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning-emphasis' ?> mt-2">
                    <i class="bi <?= $status === 'cancelled' ? 'bi-x-circle-fill' : 'bi-slash-circle-fill' ?> fs-3"></i>
                    <div>
                        <strong class="d-block">Booking <?= ucfirst($status) ?></strong>
                        <span class="small">This booking is in a terminal state (<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>). No further lifecycle actions can be executed.</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Customer Review if submitted -->
        <?php if ($review): ?>
            <div class="card shadow-sm border-0 border-start border-4 border-success rounded-4 p-4 mb-4 bg-light">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <div>
                        <span class="section-kicker text-success">Customer Review</span>
                        <h3 class="h5 mb-1">Feedback from <?= htmlspecialchars($booking['customer_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                    </div>
                    <span class="badge text-bg-success"><i class="bi bi-patch-check-fill me-1"></i>Verified Service</span>
                </div>
                <div class="mb-2">
                    <span class="fs-5 me-2"><?= renderStarRating((float)$review['rating']) ?></span>
                    <strong><?= (int)$review['rating'] ?>/5 Stars</strong>
                    <span class="text-muted small ms-2">Submitted <?= htmlspecialchars(date('M j, Y', strtotime($review['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if (!empty($review['review'])): ?>
                    <p class="text-secondary mb-0 mt-2 p-3 bg-white rounded-3 border">
                        <?= nl2br(htmlspecialchars($review['review'], ENT_QUOTES, 'UTF-8')) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <!-- Booking Status & Actions -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 h-100">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <span class="text-muted small text-uppercase">Requested Service</span>
                            <h2 class="h4 mb-1"><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></h2>
                            <?php if ($booking['base_price'] !== null): ?>
                                <p class="text-muted small mb-0">Rate: $<?= htmlspecialchars(number_format((float)$booking['base_price'], 2), ENT_QUOTES, 'UTF-8') ?></p>
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
                            <label class="text-muted small">Customer Instructions / Notes</label>
                            <div class="bg-light p-3 rounded-3 text-secondary">
                                <?= nl2br(htmlspecialchars($booking['notes'], ENT_QUOTES, 'UTF-8')) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($booking['status'] === 'rejected' && !empty($booking['rejection_reason'])): ?>
                        <div class="alert alert-warning rounded-3 mb-3">
                            <strong>Reason for Declining:</strong><br>
                            <?= nl2br(htmlspecialchars($booking['rejection_reason'], ENT_QUOTES, 'UTF-8')) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Lifecycle Milestones -->
                    <div class="small text-muted border-top pt-3 mt-3">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div><strong>Requested On:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($booking['created_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if ($booking['accepted_at']): ?>
                                    <div><strong>Accepted On:</strong> <?= htmlspecialchars(date('M j, Y g:i A', strtotime($booking['accepted_at'])), ENT_QUOTES, 'UTF-8') ?></div>
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

                    <!-- State Machine Action Bar -->
                    <div class="mt-4 pt-3 border-top">
                        <?php if ($booking['status'] === 'pending'): ?>
                            <div class="d-flex gap-2 flex-wrap">
                                <form method="POST" action="booking_details.php?id=<?= (int)$booking['id'] ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="accept">
                                    <button type="submit" class="btn btn-primary px-4">
                                        <i class="bi bi-check-circle me-1"></i>Accept Appointment
                                    </button>
                                </form>
                                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectBookingModal">
                                    <i class="bi bi-x-circle me-1"></i>Decline Booking
                                </button>
                            </div>
                        <?php elseif ($booking['status'] === 'accepted'): ?>
                            <form method="POST" action="booking_details.php?id=<?= (int)$booking['id'] ?>">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="start">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="bi bi-play-circle me-1"></i>Start Service Execution
                                </button>
                            </form>
                        <?php elseif ($booking['status'] === 'in_progress'): ?>
                            <form method="POST" action="booking_details.php?id=<?= (int)$booking['id'] ?>">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="complete">
                                <button type="submit" class="btn btn-success px-4">
                                    <i class="bi bi-check2-all me-1"></i>Mark Service Completed
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-secondary mb-0 rounded-3">
                                <i class="bi bi-lock me-1"></i>This booking is in terminal state (<strong><?= htmlspecialchars(bookingStatusLabel($booking['status']), ENT_QUOTES, 'UTF-8') ?></strong>). No further status transitions can be performed.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Customer & Location Card -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 rounded-4 p-4 h-100">
                    <span class="section-kicker">Customer Information</span>
                    <h3 class="h5 mb-2"><?= htmlspecialchars($booking['customer_name'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="text-muted small mb-3">Email: <?= htmlspecialchars($booking['customer_email'], ENT_QUOTES, 'UTF-8') ?></p>
                    <hr>
                    <p class="mb-2"><strong><i class="bi bi-geo-alt me-2"></i>City:</strong> <?= htmlspecialchars($booking['request_city'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-2"><strong><i class="bi bi-pin-map me-2"></i>Area:</strong> <?= htmlspecialchars($booking['request_area'] ?: 'Not specified', ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if (!empty($booking['customer_address'])): ?>
                        <p class="mb-2"><strong><i class="bi bi-house me-2"></i>Address:</strong> <?= htmlspecialchars($booking['customer_address'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <p class="mb-0"><strong><i class="bi bi-tag me-2"></i>Urgency:</strong> <span class="badge text-bg-light border"><?= htmlspecialchars(ucfirst((string)$booking['request_urgency']), ENT_QUOTES, 'UTF-8') ?></span></p>
                </div>
            </div>
        </div>

        <!-- Problem Description & ServiceDNA Cards -->
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm border-0 rounded-4 p-4 h-100">
                    <span class="section-kicker">Service Request</span>
                    <h3 class="h5 mb-2"><?= htmlspecialchars($booking['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p class="text-muted small mb-2">Customer Problem Description:</p>
                    <p class="mb-0 request-description"><?= nl2br(htmlspecialchars($booking['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm border-0 rounded-4 p-4 h-100">
                    <span class="section-kicker">ServiceDNA Diagnosis</span>
                    <?php if ($dna): ?>
                        <h3 class="h5 mb-2"><?= htmlspecialchars($dna['problem_type'] ?: 'Technical Request', ENT_QUOTES, 'UTF-8') ?></h3>
                        <p class="text-muted small mb-3">Entity: <?= htmlspecialchars($dna['affected_entity'] ?: 'General Hardware', ENT_QUOTES, 'UTF-8') ?></p>
                        <hr>
                        <p class="mb-1"><strong>Symptoms:</strong> <?= htmlspecialchars(implode(', ', $dna['symptoms']) ?: 'None isolated', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-1"><strong>Context:</strong> <?= htmlspecialchars(implode(', ', $dna['context']) ?: 'Standard usage', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-0"><strong>Analysis Confidence:</strong> <?= (int)$dna['confidence_score'] ?>%</p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No ServiceDNA recorded for this request.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ADCS Provider Assessment Consensus Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5 mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <span class="section-kicker">Phase 7 · Diagnostic Consensus</span>
                    <h3 class="h5 mb-0">ADCS Assessment Consensus Summary</h3>
                </div>
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
                    <i class="bi bi-info-circle me-1"></i>No multi-provider consensus result available. Proceeding with independent direct assessment.
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

<!-- Reject Booking Modal -->
<?php if ($booking['status'] === 'pending'): ?>
    <div class="modal fade" id="rejectBookingModal" tabindex="-1" aria-labelledby="rejectBookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <form method="POST" action="booking_details.php?id=<?= (int)$booking['id'] ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reject">
                    <div class="modal-header">
                        <h5 class="modal-title h6" id="rejectBookingModalLabel">Decline Booking Request</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Please provide a reason to inform the customer why this appointment cannot be accepted.</p>
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label small fw-bold">Reason (Optional)</label>
                            <textarea name="rejection_reason" id="rejection_reason" class="form-control" rows="3" maxlength="500" placeholder="e.g. Schedule conflict, out of service area, or specialized equipment required..."></textarea>
                            <div class="form-text">Maximum 500 characters.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button>
                        <button type="submit" class="btn btn-danger">Confirm Decline</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
