<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/midtrans.php';
require_once __DIR__ . '/../config/refund_reconciliation.php';

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

$adminId = filter_var($_SESSION['admin_id'] ?? null, FILTER_VALIDATE_INT);
$isAdmin = $adminId !== false && $adminId !== null && $adminId > 0;

if (($userId === false || $userId === null || $userId <= 0) && !$isAdmin) {
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
            r.admin_cancelled_at,
            i.cancellation_deadline,
            CASE
                WHEN (clock_timestamp() AT TIME ZONE 'Asia/Jakarta')
                     < i.cancellation_deadline
                THEN 1
                ELSE 0
            END AS within_window,
            p.payment_id,
            p.payment_status::text AS payment_status,
            p.amount,
            p.transaction_reference,
            p.payment_type,
            p.refund_status,
            p.refund_key
        FROM reservation r
        INNER JOIN invoice i
            ON i.reservation_id = r.reservation_id
        INNER JOIN payment p
            ON p.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
          AND (:is_admin = TRUE OR r.user_id = :user_id)
        LIMIT 1
        FOR UPDATE OF r, i, p
    ");

    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $userId ?: 0,
        ':is_admin' => $isAdmin
    ]);

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Reservation or payment record not found.'
        ]);
    }

    // Use the owner from the locked reservation for internal ownership checks.
    $userId = (int) $booking['user_id'];

    if (
        $booking['payment_status'] === 'refunded' &&
        $booking['refund_status'] === 'completed'
    ) {
        if ($isAdmin) {
            $stmt = $pdo->prepare("UPDATE reservation SET reservation_status = 'inactive', admin_cancelled_at = COALESCE(admin_cancelled_at, clock_timestamp() AT TIME ZONE 'Asia/Jakarta'), updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta' WHERE reservation_id = :reservation_id");
            $stmt->execute([':reservation_id' => $reservationId]);
        }
        $pdo->commit();

        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'refunded',
            'refund_status' => 'completed',
            'message' => 'This reservation has already been refunded.'
        ]);
    }

    if (
        $booking['payment_status'] === 'cancelled' &&
        $booking['refund_status'] === 'not_requested' &&
        $booking['reservation_status'] === 'inactive'
    ) {
        if ($isAdmin && $booking['admin_cancelled_at'] === null) {
            $stmt = $pdo->prepare("UPDATE reservation SET admin_cancelled_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta' WHERE reservation_id = :reservation_id");
            $stmt->execute([':reservation_id' => $reservationId]);
        }
        $pdo->commit();

        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'refund_status' => 'not_requested',
            'message' => 'This reservation has already been cancelled.'
        ]);
    }

    if ($booking['refund_status'] === 'completed') {
        $pdo->commit();

        jsonResponse(200, [
            'success' => true,
            'payment_status' => $booking['payment_status'],
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

    if (
        $isAdmin &&
        $booking['payment_status'] === 'paid' &&
        strtolower((string) $booking['payment_type']) === 'cash'
    ) {
        $refundKey = is_string($booking['refund_key']) && $booking['refund_key'] !== ''
            ? $booking['refund_key']
            : 'PEMON-MANUAL-' . $booking['payment_id'] . '-' . bin2hex(random_bytes(8));
        $manualRefund = $pdo->prepare("
            UPDATE payment
            SET refund_status = 'pending',
                refund_method = 'manual',
                refund_key = :refund_key,
                refund_reason = 'Reservation cancelled by barbershop',
                refund_requested_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta',
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE payment_id = :payment_id
              AND payment_status = 'paid'
              AND refund_status IN ('not_requested', 'pending')
        ");
        $manualRefund->execute([
            ':refund_key' => $refundKey,
            ':payment_id' => $booking['payment_id']
        ]);
        if ($manualRefund->rowCount() !== 1 && $booking['refund_status'] !== 'pending') {
            $pdo->rollBack();
            jsonResponse(409, ['success' => false, 'message' => 'The payment is not ready for a manual refund.']);
        }
        $cancel = $pdo->prepare("
            UPDATE reservation
            SET reservation_status = 'inactive',
                admin_cancelled_at = COALESCE(admin_cancelled_at, clock_timestamp() AT TIME ZONE 'Asia/Jakarta'),
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE reservation_id = :reservation_id
        ");
        $cancel->execute([':reservation_id' => $reservationId]);
        $pdo->commit();
        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'paid',
            'refund_status' => 'pending',
            'refund_method' => 'manual',
            'message' => 'Reservation cancelled. The barbershop must send the cash refund manually.'
        ]);
    }

    if (
        $isAdmin &&
        $booking['payment_status'] !== 'paid' &&
        (!is_string($booking['transaction_reference']) || $booking['transaction_reference'] === '')
    ) {
        $cancel = $pdo->prepare("
            UPDATE reservation
            SET reservation_status = 'inactive',
                admin_cancelled_at = COALESCE(admin_cancelled_at, clock_timestamp() AT TIME ZONE 'Asia/Jakarta'),
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE reservation_id = :reservation_id
              AND reservation_status = 'active'
        ");
        $cancel->execute([':reservation_id' => $reservationId]);
        $paymentCancel = $pdo->prepare("
            UPDATE payment
            SET payment_status = 'cancelled',
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE payment_id = :payment_id
              AND payment_status IN ('pending', 'verification pending', 'failed')
        ");
        $paymentCancel->execute([':payment_id' => $booking['payment_id']]);
        $pdo->commit();
        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'refund_status' => 'not_requested',
            'message' => 'Reservation cancelled. No payment was captured, so no refund is needed.'
        ]);
    }

    // An inactive reservation may be retried only when its refund intent
    // already exists. This lets a captured payment proceed after settlement.
    $isRetry = $booking['refund_status'] === 'pending';

    if (
        $booking['reservation_status'] !== 'active' &&
        !$isRetry
    ) {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'This reservation is no longer active.'
        ]);
    }

    if (!$isAdmin && $booking['payment_status'] !== 'paid') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'Only successfully paid reservations can be cancelled.'
        ]);
    }

    // A pending refund can be checked again without creating a new key.
    if (
        !$isAdmin &&
        !$isRetry &&
        (int) $booking['within_window'] !== 1
    ) {
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

// The webhook may have been delayed. If Midtrans confirms a successful
// transaction, reconcile the local payment before selecting the refund path.
if (
    $isAdmin &&
    $booking['payment_status'] !== 'paid' &&
    in_array($transaction['transaction_status'] ?? '', ['capture', 'settlement', 'refund', 'partial_refund'], true)
) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            UPDATE payment
            SET payment_status = 'paid',
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE payment_id = :payment_id
              AND payment_status IN ('pending', 'verification pending', 'failed', 'cancelled')
        ");
        $stmt->execute([':payment_id' => $booking['payment_id']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Could not reconcile the successful Midtrans payment.');
        }
        $pdo->commit();
        $booking['payment_status'] = 'paid';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin cancellation payment reconciliation error: ' . $e->getMessage());
        jsonResponse(409, [
            'success' => false,
            'message' => 'Midtrans shows a successful payment, but the local payment status could not be reconciled. Please retry or contact support.'
        ]);
    }
}

