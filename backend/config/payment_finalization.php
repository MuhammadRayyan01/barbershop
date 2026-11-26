<?php
declare(strict_types=1);

function finalizeVerifiedMidtransPayment(
    PDO $pdo,
    array $transaction
): array {
    // Validate verified transaction data.
    $orderId = $transaction['order_id'] ?? null;
    $transactionStatus = $transaction['transaction_status'] ?? '';
    $fraudStatus = $transaction['fraud_status'] ?? '';

    if (!is_string($orderId) || $orderId === '') {
        throw new InvalidArgumentException('Invalid Midtrans order ID.');
    }

    $isPaid = (
        $transactionStatus === 'settlement'
        ||
        (
            $transactionStatus === 'capture'
            && $fraudStatus === 'accept'
        )
    );

    if (!$isPaid) {
        throw new RuntimeException('Transaction is not successfully paid.');
    }

    $grossAmount = $transaction['gross_amount'] ?? null;

    if (
        !is_string($grossAmount) &&
        !is_int($grossAmount) &&
        !is_float($grossAmount)
    ) {
        throw new RuntimeException('Invalid transaction amount.');
    }

    $grossAmount = (string) $grossAmount;

    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $grossAmount, $matches)) {
        throw new RuntimeException('Invalid transaction amount format.');
    }

    $amountWhole = ltrim($matches[1], '0');
    $amountWhole = $amountWhole === '' ? '0' : $amountWhole;

    $amountDecimal = str_pad($matches[2] ?? '', 2, '0');

    if ($amountDecimal !== '00') {
        throw new RuntimeException('Indonesian Rupiah amount must be a whole number.');
    }

    $paidAmount = $amountWhole;

    // Require this function to manage its own transaction.
    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Payment finalization requires its own transaction.'
        );
    }

    $pdo->beginTransaction();

    try {
        // Find the booking hold and appointment time.
        $findHold = $pdo->prepare("
            SELECT
                bh.hold_id,
                bh.user_id,
                bh.slot_id,
                bh.service_id,
                bh.service_price,
                bh.additional_service_id,
                bh.additional_service_price,
                bh.total_amount,
                bh.hold_status,
                bh.expires_at,
                bh.midtrans_order_id,
                bh.reservation_id,
                ts.slot_date,
                ts.start_time
            FROM booking_hold bh
            INNER JOIN time_slot ts
                ON ts.slot_id = bh.slot_id
            WHERE bh.midtrans_order_id = :order_id
            LIMIT 1
        ");

        $findHold->execute([
            ':order_id' => $orderId
        ]);

        $initialHold = $findHold->fetch(PDO::FETCH_ASSOC);

        if (!$initialHold) {
            throw new RuntimeException('Booking hold not found.');
        }

        // Acquire the same hourly advisory lock used by booking creation.
        $slotDate = (string) $initialHold['slot_date'];
        $startTime = (string) $initialHold['start_time'];

        $hour = (int) substr($startTime, 0, 2);

        $lockKey = $slotDate . ':' . str_pad(
            (string) $hour,
            2,
            '0',
            STR_PAD_LEFT
        );

        $lockStmt = $pdo->prepare("
            SELECT pg_advisory_xact_lock(
                hashtextextended(:lock_key, 0)
            )
        ");

        $lockStmt->execute([
            ':lock_key' => $lockKey
        ]);

        // Lock the hold and recheck its current state.
        $lockHold = $pdo->prepare("
            SELECT
                bh.*,
                ts.slot_date,
                ts.start_time
            FROM booking_hold bh
            INNER JOIN time_slot ts
                ON ts.slot_id = bh.slot_id
            WHERE bh.hold_id = :hold_id
            FOR UPDATE OF bh
        ");

        $lockHold->execute([
            ':hold_id' => $initialHold['hold_id']
        ]);

        $hold = $lockHold->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            throw new RuntimeException('Booking hold no longer exists.');
        }

        // Verify order ID and amount.
        if (
            !is_string($hold['midtrans_order_id']) ||
            !hash_equals($hold['midtrans_order_id'], $orderId)
        ) {
            throw new RuntimeException('Booking order ID mismatch.');
        }

        $holdAmount = (string) $hold['total_amount'];

        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $holdAmount, $holdMatches)) {
            throw new RuntimeException('Invalid booking amount.');
        }

        $holdWhole = ltrim($holdMatches[1], '0');
        $holdWhole = $holdWhole === '' ? '0' : $holdWhole;

        $holdDecimal = str_pad($holdMatches[2] ?? '', 2, '0');

        if (
            $holdDecimal !== '00' ||
            $holdWhole !== $paidAmount
        ) {
            throw new RuntimeException('Paid amount does not match booking amount.');
        }

        // Return the existing reservation if this payment was already processed.
        if (
            $hold['hold_status'] === 'converted' &&
            !empty($hold['reservation_id'])
        ) {
            $reservationId = (int) $hold['reservation_id'];

            $pdo->commit();

            return [
                'reservation_id' => $reservationId,
                'order_id' => $orderId,
                'already_processed' => true
            ];
        }

        if ($hold['hold_status'] !== 'active') {
            throw new RuntimeException(
                'Booking hold is no longer active.'
            );
        }

        // Verify that the temporary hold has not expired.
        $expiryStmt = $pdo->prepare("
            SELECT expires_at > CURRENT_TIMESTAMP
            FROM booking_hold
            WHERE hold_id = :hold_id
        ");

        $expiryStmt->execute([
            ':hold_id' => $hold['hold_id']
        ]);

        $isUnexpired = $expiryStmt->fetchColumn();

        if (!in_array($isUnexpired, [true, 1, '1', 't', 'true'], true)) {
            throw new RuntimeException(
                'Payment succeeded, but the booking hold has expired. Manual payment review is required.'
            );
        }

        // Check shared hourly booking capacity.
        $capacityStmt = $pdo->prepare("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM reservation r
                    INNER JOIN time_slot ts
                        ON ts.slot_id = r.slot_id
                    WHERE r.reservation_status = 'active'
                      AND ts.slot_date = :reservation_date
                      AND EXTRACT(HOUR FROM ts.start_time) = :reservation_hour
                )
                +
                (
                    SELECT COUNT(*)
                    FROM booking_hold bh
                    INNER JOIN time_slot ts
                        ON ts.slot_id = bh.slot_id
                    WHERE bh.hold_status = 'active'
                      AND bh.expires_at > CURRENT_TIMESTAMP
                      AND bh.hold_id <> :current_hold_id
                      AND ts.slot_date = :hold_date
                      AND EXTRACT(HOUR FROM ts.start_time) = :hold_hour
                )
                AS total_bookings
        ");

        $capacityStmt->execute([
            ':reservation_date' => $slotDate,
            ':reservation_hour' => $hour,
            ':current_hold_id' => $hold['hold_id'],
            ':hold_date' => $slotDate,
            ':hold_hour' => $hour
        ]);

        $totalBookings = (int) $capacityStmt->fetchColumn();

        $configuredCapacityStmt = $pdo->prepare("SELECT COALESCE(MIN(booking_capacity), 0)
            FROM time_slot
            WHERE slot_date = CAST(:slot_date AS date)
              AND EXTRACT(HOUR FROM start_time) = :slot_hour");
        $configuredCapacityStmt->execute([
            ':slot_date' => $slotDate,
            ':slot_hour' => $hour,
        ]);
        $configuredCapacity = (int) $configuredCapacityStmt->fetchColumn();

        if ($totalBookings >= $configuredCapacity) {
            throw new RuntimeException(
                'Booking capacity has been reached. Manual payment review is required.'
            );
        }

        // Retrieve customer information.
        $customerStmt = $pdo->prepare("
            SELECT
                full_name,
                phone_number
            FROM users
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $customerStmt->execute([
            ':user_id' => $hold['user_id']
        ]);

        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            throw new RuntimeException('Customer account not found.');
        }

        // Record the successful payment verification time using Jakarta time.
        $verifiedAtStmt = $pdo->query("
            SELECT clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
        ");

        $verifiedAt = $verifiedAtStmt->fetchColumn();

        if (!is_string($verifiedAt) || $verifiedAt === '') {
            throw new RuntimeException('Unable to determine payment verification time.');
        }

        // Convert the temporary hold before creating the reservation.
        $convertHold = $pdo->prepare("
            UPDATE booking_hold
            SET
                hold_status = 'converted',
                updated_at = CURRENT_TIMESTAMP
            WHERE hold_id = :hold_id
              AND hold_status = 'active'
        ");

        $convertHold->execute([
            ':hold_id' => $hold['hold_id']
        ]);

        if ($convertHold->rowCount() !== 1) {
            throw new RuntimeException('Unable to convert booking hold.');
        }

        // Create permanent reservation.
        $reservationStmt = $pdo->prepare("
            INSERT INTO reservation (
                user_id,
                slot_id,
                service_id,
                service_price,
                additional_service_id,
                additional_service_price,
                customer_message,
                reservation_status,
                created_at,
                updated_at
            )
            VALUES (
                :user_id,
                :slot_id,
                :service_id,
                :service_price,
                :additional_service_id,
                :additional_service_price,
                :customer_message,
                'active',
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            RETURNING reservation_id
        ");

        $reservationStmt->execute([
            ':user_id' => $hold['user_id'],
            ':slot_id' => $hold['slot_id'],
            ':service_id' => $hold['service_id'],
            ':service_price' => $hold['service_price'],
            ':additional_service_id' => $hold['additional_service_id'],
            ':additional_service_price' => $hold['additional_service_price'],
            ':customer_message' => $hold['customer_message']
        ]);

        $reservationId = (int) $reservationStmt->fetchColumn();

        // Link the converted hold to its permanent reservation.
        $linkStmt = $pdo->prepare("
            UPDATE booking_hold
            SET
                reservation_id = :reservation_id,
                updated_at = CURRENT_TIMESTAMP
            WHERE hold_id = :hold_id
        ");

        $linkStmt->execute([
            ':reservation_id' => $reservationId,
            ':hold_id' => $hold['hold_id']
        ]);

        // Create invoice with a seven-minute cancellation deadline.
        $invoiceStmt = $pdo->prepare("
            INSERT INTO invoice (
                reservation_id,
                customer_name,
                phone_number,
                total_payment,
                cancellation_deadline,
                created_at
            )
            VALUES (
                :reservation_id,
                :customer_name,
                :phone_number,
                :total_payment,
                CAST(:verified_at AS timestamp) + INTERVAL '7 minutes',
                CAST(:verified_at AS timestamp)
            )
            RETURNING invoice_id
        ");

        $invoiceStmt->execute([
            ':reservation_id' => $reservationId,
            ':customer_name' => $customer['full_name'],
            ':phone_number' => $customer['phone_number'],
            ':total_payment' => $hold['total_amount'],
            ':verified_at' => $verifiedAt
        ]);

        $invoiceId = (int) $invoiceStmt->fetchColumn();

        // Create paid payment record using the same verification timestamp.
        $paymentStmt = $pdo->prepare("
            INSERT INTO payment (
                reservation_id,
                payment_type,
                payment_status,
                amount,
                transaction_reference,
                created_at,
                updated_at
            )
            VALUES (
                :reservation_id,
                'Midtrans',
                'paid',
                :amount,
                :transaction_reference,
                CAST(:verified_at AS timestamp),
                CAST(:verified_at AS timestamp)
            )
            RETURNING payment_id
        ");

        $paymentStmt->execute([
            ':reservation_id' => $reservationId,
            ':amount' => $hold['total_amount'],
            ':transaction_reference' => $orderId,
            ':verified_at' => $verifiedAt
        ]);

        $paymentId = (int) $paymentStmt->fetchColumn();

        // Commit the reservation, invoice, payment, and converted hold together.
        $pdo->commit();

        return [
            'reservation_id' => $reservationId,
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'payment_status' => 'paid',
            'already_processed' => false
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
