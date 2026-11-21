<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/payment_finalization.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

// Read the Midtrans notification.
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

// Validate required notification fields.
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

// Verify the notification signature.
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

// Retrieve the actual transaction status from Midtrans.
try {
    $transaction = midtransGetTransactionStatus($orderId);
} catch (Throwable $e) {
    error_log('Midtrans status retrieval error: ' . $e->getMessage());

    jsonResponse(503, [
        'success' => false,
        'message' => 'Unable to retrieve transaction status.'
    ]);
}

// Verify the returned transaction identity.
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

// Normalize monetary values without floating-point arithmetic.
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

// Compare notification and verified transaction amounts.
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

// Verify the transaction amount against the original booking hold.
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

// Handle a fully refunded transaction separately from payment finalization.
$transactionStatus = $transaction['transaction_status'] ?? '';

if ($transactionStatus === 'refund') {
    try {
        $pdo->beginTransaction();

        // Lock the payment and reservation while processing the refund.
        $stmt = $pdo->prepare("
            SELECT
                p.payment_id,
                p.reservation_id,
                p.amount,
                p.refund_key,
                p.refund_status,
                p.payment_status,
                r.reservation_status
            FROM payment p
            INNER JOIN reservation r
                ON r.reservation_id = p.reservation_id
            WHERE p.transaction_reference = :order_id
            LIMIT 1
            FOR UPDATE OF p, r
        ");

        $stmt->execute([
            ':order_id' => $orderId
        ]);

        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $pdo->rollBack();

            error_log('Refund notification has no matching payment: ' . $orderId);

            jsonResponse(404, [
                'success' => false,
                'message' => 'Payment record not found.'
            ]);
        }

        $refundKey = $payment['refund_key'];

        if (
            !is_string($refundKey) ||
            $refundKey === ''
        ) {
            $pdo->rollBack();

            error_log('Refund notification has no stored refund key: ' . $orderId);

            jsonResponse(200, [
                'success' => true,
                'message' => 'Refund notification received but no refund request is recorded.'
            ]);
        }

        // Find the exact refund request using our stored refund key.
        $refundDetails = $transaction['refunds'] ?? null;
        $matchingRefund = null;

        if (is_array($refundDetails)) {
            foreach ($refundDetails as $refund) {
                if (
                    is_array($refund) &&
                    isset($refund['refund_key']) &&
                    is_string($refund['refund_key']) &&
                    hash_equals($refundKey, $refund['refund_key'])
                ) {
                    $matchingRefund = $refund;
                    break;
                }
            }
        }

        // A refund request is not complete until Midtrans confirms it.
        if (
            !is_array($matchingRefund) ||
            !isset($matchingRefund['bank_confirmed_at']) ||
            !is_string($matchingRefund['bank_confirmed_at']) ||
            trim($matchingRefund['bank_confirmed_at']) === ''
        ) {
            $pdo->commit();

            jsonResponse(200, [
                'success' => true,
                'message' => 'Refund request is not yet confirmed by the payment provider.'
            ]);
        }

        // Only accept a full refund matching the original payment amount.
        $confirmedRefundAmount = $normalizeAmount(
            $matchingRefund['refund_amount'] ?? null
        );

        $originalPaymentAmount = $normalizeAmount(
            $payment['amount']
        );

        if (
            $confirmedRefundAmount === null ||
            $originalPaymentAmount === null ||
            $confirmedRefundAmount !== $originalPaymentAmount
        ) {
            $pdo->rollBack();

            error_log('Confirmed refund amount does not match the original payment: ' . $orderId);

            jsonResponse(400, [
                'success' => false,
                'message' => 'Refund amount does not match the original payment.'
            ]);
        }

        // Do not process a payment that was not marked as awaiting a refund.
        if ($payment['refund_status'] === 'completed') {
            $pdo->commit();

            jsonResponse(200, [
                'success' => true,
                'message' => 'Refund was already processed.'
            ]);
        }

        if ($payment['refund_status'] !== 'pending') {
            $pdo->rollBack();

            error_log('Refund confirmation received for a payment not awaiting a refund: ' . $orderId);

            jsonResponse(200, [
                'success' => true,
                'message' => 'Refund notification received but no pending refund exists.'
            ]);
        }

        // Mark the payment as refunded only after provider confirmation.
        $stmt = $pdo->prepare("
            UPDATE payment
            SET
                payment_status = 'refunded',
                refund_status = 'completed',
                refunded_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE payment_id = :payment_id
        ");

        $stmt->execute([
            ':payment_id' => $payment['payment_id']
        ]);

        // Deactivate the reservation after the confirmed full refund.
        $stmt = $pdo->prepare("
            UPDATE reservation
            SET
                reservation_status = 'inactive',
                updated_at = CURRENT_TIMESTAMP
            WHERE reservation_id = :reservation_id
        ");

        $stmt->execute([
            ':reservation_id' => $payment['reservation_id']
        ]);

        $pdo->commit();

        jsonResponse(200, [
            'success' => true,
            'message' => 'Refund successfully confirmed and processed.'
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('Refund notification processing error: ' . $e->getMessage());

        jsonResponse(500, [
            'success' => false,
            'message' => 'Unable to process refund confirmation.'
        ]);
    }
}

// A partial refund is not a completed full cancellation.
if ($transactionStatus === 'partial_refund') {
    jsonResponse(200, [
        'success' => true,
        'message' => 'Partial refund notification received.',
        'payment_status' => $transactionStatus
    ]);
}

// Determine whether the transaction is a successful payment.
$fraudStatus = $transaction['fraud_status'] ?? '';

$isPaid = (
    $transactionStatus === 'settlement'
    ||
    (
        $transactionStatus === 'capture' &&
        $fraudStatus === 'accept'
    )
);

// A pending, cancelled, expired, or failed transaction must not create a reservation.
if (!$isPaid) {
    jsonResponse(200, [
        'success' => true,
        'message' => 'Notification received.',
        'payment_status' => $transactionStatus
    ]);
}

// Finalize the verified payment.
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

    jsonResponse(500, [
        'success' => false,
        'message' => 'Payment finalization failed.'
    ]);
}
