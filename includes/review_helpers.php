<?php
declare(strict_types=1);

/**
 * Phase 9: Ratings, Reviews & Trust / Reputation Helpers
 *
 * Centralizes review eligibility verification, anti-abuse checks,
 * reputation calculations, and display formatting.
 */

function findReviewForBooking(PDO $pdo, int $bookingId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, cu.name AS customer_name, pp.business_name
         FROM reviews r
         INNER JOIN users cu ON cu.id = r.customer_id
         INNER JOIN provider_profiles pp ON pp.id = r.provider_id
         WHERE r.booking_id = :booking_id
         LIMIT 1'
    );
    $stmt->execute(['booking_id' => $bookingId]);
    return $stmt->fetch() ?: null;
}

/**
 * Validates whether an authenticated customer is eligible to review a given booking.
 *
 * Eligibility rules:
 * - Booking must exist and belong to the customer.
 * - Booking status must be strictly 'completed'.
 * - Booking must not already have a review.
 */
function canCustomerReviewBooking(PDO $pdo, int $bookingId, int $customerId): array
{
    $stmt = $pdo->prepare(
        'SELECT b.id, b.status, b.customer_id, b.provider_id, b.service_id, b.completed_at,
                r.title AS request_title, r.description AS request_description,
                pp.business_name, pp.city AS provider_city,
                s.service_name
         FROM bookings b
         INNER JOIN service_requests r ON r.id = b.request_id
         INNER JOIN provider_profiles pp ON pp.id = b.provider_id
         LEFT JOIN services s ON s.id = b.service_id
         WHERE b.id = :booking_id
         LIMIT 1'
    );
    $stmt->execute(['booking_id' => $bookingId]);
    $booking = $stmt->fetch();

    if (!$booking) {
        return [
            'eligible' => false,
            'reason' => 'Booking not found.',
            'booking' => null,
            'review' => null,
        ];
    }

    if ((int)$booking['customer_id'] !== $customerId) {
        return [
            'eligible' => false,
            'reason' => 'You are not authorized to review this booking.',
            'booking' => null,
            'review' => null,
        ];
    }

    if ($booking['status'] !== 'completed') {
        return [
            'eligible' => false,
            'reason' => 'Only completed service bookings can be reviewed (current status: ' . ucwords(str_replace('_', ' ', (string)$booking['status'])) . ').',
            'booking' => $booking,
            'review' => null,
        ];
    }

    // Check for existing review
    $existing = findReviewForBooking($pdo, $bookingId);
    if ($existing !== null) {
        return [
            'eligible' => false,
            'reason' => 'This completed booking has already been reviewed.',
            'booking' => $booking,
            'review' => $existing,
        ];
    }

    return [
        'eligible' => true,
        'reason' => null,
        'booking' => $booking,
        'review' => null,
    ];
}

/**
 * Creates a verified customer review for a completed booking inside a transaction.
 */
