<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dependencies
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/payment_finalization.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/*
|--------------------------------------------------------------------------
| Only accept POST requests
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
| Read Midtrans notification
|--------------------------------------------------------------------------
*/

try {
    $rawBody = file_get_contents('php://input');

    $notification = json_decode(
        $rawBody ?: '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );

} catch (JsonException $e) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Invalid notification data.'
    ]);
}

if (!is_array($notification)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Invalid notification format.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Validate required notification fields
|--------------------------------------------------------------------------
*/

$orderId = $notification['order_id'] ?? null;

if (
    !is_string($orderId) ||
    $orderId === '' ||
    !isset(
        $notification['status_code'],
        $notification['gross_amount'],
        $notification['signature_key']
    )
) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Missing notification fields.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Verify notification signature
|--------------------------------------------------------------------------
*/

try {
    if (!midtransVerifyNotificationSignature($notification)) {
        error_log('Invalid Midtrans notification signature.');

        jsonResponse(403, [
            'success' => false,
            'message' => 'Invalid notification signature.'
        ]);
    }

} catch (Throwable $e) {
    error_log('Notification signature verification error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to verify notification.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Retrieve actual transaction status from Midtrans
|--------------------------------------------------------------------------
*/

try {
    $transaction = midtransGetTransactionStatus($orderId);

} catch (Throwable $e) {
    error_log('Midtrans status retrieval error: ' . $e->getMessage());

    // Return an error so Midtrans can retry the notification.
    jsonResponse(503, [
        'success' => false,
        'message' => 'Unable to retrieve transaction status.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Verify returned transaction identity
|--------------------------------------------------------------------------
*/

$verifiedOrderId = $transaction['order_id'] ?? null;

if (
    !is_string($verifiedOrderId) ||
    !hash_equals($orderId, $verifiedOrderId)
) {
    error_log('Midtrans order ID mismatch.');

    jsonResponse(400, [
        'success' => false,
        'message' => 'Transaction order ID mismatch.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Compare payment amounts without using floating-point arithmetic
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

$notificationAmount = $normalizeAmount(
    $notification['gross_amount']
);

$verifiedAmount = $normalizeAmount(
    $transaction['gross_amount'] ?? null
);

if (
    $notificationAmount === null ||
    $verifiedAmount === null ||
    $notificationAmount !== $verifiedAmount
) {
    error_log('Midtrans notification amount mismatch.');

    jsonResponse(400, [
        'success' => false,
        'message' => 'Transaction amount mismatch.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Verify amount against our booking hold
|--------------------------------------------------------------------------
*/

try {
    $stmt = $pdo->prepare("
        SELECT total_amount
        FROM booking_hold
        WHERE midtrans_order_id = :order_id
        LIMIT 1
    ");

    $stmt->execute([
        ':order_id' => $orderId
    ]);

    $holdAmount = $stmt->fetchColumn();

    if ($holdAmount === false) {
        error_log('No booking hold found for Midtrans order: ' . $orderId);

        jsonResponse(404, [
            'success' => false,
            'message' => 'Booking hold not found.'
        ]);
    }

    $expectedAmount = $normalizeAmount($holdAmount);

    if (
        $expectedAmount === null ||
        $expectedAmount !== $verifiedAmount
    ) {
        error_log('Booking hold amount does not match Midtrans transaction.');

        jsonResponse(400, [
            'success' => false,
            'message' => 'Booking payment amount does not match.'
        ]);
    }

} catch (PDOException $e) {
    error_log('Booking hold verification error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to verify booking payment.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Determine whether payment is successful
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
| Acknowledge notifications that are not successful payments
|--------------------------------------------------------------------------
|
| A pending, cancelled, expired, or failed transaction is not a
| successful payment. It must never create a reservation.
|
*/

if (!$isPaid) {
    jsonResponse(200, [
        'success' => true,
        'message' => 'Notification received.',
        'payment_status' => $transactionStatus
    ]);
}

/*
|--------------------------------------------------------------------------
| Finalize verified payment
|--------------------------------------------------------------------------
|
| The finalization function will be created in the next file.
| It must:
| - Acquire the booking hour advisory lock.
| - Lock and validate the booking hold.
| - Check capacity again.
| - Create the reservation, invoice, and payment records.
| - Mark the hold as converted.
| - Handle duplicate notifications idempotently.
|
*/

if (!function_exists('finalizeVerifiedMidtransPayment')) {
    error_log('Payment finalization function has not been installed yet.');

    jsonResponse(503, [
        'success' => false,
        'message' => 'Payment finalization is temporarily unavailable.'
    ]);
}

try {
    $result = finalizeVerifiedMidtransPayment(
        $pdo,
        $transaction
    );

    jsonResponse(200, [
        'success' => true,
        'message' => 'Payment notification processed.',
        'data' => $result
    ]);

} catch (Throwable $e) {
    error_log('Payment finalization error: ' . $e->getMessage());

    // Midtrans can retry when it receives a server error.
    jsonResponse(500, [
        'success' => false,
        'message' => 'Payment finalization failed.'
    ]);
}
