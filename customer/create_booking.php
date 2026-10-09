<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/request_helpers.php';
require __DIR__ . '/../includes/matching_helpers.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/validation.php';
require __DIR__ . '/../includes/audit.php';
require __DIR__ . '/../services/service_dna.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$customerId = (int)getUserId();
$errors = [];

$requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) 
    ?: filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);

if (!$requestId || $requestId < 1) {
    http_response_code(404);
    exit('Valid service request required.');
}

$request = findCustomerRequest($pdo, (int)$requestId, $customerId);
if (!$request || in_array($request['status'], ['cancelled', 'completed'], true)) {
    http_response_code(404);
    exit('This request is not eligible for booking.');
}

// Check if request is already booked
$existingBooking = findBookingForRequest($pdo, (int)$requestId);
if ($existingBooking) {
    header('Location: booking_details.php?id=' . (int)$existingBooking['id']);
    exit;
}

$dna = getServiceDnaForRequest($pdo, (int)$requestId);

// Get ranked eligible providers using centralized matching engine
$providers = getRankedProvidersForRequest($pdo, (int)$requestId, $customerId);

$providerId = filter_input(INPUT_POST, 'provider_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'provider_id', FILTER_VALIDATE_INT);

// Consistently resolve provider_profiles.id with strict profile-first precedence
if ($providerId && $providerId > 0) {
    $checkProfileStmt = $pdo->prepare('SELECT id FROM provider_profiles WHERE id = :pid LIMIT 1');
    $checkProfileStmt->execute(['pid' => $providerId]);
    $mappedProfileId = $checkProfileStmt->fetchColumn();

    if ($mappedProfileId === false) {
        $checkUserStmt = $pdo->prepare('SELECT id FROM provider_profiles WHERE user_id = :pid LIMIT 1');
        $checkUserStmt->execute(['pid' => $providerId]);
        $mappedProfileId = $checkUserStmt->fetchColumn();
    }

    if ($mappedProfileId !== false) {
        $providerId = (int)$mappedProfileId;
    }
}

// Verify provider belongs to the matched list or is an approved active provider
$selectedProvider = null;
if ($providerId && $providerId > 0) {
    foreach ($providers as $p) {
        if ((int)$p['provider_id'] === $providerId) {
            $selectedProvider = $p;
            break;
        }
    }
    if (!$selectedProvider) {
        $selectedProvider = getProviderMatchBreakdown($pdo, (int)$requestId, $providerId, $customerId);
        if ($selectedProvider && ($selectedProvider['verification_status'] ?? '') === 'approved' && ($selectedProvider['availability_status'] ?? '') !== 'offline') {
            $providers[] = $selectedProvider;
        } else {
            $selectedProvider = null;
            $providerId = null;
        }
    }
}

// Default to top ranked provider if only 1 provider exists or not specified
if (!$providerId && count($providers) === 1) {
    $providerId = (int)$providers[0]['provider_id'];
    $selectedProvider = $providers[0];
}

$preselectedServiceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'service_id', FILTER_VALIDATE_INT);

$services = [];
if ($providerId) {
    $serviceStmt = $pdo->prepare(
        'SELECT s.id, s.service_name, s.base_price, s.description, c.category_name
         FROM services s
         INNER JOIN service_categories c ON c.id = s.category_id
         WHERE s.provider_id = :provider_id AND s.is_active = 1
         ORDER BY s.service_name ASC'
    );
    $serviceStmt->execute(['provider_id' => $providerId]);
    $services = $serviceStmt->fetchAll();
}

$scheduledDate = (string)($_POST['scheduled_date'] ?? date('Y-m-d', strtotime('+1 day')));
$scheduledTime = (string)($_POST['scheduled_time'] ?? '10:00');
$notes = trim((string)($_POST['notes'] ?? ''));

