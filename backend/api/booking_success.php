<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/auth_require.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method !== 'GET') {
    header('Allow: GET');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$reservationId = filter_input(
    INPUT_GET,
    'reservation_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

$orderId = $_GET['order_id'] ?? '';

if (
    !$reservationId &&
    (
        !is_string($orderId) ||
        !preg_match('/^[A-Za-z0-9_-]{1,255}$/', $orderId)
    )
) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'A valid reservation ID or order ID is required.'
    ]);
}

try {
    if ($reservationId) {
        $stmt = $pdo->prepare(
            "SELECT
                r.reservation_id,
                r.reservation_status::text AS reservation_status,
                s.service_name,
                ts.slot_date,
                ts.start_time,
                ts.end_time,
                u.full_name,
                u.phone_number,
                i.invoice_id,
                i.total_payment,
                p.payment_status::text AS payment_status,
                p.payment_type,
                p.transaction_reference
            FROM reservation r
            JOIN users u
                ON u.user_id = r.user_id
            JOIN service s
                ON s.service_id = r.service_id
            JOIN time_slot ts
                ON ts.slot_id = r.slot_id
            JOIN invoice i
                ON i.reservation_id = r.reservation_id
            LEFT JOIN payment p
                ON p.reservation_id = r.reservation_id
            WHERE r.reservation_id = :reservation_id
              AND r.user_id = :user_id"
        );

        $stmt->execute([
            'reservation_id' => $reservationId,
            'user_id' => $authenticatedUserId
        ]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT
                r.reservation_id,
                r.reservation_status::text AS reservation_status,
                s.service_name,
                ts.slot_date,
                ts.start_time,
                ts.end_time,
                u.full_name,
                u.phone_number,
                i.invoice_id,
                i.total_payment,
                p.payment_status::text AS payment_status,
                p.payment_type,
                p.transaction_reference
            FROM reservation r
            JOIN users u
                ON u.user_id = r.user_id
            JOIN service s
                ON s.service_id = r.service_id
            JOIN time_slot ts
                ON ts.slot_id = r.slot_id
            JOIN invoice i
                ON i.reservation_id = r.reservation_id
            JOIN payment p
                ON p.reservation_id = r.reservation_id
            WHERE p.transaction_reference = :order_id
              AND r.user_id = :user_id"
        );

        $stmt->execute([
            'order_id' => $orderId,
            'user_id' => $authenticatedUserId
        ]);
    }

    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        jsonResponse(404, [
            'success' => false,
            'message' => 'Reservation not found.'
        ]);
    }

    jsonResponse(200, [
        'success' => true,
        'data' => $reservation
    ]);

} catch (PDOException $e) {
    error_log('Booking success API error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to load reservation information.'
    ]);
}
