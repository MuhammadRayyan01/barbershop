<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dependencies
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/payment_finalization.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/*
|--------------------------------------------------------------------------
| Only accept POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Check authentication
|--------------------------------------------------------------------------
*/

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($userId === false || $userId === null || $userId <= 0) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Please log in first.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Check CSRF token
|--------------------------------------------------------------------------
*/

requireValidCsrfToken();

/*
|--------------------------------------------------------------------------
| Read request
|--------------------------------------------------------------------------
*/

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== 0) {
    jsonResponse(415, [
        'success' => false,
        'message' => 'Content-Type must be application/json.'
    ]);
}

try {
    $requestBody = file_get_contents('php://input');

    $data = json_decode(
        $requestBody ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);
}

if (!is_array($data)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Invalid request data.'
    ]);
}

$holdToken = trim((string) ($data['hold_token'] ?? ''));

if ($holdToken === '' || strlen($holdToken) > 64) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'A valid hold token is required.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Find customer's booking hold
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare("
        SELECT
            hold_id,
            hold_status,
            reservation_id,
            midtrans_order_id,
            total_amount
        FROM booking_hold
        WHERE hold_token = :hold_token
          AND user_id = :user_id
        LIMIT 1
    ");

    $stmt->execute([
        ':hold_token' => $holdToken,
        ':user_id' => $userId
    ]);

    $hold = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Payment status hold lookup error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to retrieve payment status.'
    ]);
}

if (!$hold) {
    jsonResponse(404, [
        'success' => false,
        'message' => 'Booking hold not found.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Already finalized?
|--------------------------------------------------------------------------
*/

if (
    $hold['hold_status'] === 'converted' &&
    !empty($hold['reservation_id'])
) {
    jsonResponse(200, [
        'success' => true,
        'payment_status' => 'paid',
        'booking_status' => 'confirmed',
        'message' => 'Your booking has been confirmed.',
        'data' => [
            'reservation_id' => (int) $hold['reservation_id']
        ]
    ]);
}

/*
|--------------------------------------------------------------------------
| Check Midtrans order ID
|--------------------------------------------------------------------------
*/

$orderId = $hold['midtrans_order_id'] ?? null;

if (!is_string($orderId) || $orderId === '') {
    jsonResponse(409, [
        'success' => false,
        'payment_status' => 'not_created',
        'message' => 'A payment transaction has not been created yet.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Ask Midtrans for actual transaction status
|--------------------------------------------------------------------------
*/

try {
    $transaction = midtransGetTransactionStatus($orderId);

} catch (Throwable $e) {
    error_log('Payment status Midtrans request error: ' . $e->getMessage());

    jsonResponse(503, [
        'success' => false,
        'message' => 'Unable to retrieve payment status. Please try again.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Verify transaction identity
|--------------------------------------------------------------------------
*/

$verifiedOrderId = $transaction['order_id'] ?? null;

if (
    !is_string($verifiedOrderId) ||
    !hash_equals($orderId, $verifiedOrderId)
) {
    error_log('Payment status order ID mismatch.');

    jsonResponse(400, [
        'success' => false,
        'message' => 'Transaction verification failed.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Normalize and compare amounts
|--------------------------------------------------------------------------
*/

$normalizeAmount = static function (mixed $amount): ?string {
    if (
        !is_string($amount) &&
        !is_int($amount) &&
        !is_float($amount)
    ) {
        return null;
    }

    $amount = (string) $amount;

    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
        return null;
    }

    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;

    $decimal = str_pad($matches[2] ?? '', 2, '0');

    return $whole . '.' . $decimal;
};

$expectedAmount = $normalizeAmount($hold['total_amount']);
$verifiedAmount = $normalizeAmount(
    $transaction['gross_amount'] ?? null
);

if (
    $expectedAmount === null ||
    $verifiedAmount === null ||
    $expectedAmount !== $verifiedAmount
) {
    error_log('Payment status amount mismatch.');

    jsonResponse(400, [
        'success' => false,
        'message' => 'Transaction amount verification failed.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Determine payment status
|--------------------------------------------------------------------------
*/

$transactionStatus = $transaction['transaction_status'] ?? '';
$fraudStatus = $transaction['fraud_status'] ?? '';

$isPaid = (
    $transactionStatus === 'settlement'
    ||
    (
        $transactionStatus === 'capture' &&
        $fraudStatus === 'accept'
    )
);

/*
|--------------------------------------------------------------------------
| Finalize successful payment
|--------------------------------------------------------------------------
*/

if ($isPaid) {
    try {
        $result = finalizeVerifiedMidtransPayment(
            $pdo,
            $transaction
        );

        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'paid',
            'booking_status' => 'confirmed',
            'message' => 'Your payment was successful and your booking is confirmed.',
            'data' => $result
        ]);

    } catch (Throwable $e) {
        error_log('Payment status finalization error: ' . $e->getMessage());

        /*
         * Payment is confirmed by Midtrans, but booking finalization
         * could not be completed. Do not report this as unpaid.
         */

        jsonResponse(202, [
            'success' => false,
            'payment_status' => 'paid',
            'booking_status' => 'manual_review',
            'message' => 'Your payment was received, but your booking requires further review. Please contact the barbershop.'
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Return non-successful transaction status
|--------------------------------------------------------------------------
*/

jsonResponse(200, [
    'success' => true,
    'payment_status' => $transactionStatus ?: 'unknown',
    'booking_status' => 'not_confirmed',
    'message' => 'Payment has not been confirmed yet.'
]);