// Find a refund record using the exact stored refund key.
$findMatchingRefund = static function (
    array $transaction,
    string $refundKey
): ?array {
    $refunds = $transaction['refunds'] ?? [];

    if (!is_array($refunds)) {
        return null;
    }

    foreach ($refunds as $refund) {
        if (
            is_array($refund) &&
            isset($refund['refund_key']) &&
            is_string($refund['refund_key']) &&
            hash_equals($refundKey, $refund['refund_key'])
        ) {
            return $refund;
        }
    }

    return null;
};

// Verify that a Midtrans refund record represents the full payment amount.
$verifyFullRefundRecord = static function (
    array $refund,
    string $paymentAmount
) use ($normalizeAmount): bool {
    $refundAmount = $normalizeAmount(
        $refund['refund_amount'] ?? null
    );

    return $refundAmount !== null &&
        $refundAmount === $paymentAmount;
};

// Use this identifier for Midtrans API requests.
$transactionStatus = $transaction['transaction_status'] ?? '';

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
    $userId,
    $isAdmin
): void {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT
                r.reservation_status,
                r.admin_cancelled_at,
                p.payment_status::text AS payment_status,
                p.refund_status
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

        if (!$current) {
            throw new RuntimeException(
                'Reservation not found during cancellation finalization.'
            );
        }

        // Already finalized.
        if (
            $current['reservation_status'] === 'inactive' &&
            $current['payment_status'] === 'cancelled' &&
            $current['refund_status'] === 'not_requested'
        ) {
            if ($isAdmin && $current['admin_cancelled_at'] === null) {
                $pdo->prepare("
                    UPDATE reservation
                    SET admin_cancelled_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                    WHERE reservation_id = :reservation_id
                ")->execute([':reservation_id' => $reservationId]);
            }
            $pdo->commit();
            return;
        }

        // A confirmed Midtrans cancellation can clear an old pending
        // intent, because a cancelled transaction is not a refund.
        if (
            (!$isAdmin && $current['payment_status'] !== 'paid') ||
            ($isAdmin && !in_array($current['payment_status'], ['paid', 'pending', 'verification pending', 'failed'], true)) ||
            !in_array(
                $current['refund_status'],
                ['not_requested', 'pending'],
                true
            )
        ) {
            throw new RuntimeException(
                'Payment state does not allow cancellation finalization.'
            );
        }

        $paymentStatusCondition = $isAdmin
            ? "payment_status IN ('paid', 'pending', 'verification pending', 'failed')"
            : "payment_status = 'paid'";
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
              AND $paymentStatusCondition
              AND refund_status IN ('not_requested', 'pending')
        ");

        $stmt->execute([
            ':payment_id' => $booking['payment_id']
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Unable to update payment cancellation status.'
            );
        }

        $stmt = $pdo->prepare("
            UPDATE reservation
            SET
                reservation_status = 'inactive',
                admin_cancelled_at = CASE
                    WHEN :is_admin THEN COALESCE(admin_cancelled_at, clock_timestamp() AT TIME ZONE 'Asia/Jakarta')
                    ELSE admin_cancelled_at
                END,
                updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE reservation_id = :reservation_id
        ");

        $stmt->execute([
            ':reservation_id' => $reservationId,
            ':is_admin' => $isAdmin
        ]);

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
};

// Handle a transaction that Midtrans has already cancelled.
if ($transactionStatus === 'cancel') {
    try {
        $finalizeCardCancellation();

        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'refund_status' => 'not_requested',
            'message' => 'Your card payment and reservation have been cancelled successfully.'
        ]);

    } catch (Throwable $e) {
        error_log(
            'Card cancellation finalization error: ' . $e->getMessage()
        );

        jsonResponse(500, [
            'success' => false,
            'message' => 'Midtrans cancelled the transaction, but the reservation update failed. Please contact the barbershop.'
        ]);
    }
}

