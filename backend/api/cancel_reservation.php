<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/midtrans.php';

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

// Check authentication.
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

// Check CSRF token.
requireValidCsrfToken();

// Validate JSON content type.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== 0) {
    jsonResponse(415, [
        'success' => false,
        'message' => 'Content-Type must be application/json.'
    ]);
}

// Read the request body.
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

// Validate reservation ID.
$reservationId = filter_var(
    $data['reservation_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($reservationId === false || $reservationId === null || $reservationId <= 0) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'A valid reservation ID is required.'
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

// Retrieve and lock the customer's reservation and payment.
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            r.reservation_id,
            r.user_id,
            r.reservation_status,
            i.cancellation_deadline,
            CASE
                WHEN (clock_timestamp() AT TIME ZONE 'Asia/Jakarta')
                     < i.cancellation_deadline
                THEN 1
                ELSE 0
            END AS within_window,
            p.payment_id,
            p.payment_status,
            p.amount,
            p.transaction_reference,
            p.refund_status,
            p.refund_key
        FROM reservation r
        INNER JOIN invoice i
            ON i.reservation_id = r.reservation_id
        INNER JOIN payment p
            ON p.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
          AND r.user_id = :user_id
        LIMIT 1
        FOR UPDATE OF r, i, p
    ");

    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $userId
    ]);

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Reservation or payment record not found.'
        ]);
    }

    if ($booking['reservation_status'] !== 'active') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'This reservation is no longer active.'
        ]);
    }

    if ($booking['payment_status'] !== 'paid') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'Only successfully paid reservations can be cancelled.'
        ]);
    }

    if ($booking['refund_status'] === 'completed') {
        $pdo->commit();

        jsonResponse(200, [
            'success' => true,
            'refund_status' => 'completed',
            'message' => 'This reservation has already been refunded.'
        ]);
    }

    if ($booking['refund_status'] === 'failed') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'refund_status' => 'failed',
            'message' => 'The previous refund request failed. Please contact the barbershop.'
        ]);
    }

    $isRetry = $booking['refund_status'] === 'pending';

    if (!$isRetry && (int) $booking['within_window'] !== 1) {
        $pdo->rollBack();

        jsonResponse(403, [
            'success' => false,
            'message' => 'The seven-minute cancellation period has expired.'
        ]);
    }

    $orderId = $booking['transaction_reference'];

    if (!is_string($orderId) || $orderId === '') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'The payment transaction reference is missing.'
        ]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Reservation cancellation preparation error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to prepare the cancellation request.'
    ]);
}

// Retrieve the actual transaction status from Midtrans.
try {
    $transaction = midtransGetTransactionStatus($orderId);
} catch (Throwable $e) {
    error_log('Cancellation Midtrans status error: ' . $e->getMessage());

    jsonResponse(503, [
        'success' => false,
        'message' => 'Unable to verify the payment with Midtrans. Please try again.'
    ]);
}

// Verify transaction identity.
$verifiedOrderId = $transaction['order_id'] ?? null;

if (
    !is_string($verifiedOrderId) ||
    !hash_equals($orderId, $verifiedOrderId)
) {
    error_log('Cancellation transaction order ID mismatch.');

    jsonResponse(400, [
        'success' => false,
        'message' => 'Payment transaction verification failed.'
    ]);
}

// Verify the original payment amount.
$paymentAmount = $normalizeAmount($booking['amount']);

$transactionAmount = $normalizeAmount(
    $transaction['gross_amount'] ?? null
);

if (
    $paymentAmount === null ||
    $transactionAmount === null ||
    $paymentAmount !== $transactionAmount
) {
    error_log('Cancellation transaction amount mismatch.');

    jsonResponse(400, [
        'success' => false,
        'message' => 'Payment amount verification failed.'
    ]);
}

$transactionStatus = $transaction['transaction_status'] ?? '';

// Use this identifier for Midtrans API requests.
$transactionIdentifier = $transaction['transaction_id'] ?? $orderId;

