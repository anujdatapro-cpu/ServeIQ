<?php
declare(strict_types=1);

require __DIR__ . '/../includes/session.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/booking_helpers.php';
require __DIR__ . '/../config/database.php';

requireLogin();

$userId = (int)getUserId();
$userRole = (string)getUserRole();
$bookingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$bookingId || $bookingId < 1) {
    http_response_code(404);
    exit('Booking not found.');
}

$pdo = getDatabaseConnection();
$booking = null;

if ($userRole === 'customer') {
    $booking = findCustomerBooking($pdo, $bookingId, $userId);
} elseif ($userRole === 'provider') {
    $pStmt = $pdo->prepare('SELECT id FROM provider_profiles WHERE user_id = :uid LIMIT 1');
    $pStmt->execute(['uid' => $userId]);
    $provId = (int)$pStmt->fetchColumn();
    if ($provId > 0) {
        $booking = findProviderBooking($pdo, $bookingId, $provId);
    }
} elseif ($userRole === 'admin') {
    $stmt = $pdo->prepare(
        'SELECT b.*, pp.business_name, pp.phone AS provider_phone, pp.city AS provider_city, pp.area AS provider_area, pp.address AS provider_address,
                s.service_name, s.base_price, r.title, r.city AS request_city, r.area AS request_area,
                cu.name AS customer_name, cu.email AS customer_email
         FROM bookings b
         INNER JOIN provider_profiles pp ON pp.id = b.provider_id
         INNER JOIN users cu ON cu.id = b.customer_id
         INNER JOIN service_requests r ON r.id = b.request_id
         LEFT JOIN services s ON s.id = b.service_id
         WHERE b.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $bookingId]);
    $booking = $stmt->fetch() ?: null;
}

if (!$booking) {
    http_response_code(403);
    exit('Booking not found or access denied.');
}

$customerName = (string)($booking['customer_name'] ?? '');
if ($customerName === '') {
    $customerStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id');
    $customerStmt->execute(['id' => (int)$booking['customer_id']]);
    $customerName = (string)($customerStmt->fetchColumn() ?: 'Customer');
}
// A single-page native PDF writer keeps receipts downloadable without external dependencies.
$escape = static function (string $value): string {
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '';
    return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $value);
};

$servicePrice = $booking['base_price'] !== null ? number_format((float)$booking['base_price'], 2) : 'Not set';
$lines = [
    'ServeIQ | Booking Receipt',
    'Receipt for service booking. This document is not proof of payment.',
    'Booking: #' . (int)$booking['id'],
    'Status: ' . bookingStatusLabel((string)$booking['status']),
    'Customer: ' . $customerName,
    'Provider: ' . (string)$booking['business_name'],
    'Service: ' . (string)($booking['service_name'] ?? 'General Service'),
    'Request: ' . (string)$booking['title'],
    'Appointment: ' . (string)$booking['scheduled_date'] . ' ' . substr((string)$booking['scheduled_time'], 0, 5),
    'Location: ' . (string)$booking['request_city'] . (empty($booking['request_area']) ? '' : ', ' . (string)$booking['request_area']),
    'Listed service price (INR): ' . $servicePrice,
    'Listed rates are indicative; this receipt does not record an amount paid.',
    'Created: ' . (string)$booking['created_at'],
];
$stream = "BT\n/F1 18 Tf\n50 790 Td\n(" . $escape(array_shift($lines)) . ") Tj\n/F1 10 Tf\n0 -30 Td\n";
foreach ($lines as $line) $stream .= '(' . $escape($line) . ") Tj\n0 -24 Td\n";
$stream .= "ET\n";
$objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . "endstream",
];
$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
$offsets = [0];
foreach ($objects as $index => $object) {
    $offsets[] = strlen($pdf);
    $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
}
$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
for ($i = 1; $i < count($offsets); $i++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
$pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="ServeIQ_Receipt_' . (int)$booking['id'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