// Pending and authorized Midtrans payments can be cancelled, but not refunded.
if ($isAdmin && in_array($transactionStatus, ['pending', 'authorize'], true)) {
    try {
        $cancelResponse = midtransCancelTransaction($orderId);
        $cancelOrderId = $cancelResponse['order_id'] ?? null;
        $cancelAmount = $normalizeAmount($cancelResponse['gross_amount'] ?? null);
        if (
            !is_string($cancelOrderId) ||
            !hash_equals($orderId, $cancelOrderId) ||
            ($cancelResponse['transaction_status'] ?? '') !== 'cancel' ||
            $cancelAmount !== $paymentAmount
        ) {
            throw new RuntimeException('Midtrans cancellation response could not be verified.');
        }
        $finalizeCardCancellation();
        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'refund_status' => 'not_requested',
            'message' => 'Reservation cancelled through Midtrans. No refund is needed because the payment was not settled.'
        ]);
    } catch (Throwable $e) {
        error_log('Admin Midtrans pending cancellation error: ' . $e->getMessage());
        try {
            $latestTransaction = midtransGetTransactionStatus($orderId);
            $latestOrderId = $latestTransaction['order_id'] ?? null;
            $latestAmount = $normalizeAmount($latestTransaction['gross_amount'] ?? null);
            if (
                !is_string($latestOrderId) ||
                !hash_equals($orderId, $latestOrderId) ||
                $latestAmount !== $paymentAmount
            ) {
                throw new RuntimeException('Updated Midtrans transaction could not be verified.');
            }
            $latestStatus = $latestTransaction['transaction_status'] ?? '';
            if ($latestStatus === 'cancel') {
                $finalizeCardCancellation();
                jsonResponse(200, [
                    'success' => true,
                    'payment_status' => 'cancelled',
                    'refund_status' => 'not_requested',
                    'message' => 'Reservation cancelled through Midtrans. No refund is needed because the payment was not settled.'
                ]);
            }
            if (in_array($latestStatus, ['settlement', 'capture'], true)) {
                $transaction = $latestTransaction;
                $transactionStatus = $latestStatus;
                $transactionIdentifier = $latestTransaction['transaction_id'] ?? $orderId;
            } else {
                jsonResponse(503, [
                    'success' => false,
                    'message' => 'Midtrans has not confirmed cancellation. No reservation or payment state was changed; please retry.'
                ]);
            }
        } catch (Throwable $statusError) {
            error_log('Admin Midtrans cancellation recheck error: ' . $statusError->getMessage());
            jsonResponse(503, [
                'success' => false,
                'message' => 'Unable to verify the payment cancellation with Midtrans. Please retry.'
            ]);
        }
    }
}

