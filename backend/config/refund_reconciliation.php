<?php
declare(strict_types=1);

// Midtrans refund reconciliation
// A refund is only complete when Midtrans reports a refund record that has
// OUR refund key, the full payment amount and a non-empty bank_confirmed_at.
// The notification webhook is the normal way to learn this, but a webhook
// cannot reach a developer machine and can also be delayed or lost. These
// helpers run the same checks directly, so the customer pages can finish a
// refund that the bank has already confirmed.

if (!function_exists('midtransNormalizeAmount')) {
    // Normalize monetary values without floating-point arithmetic.
    function midtransNormalizeAmount(mixed $amount): ?string
    {
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

        return $whole . '.' . str_pad($matches[2] ?? '', 2, '0');
    }
}

if (!function_exists('completeConfirmedMidtransRefund')) {
    /**
     * Complete a pending refund when Midtrans says the bank confirmed it.
     *
     * $transaction is the response of Midtrans' Get Status API.
     *
     * Returns one of:
     *   completed         the refund was completed by this call
     *   already_completed it had been completed before
     *   not_confirmed     Midtrans has not reported a bank-confirmed full refund
     *   no_pending_refund nothing here is waiting for a refund
     *   not_found         no payment exists for this order
     *   amount_mismatch   the amounts do not match, nothing was changed
     */
    function completeConfirmedMidtransRefund(
        PDO $pdo,
        string $orderId,
        array $transaction
    ): string {
        // Only a full refund can complete a reservation refund.
        if (($transaction['transaction_status'] ?? '') !== 'refund') {
            return 'not_confirmed';
        }

        $verifiedOrderId = $transaction['order_id'] ?? null;

        if (
            !is_string($verifiedOrderId) ||
            !hash_equals($orderId, $verifiedOrderId)
        ) {
            return 'not_confirmed';
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT
                    p.payment_id,
                    p.reservation_id,
                    p.amount,
                    p.refund_key,
                    p.refund_status,
                    p.refund_method,
                    p.payment_status::text AS payment_status
                FROM payment p
                INNER JOIN reservation r
                    ON r.reservation_id = p.reservation_id
                WHERE p.transaction_reference = :order_id
                LIMIT 1
                FOR UPDATE OF p, r
            ");

            $stmt->execute([':order_id' => $orderId]);

            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                $pdo->rollBack();

                return 'not_found';
            }

            if ($payment['refund_status'] === 'completed') {
                $pdo->commit();

                return 'already_completed';
            }

            $refundKey = $payment['refund_key'];

            if (
                $payment['payment_status'] !== 'paid' ||
                $payment['refund_status'] !== 'pending' ||
                $payment['refund_method'] !== 'gateway' ||
                !is_string($refundKey) ||
                $refundKey === ''
            ) {
                $pdo->commit();

                return 'no_pending_refund';
            }

            // Find the refund request that carries our own refund key.
            $matchingRefund = null;
            $refundRecords = $transaction['refunds'] ?? null;

            if (is_array($refundRecords)) {
                foreach ($refundRecords as $refundRecord) {
                    if (
                        is_array($refundRecord) &&
                        isset($refundRecord['refund_key']) &&
                        is_string($refundRecord['refund_key']) &&
                        hash_equals($refundKey, $refundRecord['refund_key'])
                    ) {
                        $matchingRefund = $refundRecord;
                        break;
                    }
                }
            }

            // An accepted refund request is not complete until the bank confirms.
            $bankConfirmedAt = is_array($matchingRefund)
                ? ($matchingRefund['bank_confirmed_at'] ?? null)
                : null;

            if (
                !is_string($bankConfirmedAt) ||
                trim($bankConfirmedAt) === ''
            ) {
                $pdo->commit();

                return 'not_confirmed';
            }

            // Only a full refund of the original payment completes it.
            $paymentAmount = midtransNormalizeAmount($payment['amount']);
            $grossAmount = midtransNormalizeAmount(
                $transaction['gross_amount'] ?? null
            );
            $refundAmount = midtransNormalizeAmount(
                $matchingRefund['refund_amount'] ?? null
            );

            if (
                $paymentAmount === null ||
                $grossAmount !== $paymentAmount ||
                $refundAmount !== $paymentAmount
            ) {
                $pdo->rollBack();

                error_log(
                    'Confirmed refund amount does not match the original payment: ' .
                    $orderId
                );

                return 'amount_mismatch';
            }

            $stmt = $pdo->prepare("
                UPDATE payment
                SET
                    payment_status = 'refunded',
                    refund_status = 'completed',
                    refunded_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta',
                    updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                WHERE payment_id = :payment_id
                  AND payment_status = 'paid'
                  AND refund_status = 'pending'
                  AND refund_method = 'gateway'
            ");

            $stmt->execute([':payment_id' => $payment['payment_id']]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Payment state changed while completing the refund.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE reservation
                SET
                    reservation_status = 'inactive',
                    updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                WHERE reservation_id = :reservation_id
            ");

            $stmt->execute([':reservation_id' => $payment['reservation_id']]);

            $pdo->commit();

            return 'completed';

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}

if (!function_exists('reconcilePendingRefundsForCustomer')) {
    /**
     * Check this customer's pending refunds with Midtrans and complete the ones
     * the bank has confirmed. It never throws: a Midtrans problem must not stop
     * the customer from seeing their reservations.
     */
    function reconcilePendingRefundsForCustomer(
        PDO $pdo,
        int $userId,
        int $limit = 3
    ): void {
        try {
            $stmt = $pdo->prepare("
                SELECT p.transaction_reference
                FROM payment p
                INNER JOIN reservation r
                    ON r.reservation_id = p.reservation_id
                WHERE r.user_id = :user_id
                  AND p.payment_status = 'paid'
                  AND p.refund_status = 'pending'
                  AND p.refund_method = 'gateway'
                  AND p.transaction_reference IS NOT NULL
                ORDER BY p.refund_requested_at ASC
                LIMIT " . max(1, $limit) . "
            ");

            $stmt->execute([':user_id' => $userId]);

            $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            error_log('Pending refund lookup error: ' . $e->getMessage());

            return;
        }

        foreach ($orderIds as $orderId) {
            if (!is_string($orderId) || $orderId === '') {
                continue;
            }

            try {
                $transaction = midtransGetTransactionStatus($orderId);

                completeConfirmedMidtransRefund($pdo, $orderId, $transaction);

            } catch (Throwable $e) {
                error_log(
                    'Pending refund reconciliation error for ' . $orderId .
                    ': ' . $e->getMessage()
                );
            }
        }
    }
}

if (!function_exists('submitPendingMidtransRefund')) {
    /**
     * Submit a previously requested refund after Midtrans settles the charge.
     * The stored refund key makes repeated settlement notifications safe.
     */
    function submitPendingMidtransRefund(
        PDO $pdo,
        string $orderId,
        array $transaction
    ): string {
        if (($transaction['transaction_status'] ?? '') !== 'settlement') {
            return 'not_settled';
        }

        if (
            !isset($transaction['order_id']) ||
            !is_string($transaction['order_id']) ||
            !hash_equals($orderId, $transaction['order_id'])
        ) {
            return 'order_mismatch';
        }

        $stmt = $pdo->prepare("
            SELECT p.payment_id, p.amount, p.refund_status, p.refund_key,
                   p.refund_reason,
                   p.refund_method,
                   p.payment_status::text AS payment_status,
                   p.transaction_reference
            FROM payment p
            WHERE p.transaction_reference = :order_id
            LIMIT 1
        ");
        $stmt->execute([':order_id' => $orderId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment || $payment['payment_status'] !== 'paid') {
            return 'no_payment';
        }

        if (!midtransPaymentSupportsGatewayRefund($transaction['payment_type'] ?? null)) {
            $stmt = $pdo->prepare("
                UPDATE payment
                SET refund_method = 'manual',
                    updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                WHERE payment_id = :payment_id
                  AND payment_status = 'paid'
                  AND refund_status = 'pending'
            ");
            $stmt->execute([':payment_id' => $payment['payment_id']]);
            return 'manual_required';
        }

        if ($payment['refund_status'] !== 'pending' || $payment['refund_method'] !== 'gateway') {
            return 'no_pending_refund';
        }

        $refundKey = $payment['refund_key'];
        if (!is_string($refundKey) || $refundKey === '') {
            throw new RuntimeException('Pending refund is missing its refund key.');
        }

        $amount = midtransNormalizeAmount($payment['amount']);
        if ($amount === null || str_ends_with($amount, '.00') === false) {
            throw new RuntimeException('Pending refund amount is invalid.');
        }
        $amount = substr($amount, 0, -3);

        $refunds = $transaction['refunds'] ?? [];
        if (is_array($refunds)) {
            foreach ($refunds as $refund) {
                if (
                    is_array($refund) &&
                    isset($refund['refund_key']) &&
                    is_string($refund['refund_key']) &&
                    hash_equals($refundKey, $refund['refund_key'])
                ) {
                    return 'already_submitted';
                }
            }
        }

        $identifier = $transaction['transaction_id'] ?? $orderId;
        if (!is_string($identifier) || $identifier === '') {
            $identifier = $orderId;
        }

        $response = midtransRequestRefund($identifier, [
            'refund_key' => $refundKey,
            'amount' => $amount,
            'reason' => $payment['refund_reason'] ?: 'Customer cancellation within seven-minute period'
        ]);

        $statusCode = (string) ($response['status_code'] ?? '');
        if ($statusCode === '202') {
            $stmt = $pdo->prepare("
                UPDATE payment
                SET refund_status = 'failed',
                    updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                WHERE payment_id = :payment_id
                  AND refund_key = :refund_key
                  AND payment_status = 'paid'
                  AND refund_status = 'pending'
                  AND refund_method = 'gateway'
            ");
            $stmt->execute([
                ':payment_id' => $payment['payment_id'],
                ':refund_key' => $refundKey
            ]);
            return 'rejected';
        }

        if ($statusCode !== '200') {
            throw new RuntimeException(
                'Unexpected Midtrans refund status: ' . $statusCode
            );
        }

        $returnedKey = $response['refund_key'] ?? null;
        if (
            is_string($returnedKey) &&
            !hash_equals($refundKey, $returnedKey)
        ) {
            throw new RuntimeException('Midtrans returned a different refund key.');
        }

        return 'submitted';
    }
}

if (!function_exists('savePendingMidtransRefundIntent')) {
    /** Save a cancellation refund intent so a later settlement webhook can act. */
    function savePendingMidtransRefundIntent(
        PDO $pdo,
        int $reservationId,
        int $userId,
        bool $cancelledByAdmin = false
    ): string {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT p.payment_id, p.payment_status::text AS payment_status,
                       p.refund_status, p.refund_key
                FROM reservation r
                INNER JOIN payment p ON p.reservation_id = r.reservation_id
                WHERE r.reservation_id = :reservation_id
                  AND r.user_id = :user_id
                FOR UPDATE OF r, p
            ");
            $stmt->execute([
                ':reservation_id' => $reservationId,
                ':user_id' => $userId
            ]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment || $payment['payment_status'] !== 'paid') {
                throw new RuntimeException('Payment is no longer eligible for cancellation.');
            }

            if ($payment['refund_status'] === 'pending') {
                $refundKey = $payment['refund_key'];
                if (!is_string($refundKey) || $refundKey === '') {
                    throw new RuntimeException('Pending refund is missing its reference key.');
                }
            } elseif ($payment['refund_status'] === 'not_requested') {
                $refundKey = 'PEMON-REFUND-' . $payment['payment_id'] . '-' . bin2hex(random_bytes(8));
                $stmt = $pdo->prepare("
                    UPDATE payment
                    SET refund_status = 'pending',
                        refund_method = 'gateway',
                        refund_key = :refund_key,
                        refund_reason = :reason,
                        refund_requested_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta',
                        updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                    WHERE payment_id = :payment_id
                      AND payment_status = 'paid'
                      AND refund_status = 'not_requested'
                ");
                $stmt->execute([
                    ':refund_key' => $refundKey,
                    ':reason' => $cancelledByAdmin
                        ? 'Reservation cancelled by barbershop'
                        : 'Customer cancellation within seven-minute period',
                    ':payment_id' => $payment['payment_id']
                ]);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('Unable to save the refund intent.');
                }
            } else {
                throw new RuntimeException('Payment refund state does not allow a new intent.');
            }

            $stmt = $pdo->prepare("
                UPDATE reservation
                SET reservation_status = 'inactive',
                    admin_cancelled_at = CASE
                        WHEN :cancelled_by_admin THEN COALESCE(admin_cancelled_at, clock_timestamp() AT TIME ZONE 'Asia/Jakarta')
                        ELSE admin_cancelled_at
                    END,
                    updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
                WHERE reservation_id = :reservation_id
                  AND user_id = :user_id
            ");
            $stmt->execute([
                ':reservation_id' => $reservationId,
                ':user_id' => $userId,
                ':cancelled_by_admin' => $cancelledByAdmin
            ]);

            $pdo->commit();
            return $refundKey;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
