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

try {
    /*
     * Retrieve the customer's active temporary booking hold.
     *
     * We do not retrieve a permanent reservation here.
     * A permanent reservation is created only after
     * successful payment verification.
     */
    $stmt = $pdo->prepare("
        SELECT
            bh.hold_id,
            bh.hold_token,
            bh.service_id,
            bh.service_price,
            bh.additional_service_id,
            bh.additional_service_price,
            bh.customer_message,
            bh.total_amount,
            bh.hold_status,
            bh.expires_at,

            s.service_name,
            ads.additional_service_name,

            ts.slot_date,
            ts.start_time,
            ts.end_time,

            u.full_name AS customer_name,
            u.phone_number

        FROM booking_hold bh

        INNER JOIN service s
            ON s.service_id = bh.service_id

        LEFT JOIN additional_service ads
            ON ads.additional_service_id = bh.additional_service_id

        INNER JOIN time_slot ts
            ON ts.slot_id = bh.slot_id

        INNER JOIN users u
            ON u.user_id = bh.user_id

        WHERE bh.user_id = :user_id
          AND bh.hold_status = 'active'
          AND bh.expires_at > CURRENT_TIMESTAMP

        ORDER BY bh.created_at DESC

        LIMIT 1
    ");

    $stmt->execute([
        'user_id' => $userId
    ]);

    $hold = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hold) {
        confirmationResponse(404, [
            'success' => false,
            'message' => 'Your booking session has expired or no active booking was found. Please select your booking again.'
        ]);
    }

    confirmationResponse(200, [
        'success' => true,
        'data' => [
            'hold_id' => (int) $hold['hold_id'],
            'hold_token' => $hold['hold_token'],

            'hold_status' => $hold['hold_status'],
            'expires_at' => $hold['expires_at'],

            'service_id' => (int) $hold['service_id'],
            'service_name' => $hold['service_name'],
            'service_price' => (float) $hold['service_price'],

            'additional_service_id' => $hold['additional_service_id'] !== null
                ? (int) $hold['additional_service_id']
                : null,

            'additional_service_name' => $hold['additional_service_name'],

            'additional_service_price' => $hold['additional_service_price'] !== null
                ? (float) $hold['additional_service_price']
                : null,

            'customer_message' => $hold['customer_message'] ?? '',
            'customer_name' => $hold['customer_name'],
            'phone_number' => $hold['phone_number'],

            'slot_date' => $hold['slot_date'],
            'start_time' => $hold['start_time'],
            'end_time' => $hold['end_time'],

            'total_payment' => (float) $hold['total_amount']
        ]
    ]);

} catch (PDOException $e) {
    error_log('Confirmation API error: ' . $e->getMessage());

    confirmationResponse(500, [
        'success' => false,
        'message' => 'Unable to load booking details.'
    ]);
}