if ($isAdmin && in_array($transactionStatus, ['expire', 'deny', 'failure'], true)) {
    try {
        $finalizeCardCancellation();
        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'refund_status' => 'not_requested',
            'message' => 'Reservation cancelled. Midtrans confirms no settled payment, so no refund is needed.'
        ]);
    } catch (Throwable $e) {
        error_log('Admin no-payment cancellation finalization error: ' . $e->getMessage());
        jsonResponse(500, [
            'success' => false,
            'message' => 'Midtrans shows no settled payment, but the reservation could not be cancelled. Please retry.'
        ]);
    }
}

// An authorization is not a captured payment and cannot be refunded yet.
// Do not call the Midtrans Cancel API or claim that a refund was issued.
if ($transactionStatus === 'authorize') {
    jsonResponse(409, [
        'success' => false,
        'payment_status' => 'paid',
        'payment_transaction_status' => 'authorize',
        'message' => 'The card payment is only authorized, not captured. No refund has been submitted. Please contact the barbershop to resolve the authorization.'
    ]);
}

// Void an unsettled captured card charge immediately. If it settled while the
// cancel request was in flight, continue through the Refund API path below.
if ($transactionStatus === 'capture') {
    try {
        $cancelResponse = midtransCancelTransaction($orderId);
        $cancelOrderId = $cancelResponse['order_id'] ?? null;
        $cancelAmount = $normalizeAmount($cancelResponse['gross_amount'] ?? null);

        if (
            !is_string($cancelOrderId) ||
            !hash_equals($orderId, $cancelOrderId) ||
            ($cancelResponse['transaction_status'] ?? '') !== 'cancel' ||
            $cancelAmount !== $paymentAmount
        ) {
            throw new RuntimeException('Midtrans cancellation response could not be verified.');
        }

        $finalizeCardCancellation();
        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'cancelled',
            'refund_status' => 'not_requested',
            'message' => 'The captured card payment was voided through Midtrans. Your bank may take up to one business day to restore the available card limit.'
        ]);
    } catch (Throwable $e) {
        error_log('Midtrans captured-card cancellation attempt: ' . $e->getMessage());

        try {
            $latestTransaction = midtransGetTransactionStatus($orderId);
            $latestOrderId = $latestTransaction['order_id'] ?? null;
            $latestAmount = $normalizeAmount($latestTransaction['gross_amount'] ?? null);

            if (
                !is_string($latestOrderId) ||
                !hash_equals($orderId, $latestOrderId) ||
                $latestAmount !== $paymentAmount
            ) {
                throw new RuntimeException('Updated Midtrans transaction could not be verified.');
            }

            $latestStatus = $latestTransaction['transaction_status'] ?? '';

            if ($latestStatus === 'cancel') {
                $finalizeCardCancellation();
                jsonResponse(200, [
                    'success' => true,
                    'payment_status' => 'cancelled',
                    'refund_status' => 'not_requested',
                    'message' => 'Midtrans cancelled the card payment. Your bank may take up to one business day to restore the available card limit.'
                ]);
            }

            if ($latestStatus === 'capture') {
                savePendingMidtransRefundIntent(
                    $pdo,
                    (int) $reservationId,
                    (int) $userId,
                    $isAdmin
                );

                // Close the race where settlement happened while the intent
                // was being saved and its first webhook arrived too early.
                $latestTransaction = midtransGetTransactionStatus($orderId);
                $latestOrderId = $latestTransaction['order_id'] ?? null;
                $latestAmount = $normalizeAmount($latestTransaction['gross_amount'] ?? null);
                if (
                    !is_string($latestOrderId) ||
                    !hash_equals($orderId, $latestOrderId) ||
                    $latestAmount !== $paymentAmount
                ) {
                    throw new RuntimeException('Updated Midtrans transaction could not be verified.');
                }

                $latestStatus = $latestTransaction['transaction_status'] ?? '';
                if ($latestStatus === 'cancel') {
                    $finalizeCardCancellation();
                    jsonResponse(200, [
                        'success' => true,
                        'payment_status' => 'cancelled',
                        'refund_status' => 'not_requested',
                        'message' => 'Midtrans cancelled the card payment. Your bank may take up to one business day to restore the available card limit.'
                    ]);
                }

                if ($latestStatus === 'capture') {
                    jsonResponse(200, [
                        'success' => true,
                        'payment_status' => 'paid',
                        'refund_status' => 'pending',
                        'message' => 'Cancellation saved. If Midtrans settles this payment, the refund will be submitted automatically.'
                    ]);
                }
            }

            if ($latestStatus !== 'settlement') {
                jsonResponse(503, [
                    'success' => false,
                    'payment_transaction_status' => $latestStatus !== '' ? $latestStatus : 'unknown',
                    'message' => 'Midtrans could not confirm cancellation or settlement. Please check the transaction status before retrying.'
                ]);
            }

            $transaction = $latestTransaction;
            $transactionStatus = 'settlement';
        } catch (Throwable $statusError) {
            error_log('Midtrans cancellation status recheck error: ' . $statusError->getMessage());
            jsonResponse(503, [
                'success' => false,
                'message' => 'Unable to confirm whether Midtrans cancelled or settled this payment. Please retry after checking its status.'
            ]);
        }
    }
}