if (
    !is_string($transactionIdentifier) ||
    $transactionIdentifier === ''
) {
    $transactionIdentifier = $orderId;
}

// Finalize a card cancellation after Midtrans confirms it.
$finalizeCardCancellation = static function () use (
    $pdo,
    $booking,
    $reservationId,
    $userId
): void {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                r.reservation_status,
                p.payment_status
            FROM reservation r
            INNER JOIN payment p
                ON p.reservation_id = r.reservation_id
            WHERE r.reservation_id = :reservation_id
              AND r.user_id = :user_id
            FOR UPDATE OF r, p
        ");

        $stmt->execute([
            ':reservation_id' => $reservationId,
            ':user_id' => $userId
        ]);

        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$current ||
            $current['reservation_status'] !== 'active' ||
            $current['payment_status'] !== 'paid'
        ) {
            $pdo->rollBack();

            throw new RuntimeException(
                'Reservation state changed before cancellation could be finalized.'
            );
        }

        $stmt = $pdo->prepare("
            UPDATE payment
            SET
                payment_status = 'cancelled',
                refund_status = 'not_requested',
                refund_key = NULL,
                refund_reason = NULL,
                refund_requested_at = NULL,
                refunded_at = NULL,
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE payment_id = :payment_id
        ");

        $stmt->execute([
            ':payment_id' => $booking['payment_id']
        ]);

        $stmt = $pdo->prepare("
            UPDATE reservation
            SET
                reservation_status = 'inactive',
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE reservation_id = :reservation_id
        ");

        $stmt->execute([
            ':reservation_id' => $reservationId
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
};

// Handle a card transaction that has already been cancelled.
if ($transactionStatus === 'cancel') {
    try {
        $finalizeCardCancellation();

        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'message' => 'Your card payment and reservation have been cancelled successfully.'
        ]);

    } catch (Throwable $e) {
        error_log('Card cancellation finalization error: ' . $e->getMessage());

        jsonResponse(500, [
            'success' => false,
            'message' => 'Midtrans cancelled the transaction, but the reservation update failed. Please contact the barbershop.'
        ]);
    }
}

// Handle captured or authorized card payments using the Cancel API.
if (
    $transactionStatus === 'capture' ||
    $transactionStatus === 'authorize'
) {
    // Clear the old pending refund state from the previous incorrect attempt.
    if ($booking['refund_status'] === 'pending') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE payment
                SET
                    refund_status = 'not_requested',
                    refund_key = NULL,
                    refund_reason = NULL,
                    refund_requested_at = NULL,
                    updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                WHERE payment_id = :payment_id
                  AND payment_status = 'paid'
                  AND refund_status = 'pending'
            ");

            $stmt->execute([
                ':payment_id' => $booking['payment_id']
            ]);

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Old refund state cleanup error: ' . $e->getMessage());

            jsonResponse(500, [
                'success' => false,
                'message' => 'Unable to prepare the card cancellation.'
            ]);
        }
    }

    try {
        $cancelResponse = midtransCancelTransaction($orderId);

        $cancelStatus = $cancelResponse['transaction_status'] ?? '';
        $cancelOrderId = $cancelResponse['order_id'] ?? '';

        if (
            $cancelStatus !== 'cancel' ||
            !is_string($cancelOrderId) ||
            !hash_equals($orderId, $cancelOrderId)
        ) {
            jsonResponse(502, [
                'success' => false,
                'payment_status' => 'paid',
                'message' => 'Midtrans did not confirm the card cancellation. Please try again or contact the barbershop.'
            ]);
        }

        $finalizeCardCancellation();

        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'message' => 'Your card payment has been cancelled successfully. Your bank may take up to one business day to restore the available card limit.'
        ]);

    } catch (Throwable $e) {
        error_log('Midtrans card cancellation error: ' . $e->getMessage());

        jsonResponse(503, [
            'success' => false,
            'payment_status' => 'paid',
            'message' => 'Unable to confirm card cancellation with Midtrans. Please check your reservation status before trying again.'
        ]);
    }
}

