<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';

header('Cache-Control: no-store');

function confirmationResponse(int $status, array $payload): never
{
    jsonResponse($status, $payload);
    exit;
}

// Only GET requests are allowed.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');

    confirmationResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

// Check customer session.
$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$userId || $userId < 1) {
    confirmationResponse(401, [
        'success' => false,
        'message' => 'Please log in first.'
    ]);
}

// Get the reservation ID created by booking.php API.
$reservationId = filter_var(
    $_SESSION['last_reservation_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$reservationId || $reservationId < 1) {
    confirmationResponse(404, [
        'success' => false,
        'message' => 'No reservation found. Please make a booking first.'
    ]);
}

try {
    $stmt = $pdo->prepare("
        SELECT
            r.reservation_id,
            r.reservation_status,
            r.service_price,
            s.service_name,
            ts.slot_date,
            ts.start_time,
            ts.end_time,
            i.customer_name,
            i.phone_number,
            i.total_payment,
            i.payment_status
        FROM reservation r
        INNER JOIN service s
            ON s.service_id = r.service_id
        INNER JOIN time_slot ts
            ON ts.slot_id = r.slot_id
        INNER JOIN invoice i
            ON i.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
          AND r.user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        'reservation_id' => $reservationId,
        'user_id' => $userId
    ]);

    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        confirmationResponse(404, [
            'success' => false,
            'message' => 'Reservation not found.'
        ]);
    }

    confirmationResponse(200, [
        'success' => true,
        'data' => [
            'reservation_id' => (int) $reservation['reservation_id'],
            'reservation_status' => $reservation['reservation_status'],
            'service_name' => $reservation['service_name'],
            'customer_name' => $reservation['customer_name'],
            'phone_number' => $reservation['phone_number'],
            'slot_date' => $reservation['slot_date'],
            'start_time' => $reservation['start_time'],
            'end_time' => $reservation['end_time'],
            'service_price' => (float) $reservation['service_price'],
            'total_payment' => (float) $reservation['total_payment'],
            'payment_status' => $reservation['payment_status']
        ]
    ]);

} catch (PDOException $e) {
    error_log('Confirmation API error: ' . $e->getMessage());

    confirmationResponse(500, [
        'success' => false,
        'message' => 'Unable to load reservation details.'
    ]);
}