// A full refund reported by Midtrans must match our stored refund key.

// Verify that Midtrans recorded the exact refund requested by this customer.

if ($transactionStatus === 'refund') {
    $storedRefundKey = $booking['refund_key'] ?? null;
    $refundRecords = $transaction['refunds'] ?? [];

    if (
        !is_string($storedRefundKey) ||
        $storedRefundKey === '' ||
        !is_array($refundRecords)
    ) {
        error_log('Midtrans reports refund without a valid local refund reference.');

        jsonResponse(409, [
            'success' => false,
            'refund_status' => $booking['refund_status'],
            'message' => 'We could not verify your refund reference. Please contact the barbershop.'
        ]);
    }

    $matchingRefund = null;

    foreach ($refundRecords as $refundRecord) {
        if (
            is_array($refundRecord) &&
            isset($refundRecord['refund_key']) &&
            is_string($refundRecord['refund_key']) &&
            hash_equals($storedRefundKey, $refundRecord['refund_key'])
        ) {
            $matchingRefund = $refundRecord;
            break;
        }
    }

    if ($matchingRefund === null) {
        error_log('Midtrans refund status has no matching local refund key.');

        jsonResponse(409, [
            'success' => false,
            'refund_status' => $booking['refund_status'],
            'message' => 'The refund could not be matched to your request. Please contact the barbershop.'
        ]);
    }

    $recordedRefundAmount = $normalizeAmount(
        $matchingRefund['refund_amount'] ?? null
    );

    if (
        $recordedRefundAmount === null ||
        $recordedRefundAmount !== $paymentAmount
    ) {
        error_log('Midtrans refund amount does not match the original payment.');

        jsonResponse(409, [
            'success' => false,
            'refund_status' => $booking['refund_status'],
            'message' => 'The recorded refund amount could not be verified. Please contact the barbershop.'
        ]);
    }

    // If the bank has already confirmed the refund, finish it now instead of
    // waiting for a webhook that may never reach this server.
    try {
        $completion = completeConfirmedMidtransRefund(
            $pdo,
            $orderId,
            $transaction
        );
    } catch (Throwable $e) {
        error_log('Refund completion error: ' . $e->getMessage());

        $completion = 'error';
    }

    if (
        $completion === 'completed' ||
        $completion === 'already_completed'
    ) {
        jsonResponse(200, [
            'success' => true,
            'payment_status' => 'refunded',
            'refund_status' => 'completed',
            'message' => 'Your refund has been confirmed by the bank.'
        ]);
    }

    jsonResponse(200, [
        'success' => true,
        'refund_status' => 'pending',
        'message' => 'Midtrans has recorded your full refund request. Waiting for bank confirmation.'
    ]);
}


