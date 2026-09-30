<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../includes/review_helpers.php';
require __DIR__ . '/../config/database.php';

requireCustomer();

$pdo = getDatabaseConnection();
$customerId = (int)getUserId();
$errors = [];

$bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);

if (!$bookingId || $bookingId < 1) {
    http_response_code(404);
    exit('Booking not found.');
}

$check = canCustomerReviewBooking($pdo, $bookingId, $customerId);
if (!$check['eligible']) {
    if ($check['review'] !== null) {
        // Already reviewed
        header('Location: booking_details.php?id=' . $bookingId . '&already_reviewed=1');
        exit;
    }
    http_response_code(403);
    exit(htmlspecialchars($check['reason'] ?? 'You are not eligible to review this booking.', ENT_QUOTES, 'UTF-8'));
}

$booking = $check['booking'];
$rating = (int)($_POST['rating'] ?? 5);
$reviewText = (string)($_POST['review'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $submittedRating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
    $submittedReview = trim((string)($_POST['review'] ?? ''));

    if (!$submittedRating || $submittedRating < 1 || $submittedRating > 5) {
        $errors[] = 'Please select a valid rating from 1 to 5 stars.';
    }

    if (mb_strlen($submittedReview) > 2000) {
        $errors[] = 'Your written review cannot exceed 2000 characters.';
    }

    if (empty($errors)) {
        try {
            createBookingReview($pdo, $bookingId, $customerId, $submittedRating, $submittedReview);
            header('Location: booking_details.php?id=' . $bookingId . '&reviewed=1');
            exit;
        } catch (Throwable $e) {
            error_log($e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
}

$pageTitle = 'Review Completed Service | ServeIQ';
$basePath = '../';
require __DIR__ . '/../includes/header.php';
?>

<main class="dashboard-page">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <span class="section-kicker">Phase 9 · Ratings & Trust</span>
                <h1 class="mb-1">Rate & Review Service</h1>
                <p class="text-muted mb-0">Share your experience to help the community and build provider trust.</p>
            </div>
            <a href="booking_details.php?id=<?= (int)$bookingId ?>" class="btn btn-outline-secondary">Back to Booking</a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger rounded-4 mb-4">
                <h5 class="alert-heading h6 mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>Please resolve the following:</h5>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Service & Booking Information Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 mb-4">
            <div class="row g-3 align-items-center">
                <div class="col-md-8">
                    <span class="text-muted small text-uppercase">Completed Service</span>
                    <h2 class="h5 mb-1"><?= htmlspecialchars($booking['service_name'] ?? 'General Service', ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="text-muted mb-1">
                        Provided by: <strong><?= htmlspecialchars($booking['business_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </p>
                    <small class="text-muted">
                        Request: <?= htmlspecialchars($booking['request_title'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($booking['completed_at']): ?>
                            · Completed <?= htmlspecialchars(date('M j, Y', strtotime($booking['completed_at'])), ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </small>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="badge text-bg-success fs-6">
                        <i class="bi bi-patch-check-fill me-1"></i>Verified Completed Service
                    </span>
                </div>
            </div>
        </div>

        <!-- Review Form Card -->
        <div class="card shadow-sm border-0 rounded-4 p-4 p-md-5">
            <form method="POST" action="review_booking.php">
                <?= csrfField() ?>
                <input type="hidden" name="booking_id" value="<?= (int)$bookingId ?>">

                <!-- Star Rating Selection -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Overall Rating (1 to 5 Stars) <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap gap-2 pt-1">
                        <?php 
                        $starLabels = [
                            5 => '5 Stars - Excellent',
                            4 => '4 Stars - Good',
                            3 => '3 Stars - Average',
                            2 => '2 Stars - Poor',
                            1 => '1 Star - Terrible',
                        ];
                        foreach ($starLabels as $stars => $label): 
                        ?>
                            <div class="form-check form-check-inline border rounded-3 px-3 py-2">
                                <input class="form-check-input" type="radio" name="rating" id="rating_<?= $stars ?>" value="<?= $stars ?>" <?= $rating === $stars ? 'checked' : '' ?> required>
                                <label class="form-check-label fw-semibold text-dark" for="rating_<?= $stars ?>">
                                    <?= renderStarRating((float)$stars) ?>
                                    <span class="ms-1 small"><?= $stars ?>/5</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Written Review Input -->
                <div class="mb-4">
                    <label for="review" class="form-label fw-bold">Written Review (Optional)</label>
                    <textarea name="review" id="review" class="form-control" rows="5" maxlength="2000" placeholder="Describe the quality of work, punctuality, technical skill, and customer service..."><?= htmlspecialchars($reviewText, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="form-text">Maximum 2000 characters. Your review will be published as a verified service review.</div>
                </div>

                <div class="alert alert-light border rounded-3 p-3 mb-4 text-secondary small">
                    <i class="bi bi-shield-check text-success me-1"></i>
                    <strong>Authenticity Policy:</strong> ServeIQ reviews are permanently linked to verified, completed service bookings. Only you can submit a review for this job.
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-star-fill me-1"></i>Submit Verified Review
                    </button>
                    <a href="booking_details.php?id=<?= (int)$bookingId ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php require __DIR__ . '/../includes/footer.php'; ?>