function createBookingReview(PDO $pdo, int $bookingId, int $customerId, int $rating, ?string $reviewText): int
{
    if ($rating < 1 || $rating > 5) {
        throw new InvalidArgumentException('Rating must be an integer between 1 and 5.');
    }

    $trimmedReview = $reviewText !== null ? trim($reviewText) : '';
    if (mb_strlen($trimmedReview) > 2000) {
        throw new InvalidArgumentException('Review text cannot exceed 2000 characters.');
    }

    $eligibility = canCustomerReviewBooking($pdo, $bookingId, $customerId);
    if (!$eligibility['eligible']) {
        throw new RuntimeException($eligibility['reason'] ?? 'Booking is not eligible for review.');
    }

    $booking = $eligibility['booking'];
    $providerId = (int)$booking['provider_id'];

    $pdo->beginTransaction();
    try {
        // Prevent concurrent duplicate submission with FOR UPDATE check
        $checkStmt = $pdo->prepare('SELECT id FROM reviews WHERE booking_id = :booking_id LIMIT 1');
        $checkStmt->execute(['booking_id' => $bookingId]);
        if ($checkStmt->fetch()) {
            throw new RuntimeException('A review has already been submitted for this booking.');
        }

        // Check if status column exists in table (for backward compatibility with base serveiq.sql)
        $hasStatusCol = true;
        try {
            $colCheck = $pdo->query("SHOW COLUMNS FROM reviews LIKE 'status'");
            if ($colCheck && !$colCheck->fetch()) {
                $hasStatusCol = false;
            }
        } catch (Throwable) {
            // For SQLite or non-MySQL DB, fallback
            $hasStatusCol = false;
        }

        if ($hasStatusCol) {
            $insert = $pdo->prepare(
                'INSERT INTO reviews (booking_id, customer_id, provider_id, rating, review, status)
                 VALUES (:booking_id, :customer_id, :provider_id, :rating, :review, \'published\')'
            );
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO reviews (booking_id, customer_id, provider_id, rating, review)
                 VALUES (:booking_id, :customer_id, :provider_id, :rating, :review)'
            );
        }

        $insert->execute([
            'booking_id' => $bookingId,
            'customer_id' => $customerId,
            'provider_id' => $providerId,
            'rating' => $rating,
            'review' => $trimmedReview !== '' ? $trimmedReview : null,
        ]);

        $reviewId = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $reviewId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Calculates real provider reputation metrics:
 * - Average Rating (1.0 - 5.0, rounded to 1 decimal; 0.0 if no reviews)
 * - Review Count (total verified published reviews)
 * - Completed Services Count (total bookings with status 'completed')
 * - Rating Distribution (counts for 5★, 4★, 3★, 2★, 1★)
 */
function getProviderReputation(PDO $pdo, int $providerId): array
{
    // Check if status column exists
    $hasStatusCol = true;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM reviews LIKE 'status'");
        if ($colCheck && !$colCheck->fetch()) {
            $hasStatusCol = false;
        }
    } catch (Throwable) {
        $hasStatusCol = false;
    }

    $statusClause = $hasStatusCol ? " AND status = 'published'" : '';

    // Aggregate rating and review count
    $stmt = $pdo->prepare(
        "SELECT 
            COALESCE(AVG(rating), 0) AS avg_rating,
            COUNT(id) AS review_count
         FROM reviews
         WHERE provider_id = :provider_id{$statusClause}"
    );
    $stmt->execute(['provider_id' => $providerId]);
    $metrics = $stmt->fetch() ?: [];

    $avgRating = round((float)($metrics['avg_rating'] ?? 0.0), 1);
    $reviewCount = (int)($metrics['review_count'] ?? 0);

    // Completed jobs count
    $jobsStmt = $pdo->prepare(
        "SELECT COUNT(id) AS completed_count
         FROM bookings
         WHERE provider_id = :provider_id AND status = 'completed'"
    );
    $jobsStmt->execute(['provider_id' => $providerId]);
    $completedCount = (int)($jobsStmt->fetch()['completed_count'] ?? 0);

    // Rating distribution
    $distStmt = $pdo->prepare(
        "SELECT rating, COUNT(id) AS count
         FROM reviews
         WHERE provider_id = :provider_id{$statusClause}
         GROUP BY rating"
    );
    $distStmt->execute(['provider_id' => $providerId]);
    $rows = $distStmt->fetchAll();

    $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach ($rows as $row) {
        $r = (int)$row['rating'];
        if (isset($distribution[$r])) {
            $distribution[$r] = (int)$row['count'];
        }
    }

    return [
        'average_rating' => $avgRating,
        'review_count' => $reviewCount,
        'completed_services' => $completedCount,
        'rating_distribution' => $distribution,
    ];
}

/**
 * Fetches verified published reviews for a given service provider.
 */
function getProviderReviews(PDO $pdo, int $providerId, bool $onlyPublished = true, int $limit = 20, int $offset = 0): array
{
    $hasStatusCol = true;
    try {
        $colCheck = $pdo->query("SHOW COLUMNS FROM reviews LIKE 'status'");
        if ($colCheck && !$colCheck->fetch()) {
            $hasStatusCol = false;
        }
    } catch (Throwable) {
        $hasStatusCol = false;
    }

    $where = ['r.provider_id = :provider_id'];
    if ($onlyPublished && $hasStatusCol) {
        $where[] = "r.status = 'published'";
    }

    $sql = 'SELECT r.*, 
                   cu.name AS customer_name,
                   s.service_name,
                   b.scheduled_date,
                   req.title AS request_title
            FROM reviews r
            INNER JOIN users cu ON cu.id = r.customer_id
            INNER JOIN bookings b ON b.id = r.booking_id
            LEFT JOIN services s ON s.id = b.service_id
            LEFT JOIN service_requests req ON req.id = b.request_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY r.created_at DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['provider_id' => $providerId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Renders star icons using Bootstrap Icons.
 */
function renderStarRating(float $rating, int $max = 5): string
{
    $html = '';
    $rounded = round($rating * 2) / 2; // Round to nearest 0.5

    for ($i = 1; $i <= $max; $i++) {
        if ($rounded >= $i) {
            $html .= '<i class="bi bi-star-fill text-warning"></i>';
        } elseif ($rounded >= $i - 0.5) {
            $html .= '<i class="bi bi-star-half text-warning"></i>';
        } else {
            $html .= '<i class="bi bi-star text-muted"></i>';
        }
    }
    return $html;
}

/**
 * Formats customer name for privacy (e.g. "John Doe" -> "John D.").
 */
function formatCustomerDisplayName(string $fullName): string
{
    $parts = preg_split('/\s+/u', trim($fullName));
    if (!$parts || empty($parts[0])) {
        return 'Verified Customer';
    }
    if (count($parts) === 1) {
        return htmlspecialchars($parts[0], ENT_QUOTES, 'UTF-8');
    }
    $first = $parts[0];
    $lastInitial = mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8');
    return htmlspecialchars($first . ' ' . $lastInitial . '.', ENT_QUOTES, 'UTF-8');
}