// Handle Booking Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
    $scheduledDate = trim((string)($_POST['scheduled_date'] ?? ''));
    $scheduledTime = trim((string)($_POST['scheduled_time'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!$providerId || !$selectedProvider) {
        $errors[] = 'Please select an eligible matched service provider.';
    }

    if (!$serviceId) {
        $errors[] = 'Please select an active service from the chosen provider.';
    } else {
        // Validate service belongs to this provider and is active
        $serviceCheck = $pdo->prepare(
            'SELECT id FROM services WHERE id = :service_id AND provider_id = :provider_id AND is_active = 1 LIMIT 1'
        );
        $serviceCheck->execute(['service_id' => $serviceId, 'provider_id' => $providerId]);
        if (!$serviceCheck->fetch()) {
            $errors[] = 'The selected service is not available for this provider.';
        }
    }

    $errors = array_merge($errors, array_values(validateBookingData(['scheduled_date' => $scheduledDate, 'scheduled_time' => $scheduledTime, 'notes' => $notes])));

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock and check for existing booking to prevent race conditions / duplicate bookings
            $lockStmt = $pdo->prepare('SELECT id FROM bookings WHERE request_id = :request_id LIMIT 1 FOR UPDATE');
            $lockStmt->execute(['request_id' => $requestId]);
            if ($lockStmt->fetch()) {
                throw new RuntimeException('A booking already exists for this service request.');
            }

            // Insert new booking
            $insertStmt = $pdo->prepare(
                "INSERT INTO bookings (request_id, customer_id, provider_id, service_id, scheduled_date, scheduled_time, notes, status)
                 VALUES (:request_id, :customer_id, :provider_id, :service_id, :scheduled_date, :scheduled_time, :notes, 'pending')"
            );
            $insertStmt->execute([
                'request_id' => $requestId,
                'customer_id' => $customerId,
                'provider_id' => $providerId,
                'service_id' => $serviceId,
                'scheduled_date' => $scheduledDate,
                'scheduled_time' => $scheduledTime,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $bookingId = (int)$pdo->lastInsertId();

            // Record initial state in booking_status_history
            recordBookingStatus($pdo, $bookingId, null, 'pending', $customerId, 'Booking submitted by customer');

            // Update service request status to 'matched' if it was submitted or analyzing
            $reqUpdate = $pdo->prepare(
                "UPDATE service_requests SET status = 'matched' WHERE id = :request_id AND status IN ('draft', 'submitted', 'analyzing')"
            );
            $reqUpdate->execute(['request_id' => $requestId]);

            $pdo->commit();
            writeAuditLog($pdo, 'booking_created', 'booking', $bookingId, null, ['status' => 'pending', 'request_id' => $requestId, 'provider_id' => $providerId]);

            header('Location: booking_details.php?id=' . $bookingId . '&created=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Booking could not be created right now. Please try again.';
        }
    }
}

$pageTitle = 'Create Booking | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Phase 8 · Service Workflow</span>
                <h1 class="mb-1">Book a Service Provider</h1>
                <p class="text-muted mb-0">Select an eligible matched provider, pick an active service, and schedule your appointment.</p>
            </div>
            <a href="matches.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">Back to Matches</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger rounded-4">
                <h5 class="alert-heading h6 mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please correct the following:</h5>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Request Summary Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <span class="text-muted small text-uppercase">Service Request #<?= (int)$request['id'] ?></span>
                    <h2 class="h5 mb-1"><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="text-muted small mb-0">
                        Location: <?= htmlspecialchars($request['city'], ENT_QUOTES, 'UTF-8') ?><?= $request['area'] ? ', ' . htmlspecialchars($request['area'], ENT_QUOTES, 'UTF-8') : '' ?>
                    </p>
                </div>
                <?php if ($dna && !empty($dna['problem_type'])): ?>
                    <div>
                        <span class="badge text-bg-light border">Analysis: <?= htmlspecialchars($dna['problem_type'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($providers)): ?>
            <div class="card shadow-sm border-0 rounded-4 p-4 text-center">
                <i class="bi bi-person-x fs-1 text-muted mb-2"></i>
                <h3 class="h5">No Matched Providers Available</h3>
                <p class="text-muted">There are currently no verified, available providers matched with this request.</p>
                <a href="request_details.php?id=<?= (int)$requestId ?>" class="btn btn-primary">Return to Request</a>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5">
                <!-- Provider Selector -->
                <div class="mb-4 pb-4 border-bottom">
                    <label class="form-label fw-bold">1. Select Matched Provider</label>
                    <div class="input-group">
                        <select class="form-select" id="providerSelector" onchange="window.location.href='create_booking.php?request_id=<?= (int)$requestId ?>&provider_id=' + this.value">
                            <option value="">-- Choose from matched providers --</option>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?= (int)$p['provider_id'] ?>" <?= $providerId === (int)$p['provider_id'] ? 'selected' : '' ?>>
                                    Rank #<?= (int)$p['ranking_position'] ?> · <?= htmlspecialchars($p['business_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)round((float)($p['match_score'] ?? $p['score'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>% Match)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($providerId): ?>
                            <a href="provider_details.php?id=<?= (int)$providerId ?>&request_id=<?= (int)$requestId ?>" target="_blank" class="btn btn-outline-secondary" title="View provider profile in new tab">
                                <i class="bi bi-box-arrow-up-right me-1"></i>View Profile
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php if ($selectedProvider): ?>
                        <div class="small text-muted mt-2">
                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($selectedProvider['city'] . ($selectedProvider['area'] ? ', ' . $selectedProvider['area'] : ''), ENT_QUOTES, 'UTF-8') ?>
                            · <i class="bi bi-award me-1"></i><?= (int)$selectedProvider['experience_years'] ?> years experience
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (!$providerId): ?>
                    <div class="alert alert-info rounded-3 mb-0">
                        Please choose a provider above to view their active services and schedule a booking.
                    </div>
                <?php elseif (empty($services)): ?>
                    <div class="alert alert-warning rounded-3 mb-0">
                        This provider has no active services listed right now. Please choose another matched provider.
                    </div>
                <?php else: ?>
                    <form method="POST" action="create_booking.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="request_id" value="<?= (int)$requestId ?>">
                        <input type="hidden" name="provider_id" value="<?= (int)$providerId ?>">

                        <!-- Service Selector -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">2. Select Service</label>
                            <div class="row g-3">
                                <?php foreach ($services as $service): ?>
                                    <div class="col-md-6">
                                        <div class="border rounded-3 p-3 h-100">
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" name="service_id" id="service_<?= (int)$service['id'] ?>" value="<?= (int)$service['id'] ?>" <?= ($preselectedServiceId === (int)$service['id'] || (count($services) === 1)) ? 'checked' : '' ?> required>
                                                <label class="form-check-label w-100" for="service_<?= (int)$service['id'] ?>">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <strong class="d-block text-dark"><?= htmlspecialchars($service['service_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <?php if ($service['base_price'] !== null): ?>
                                                            <span class="badge text-bg-light border">₹<?= htmlspecialchars(number_format((float)$service['base_price'], 2), ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="text-muted d-block"><?= htmlspecialchars($service['category_name'], ENT_QUOTES, 'UTF-8') ?></small>
                                                    <?php if (!empty($service['description'])): ?>
                                                        <p class="small text-muted mt-2 mb-0"><?= nl2br(htmlspecialchars($service['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Schedule Date & Time -->
                        <div class="mb-4 pb-3 border-bottom">
                            <label class="form-label fw-bold">3. Preferred Appointment Schedule</label>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="scheduled_date" class="form-label small text-muted">Preferred Date</label>
                                    <input type="date" id="scheduled_date" name="scheduled_date" class="form-control" value="<?= htmlspecialchars($scheduledDate, ENT_QUOTES, 'UTF-8') ?>" min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+90 days')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="scheduled_time" class="form-label small text-muted">Preferred Time</label>
                                    <input type="time" id="scheduled_time" name="scheduled_time" class="form-control" value="<?= htmlspecialchars($scheduledTime, ENT_QUOTES, 'UTF-8') ?>" required>
                                </div>
                            </div>
                            <small class="text-muted mt-1 d-block">Note: Appointment timing will be reviewed and confirmed by the service provider upon acceptance.</small>
                        </div>

                        <!-- Customer Notes -->
                        <div class="mb-4">
                            <label for="notes" class="form-label fw-bold">4. Additional Notes for Provider (Optional)</label>
                            <textarea id="notes" name="notes" class="form-control" rows="3" maxlength="3000" placeholder="Any specific requirements, availability constraints, or directions for the technician..."><?= htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') ?></textarea>
                            <div class="form-text">Maximum 3000 characters.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-calendar-check me-1"></i>Confirm & Submit Booking
                            </button>
                            <a href="matches.php?id=<?= (int)$requestId ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