// Never submit another full refund when Midtrans reports a partial refund.
if ($transactionStatus === 'partial_refund') {
    error_log('Midtrans reports a partial refund. Automatic full refund retry blocked: ' . $orderId);

    jsonResponse(409, [
        'success' => false,
        'refund_status' => $booking['refund_status'],
        'message' => 'Midtrans reports a partial refund. Another refund request has been blocked to prevent an incorrect additional refund. Please contact the barbershop.'
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
            p.payment_status::text AS payment_status,
            p.refund_status,
            p.refund_key,
            p.refund_method,
            CASE
                WHEN (clock_timestamp() AT TIME ZONE 'Asia/Jakarta')
                     < i.cancellation_deadline
                THEN 1
                ELSE 0
            END AS within_window
        FROM reservation r
        INNER JOIN invoice i
            ON i.reservation_id = r.reservation_id
        INNER JOIN payment p
            ON p.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
          AND r.user_id = :user_id
        FOR UPDATE OF r, i, p
    ");

    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $userId
    ]);

    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        $pdo->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Reservation or payment record not found.'
        ]);
    }

    if (
        ($current['reservation_status'] !== 'active' &&
         $current['refund_status'] !== 'pending') ||
        $current['payment_status'] !== 'paid'
    ) {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'The reservation or payment status has changed.'
        ]);
    }

    $refundMethod = ($current['refund_status'] === 'pending' && $current['refund_method'] === 'manual')
        ? 'manual'
        : (midtransPaymentSupportsGatewayRefund($transaction['payment_type'] ?? null)
            ? 'gateway'
            : 'manual');

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

    // Recheck the deadline before creating a new refund.
    if (
        !$isAdmin &&
        $current['refund_status'] !== 'pending' &&
        (int) $current['within_window'] !== 1
    ) {
        $pdo->rollBack();

        jsonResponse(403, [
            'success' => false,
            'message' => 'The seven-minute cancellation period has expired.'
        ]);
    }

    // Reuse the original key when retrying an existing refund.
    if ($current['refund_status'] === 'pending') {
        $refundKey = $current['refund_key'];

        if (!is_string($refundKey) || $refundKey === '') {
            $pdo->rollBack();

            jsonResponse(409, [
                'success' => false,
                'message' => 'The pending refund is missing its reference key. Please contact the barbershop.'
            ]);
        }

    } elseif ($current['refund_status'] === 'not_requested') {
        $refundKey = 'PEMON-REFUND-' .
            $booking['payment_id'] . '-' .
            bin2hex(random_bytes(8));

        $stmt = $pdo->prepare("
            UPDATE payment
            SET
                refund_status = 'pending',
                refund_method = :refund_method,
                refund_key = :refund_key,
                refund_reason = :refund_reason,
                refund_requested_at =
                    clock_timestamp() AT TIME ZONE 'Asia/Jakarta',
                updated_at =
                    clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
            WHERE payment_id = :payment_id
              AND payment_status = 'paid'
              AND refund_status = 'not_requested'
        ");

        $stmt->execute([
            ':refund_key' => $refundKey,
            ':refund_method' => $refundMethod,
            ':refund_reason' => $isAdmin
                ? 'Reservation cancelled by barbershop'
                : 'Customer cancellation within seven-minute period',
            ':payment_id' => $booking['payment_id']
        ]);

        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();

            jsonResponse(409, [
                'success' => false,
                'message' => 'The refund request state changed. Please try again.'
            ]);
        }

    } else {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'The current refund status does not allow another refund request.'
        ]);
    }

    if ($refundMethod === 'manual' && $current['refund_status'] === 'pending') {
        $stmt = $pdo->prepare("UPDATE payment SET refund_method = 'manual', updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta' WHERE payment_id = :payment_id AND refund_status = 'pending'");
        $stmt->execute([':payment_id' => $booking['payment_id']]);
    }

    // Release the reservation immediately when a settlement refund is prepared.
    $stmt = $pdo->prepare("
        UPDATE reservation
        SET
            reservation_status = 'inactive',
            admin_cancelled_at = CASE
                WHEN :is_admin THEN COALESCE(admin_cancelled_at, clock_timestamp() AT TIME ZONE 'Asia/Jakarta')
                ELSE admin_cancelled_at
            END,
            updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
        WHERE reservation_id = :reservation_id
          AND user_id = :user_id
          AND reservation_status = 'active'
    ");

    $stmt->execute([
        ':reservation_id' => $reservationId,
        ':user_id' => $userId,
        ':is_admin' => $isAdmin
    ]);

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

if ($refundMethod === 'manual') {
    jsonResponse(200, [
        'success' => true,
        'payment_status' => 'paid',
        'refund_status' => 'pending',
        'refund_method' => 'manual',
        'message' => 'Cancellation saved. This payment method must be refunded manually by the barbershop.'
    ]);
}

// Check Midtrans refund records before submitting or retrying the API request.
$existingRefund = $findMatchingRefund(
    $transaction,
    $refundKey
);

if ($existingRefund !== null) {
    if (!$verifyFullRefundRecord($existingRefund, $paymentAmount)) {
        error_log('Existing Midtrans refund amount does not match the original payment: ' . $orderId);

        jsonResponse(409, [
            'success' => false,
            'refund_status' => 'pending',
            'message' => 'An existing Midtrans refund does not match the full payment amount. No additional refund was submitted. Please contact the barbershop.'
        ]);
    }

    // The notification handler finalizes the refund after bank confirmation.
    jsonResponse(200, [
        'success' => true,
        'refund_status' => 'pending',
        'message' => 'Midtrans already has this refund request. Waiting for refund confirmation.'
    ]);
}


// Check Midtrans refund history before submitting or retrying.

$refundRecords = $transaction['refunds'] ?? [];

if (is_array($refundRecords)) {
    foreach ($refundRecords as $refundRecord) {
        if (
            !is_array($refundRecord) ||
            !isset($refundRecord['refund_key']) ||
            !is_string($refundRecord['refund_key']) ||
            !hash_equals($refundKey, $refundRecord['refund_key'])
        ) {
            continue;
        }

        $recordedAmount = $normalizeAmount(
            $refundRecord['refund_amount'] ?? null
        );

        if (
            $recordedAmount === null ||
            $recordedAmount !== $paymentAmount
        ) {
            error_log('Existing Midtrans refund amount mismatch.');

            jsonResponse(409, [
                'success' => false,
                'refund_status' => 'pending',
                'message' => 'The existing refund amount does not match your payment. Please contact the barbershop.'
            ]);
        }

        jsonResponse(200, [
            'success' => true,
            'refund_status' => 'pending',
            'message' => 'Midtrans has already acknowledged your refund. Waiting for bank confirmation.'
        ]);
    }
}


// Submit the full refund using the same stored key.
try {
    $refundResponse = midtransRequestRefund(
        $transactionIdentifier,
        [
            'refund_key' => $refundKey,
            'amount' => $paymentAmount,
            'reason' => $isAdmin
                ? 'Reservation cancelled by barbershop'
                : 'Customer cancellation within seven-minute period'
        ]
    );


$refundResponseCode = (string) (
    $refundResponse['status_code'] ?? ''
);

// Midtrans answers 202 when the bank denies the refund. No money is returned.
$refundAmountIsZero =
    !isset($refundResponse['refund_amount']) ||
    (
        is_numeric($refundResponse['refund_amount']) &&
        (float) $refundResponse['refund_amount'] === 0.0
    );

$refundKeyMatchesOrIsAbsent =
    !isset($refundResponse['refund_key']) ||
    (
        is_string($refundResponse['refund_key']) &&
        hash_equals($refundKey, $refundResponse['refund_key'])
    );

if (
    $refundResponseCode === '202' &&
    $refundAmountIsZero &&
    $refundKeyMatchesOrIsAbsent
) {
    $stmt = $pdo->prepare("
        UPDATE payment
        SET
            refund_status = 'failed',
            updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
        WHERE payment_id = :payment_id
          AND refund_key = :refund_key
          AND refund_status = 'pending'
          AND payment_status = 'paid'
    ");

    $stmt->execute([
        ':payment_id' => $booking['payment_id'],
        ':refund_key' => $refundKey
    ]);

    error_log(
        'Midtrans denied the refund request. Payment ID: ' .
        $booking['payment_id']
    );

    jsonResponse(409, [
        'success' => false,
        'refund_status' => 'failed',
        'message' => 'The bank rejected this refund request. Please contact the barbershop for assistance.'
    ]);
}


    // Anything other than a plain success is not a confirmed refund request.
    if ($refundResponseCode !== '200') {
        error_log(
            'Midtrans refund returned unexpected status_code: ' .
            $refundResponseCode
        );

        jsonResponse(502, [
            'success' => false,
            'refund_status' => 'pending',
            'message' => 'The refund request could not be confirmed. Its existing reference has been preserved. Please check the reservation status before retrying.'
        ]);
    }

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
        'message' => 'The refund request could not be confirmed. Its existing reference has been preserved. Please check the reservation status before retrying.'
    ]);
}