// A refund already recorded by Midtrans must be confirmed by notification.
if ($transactionStatus === 'refund') {
    jsonResponse(200, [
        'success' => true,
        'refund_status' => 'pending',
        'message' => 'Midtrans has recorded the refund. Waiting for refund confirmation.'
    ]);
}

// Only settled transactions use the Refund API.
if ($transactionStatus !== 'settlement') {
    jsonResponse(409, [
        'success' => false,
        'payment_transaction_status' => $transactionStatus,
        'message' => 'This payment cannot currently be cancelled or refunded through the available Midtrans API operation.'
    ]);
}

// Prepare a stable refund key for settled transactions.
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            r.reservation_status,
            p.payment_status,
            p.refund_status,
            p.refund_key
        FROM reservation r
        INNER JOIN payment p
            ON p.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
          AND r.user_id = :user_id
        FOR UPDATE OF r, p
    ");

    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $userId
    ]);

    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$current ||
        $current['reservation_status'] !== 'active' ||
        $current['payment_status'] !== 'paid'
    ) {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'The reservation or payment status has changed.'
        ]);
    }

    if ($current['refund_status'] === 'completed') {
        $pdo->commit();

        jsonResponse(200, [
            'success' => true,
            'refund_status' => 'completed',
            'message' => 'This reservation has already been refunded.'
        ]);
    }

    if ($current['refund_status'] === 'failed') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'refund_status' => 'failed',
            'message' => 'The previous refund request failed. Please contact the barbershop.'
        ]);
    }

    if ($current['refund_status'] === 'pending') {
        $refundKey = $current['refund_key'];

        if (!is_string($refundKey) || $refundKey === '') {
            $pdo->rollBack();

            jsonResponse(409, [
                'success' => false,
                'message' => 'The pending refund is missing its reference key.'
            ]);
        }
    } else {
        $refundKey = 'PEMON-REFUND-' .
            $booking['payment_id'] . '-' .
            bin2hex(random_bytes(8));

        $stmt = $pdo->prepare("
            UPDATE payment
            SET
                refund_status = 'pending',
                refund_key = :refund_key,
                refund_reason = :refund_reason,
                refund_requested_at =
                    clock_timestamp() AT TIME ZONE 'Asia/Jakarta',
                updated_at =
                    clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE payment_id = :payment_id
        ");

        $stmt->execute([
            ':refund_key' => $refundKey,
            ':refund_reason' => 'Customer cancellation within seven-minute period',
            ':payment_id' => $booking['payment_id']
        ]);
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Refund preparation error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to prepare the refund request.'
    ]);
}

// Submit the full refund to Midtrans.
try {
    $refundResponse = midtransRequestRefund(
        $transactionIdentifier,
        [
            'refund_key' => $refundKey,
            'amount' => $paymentAmount,
            'reason' => 'Customer cancellation within seven-minute period'
        ]
    );

    $returnedRefundKey = $refundResponse['refund_key'] ?? null;

    if (
        is_string($returnedRefundKey) &&
        !hash_equals($refundKey, $returnedRefundKey)
    ) {
        error_log('Midtrans returned a different refund key.');

        jsonResponse(502, [
            'success' => false,
            'refund_status' => 'pending',
            'message' => 'The refund response could not be verified. Please contact the barbershop.'
        ]);
    }

    jsonResponse(200, [
        'success' => true,
        'refund_status' => 'pending',
        'message' => 'Your refund request has been submitted. Waiting for Midtrans confirmation.'
    ]);

} catch (Throwable $e) {
    error_log('Midtrans refund request error: ' . $e->getMessage());

    jsonResponse(503, [
        'success' => false,
        'refund_status' => 'pending',
        'message' => 'The refund request could not be confirmed. You can safely retry this request.'
    ]);
}
