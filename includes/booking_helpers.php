<?php
declare(strict_types=1);

/**
 * Phase 8: Booking & Service Workflow Helpers
 *
 * Centralizes booking state machine transitions, schedule validation,
 * database lookup, and status history auditing.
 */

function bookingStatuses(): array
{
    return ['pending', 'accepted', 'in_progress', 'completed', 'rejected', 'cancelled'];
}

/**
 * Returns list of allowed next statuses from a given current status.
 *
 * State Machine:
 * - pending     -> accepted, rejected, cancelled
 * - accepted    -> in_progress, cancelled
 * - in_progress -> completed
 * - completed   -> (terminal)
 * - rejected    -> (terminal)
 * - cancelled   -> (terminal)
 */
function allowedBookingTransitions(string $currentStatus): array
{
    return match ($currentStatus) {
        'pending' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed'],
        default => [],
    };
}

/**
 * Validates if a transition from $currentStatus to $newStatus is valid in the state machine.
 */
function canTransitionBookingStatus(string $currentStatus, string $newStatus): bool
{
    return in_array($newStatus, allowedBookingTransitions($currentStatus), true);
}

/**
 * Validates if a user role is permitted to perform the specified transition.
 *
 * - Customer: Can only cancel (from pending or accepted).
 * - Provider: Can accept or reject pending, start accepted, complete in_progress.
 * - Admin: Can perform any valid transition.
 */
function canUserTransitionBooking(string $role, string $currentStatus, string $newStatus): bool
{
    if (!canTransitionBookingStatus($currentStatus, $newStatus)) {
        return false;
    }

    return match ($role) {
        'customer' => $newStatus === 'cancelled',
        'provider' => in_array($newStatus, ['accepted', 'rejected', 'in_progress', 'completed'], true),
        'admin' => true,
        default => false,
    };
}

function bookingStatusLabel(string $status): string
{
    return match ($status) {
        'in_progress' => 'In Progress',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function bookingStatusClass(string $status): string
{
    return match ($status) {
        'pending' => 'text-bg-warning text-dark',
        'accepted' => 'text-bg-info text-dark',
        'in_progress' => 'text-bg-primary',
        'completed' => 'text-bg-success',
        'rejected', 'cancelled' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

function findCustomerBooking(PDO $pdo, int $bookingId, int $customerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT b.*, 
                r.title, r.description, r.urgency AS request_urgency, r.city AS request_city, r.area AS request_area,
                pp.business_name, pp.phone AS provider_phone, pp.city AS provider_city, pp.address AS provider_address,
                u.name AS provider_contact_name,
                s.service_name, s.base_price, s.description AS service_description
         FROM bookings b
         INNER JOIN service_requests r ON r.id = b.request_id
         INNER JOIN provider_profiles pp ON pp.id = b.provider_id
         INNER JOIN users u ON u.id = pp.user_id
         LEFT JOIN services s ON s.id = b.service_id
         WHERE b.id = :booking_id AND b.customer_id = :customer_id
         LIMIT 1'
    );
    $stmt->execute(['booking_id' => $bookingId, 'customer_id' => $customerId]);
    return $stmt->fetch() ?: null;
}

function findProviderBooking(PDO $pdo, int $bookingId, int $providerId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT b.*, 
                r.title, r.description, r.urgency AS request_urgency, r.city AS request_city, r.area AS request_area, r.address AS customer_address,
                cu.name AS customer_name, cu.email AS customer_email,
                pp.business_name,
                s.service_name, s.base_price, s.description AS service_description
         FROM bookings b
         INNER JOIN service_requests r ON r.id = b.request_id
         INNER JOIN users cu ON cu.id = b.customer_id
         INNER JOIN provider_profiles pp ON pp.id = b.provider_id
         LEFT JOIN services s ON s.id = b.service_id
         WHERE b.id = :booking_id AND b.provider_id = :provider_id
         LIMIT 1'
    );
    $stmt->execute(['booking_id' => $bookingId, 'provider_id' => $providerId]);
    return $stmt->fetch() ?: null;
}

function findBookingForRequest(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT b.*, pp.business_name, s.service_name
         FROM bookings b
         INNER JOIN provider_profiles pp ON pp.id = b.provider_id
         LEFT JOIN services s ON s.id = b.service_id
         WHERE b.request_id = :request_id
         LIMIT 1'
    );
    $stmt->execute(['request_id' => $requestId]);
    return $stmt->fetch() ?: null;
}

function recordBookingStatus(PDO $pdo, int $bookingId, ?string $oldStatus, string $newStatus, int $userId, ?string $note = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, note)
         VALUES (:booking_id, :old_status, :new_status, :changed_by, :note)'
    );
    $stmt->execute([
        'booking_id' => $bookingId,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
        'changed_by' => $userId,
        'note' => $note,
    ]);
}

function getBookingStatusHistory(PDO $pdo, int $bookingId): array
{
    $stmt = $pdo->prepare(
        'SELECT h.*, u.name AS changed_by_name, u.role AS changed_by_role
         FROM booking_status_history h
         INNER JOIN users u ON u.id = h.changed_by
         WHERE h.booking_id = :booking_id
         ORDER BY h.changed_at ASC, h.id ASC'
    );
    $stmt->execute(['booking_id' => $bookingId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Validates appointment scheduling:
 * - Valid Y-m-d format
 * - Valid H:i format
 * - Must be present or future (allowing 5 min tolerance for form latency)
 * - Must not exceed 90 days in advance
 */
function bookingDateTimeIsValid(string $date, string $time): bool
{
    $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $timeValue = DateTimeImmutable::createFromFormat('!H:i', $time);

    if ($dateValue === false || $dateValue->format('Y-m-d') !== $date) {
        return false;
    }
    if ($timeValue === false || $timeValue->format('H:i') !== $time) {
        return false;
    }

    $combined = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
    if ($combined === false) {
        return false;
    }

    $now = new DateTimeImmutable('now');
    // Must not be in the past (allowing 5 minute clock drift/form fill margin)
    if ($combined < $now->modify('-5 minutes')) {
        return false;
    }
    // Must not be scheduled more than 90 days in the future
    if ($combined > $now->modify('+90 days')) {
        return false;
    }

    return true;
}
