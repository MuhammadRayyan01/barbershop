<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../config/load_env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!function_exists('jsonResponse')) {
    function jsonResponse(int $status, array $data): never
    {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(401, [
        'success' => false,
        'message' => 'Please login first.'
    ]);
}

// Validate CSRF token
requireValidCsrfToken();

$serverKey = getenv('MIDTRANS_SERVER_KEY');

$isProduction = filter_var(
    getenv('MIDTRANS_IS_PRODUCTION') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
);
$snapRedirectBase = $isProduction
    ? 'https://app.midtrans.com/snap/v2/vtweb/'
    : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/';

if (!$serverKey || trim($serverKey) === '') {
    jsonResponse(503, [
        'success' => false,
        'message' => 'Payment service is not configured.'
    ]);
}

// Read JSON request
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Invalid JSON request.'
    ]);
}

$reservationId = filter_var(
    $input['reservation_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($reservationId === false || $reservationId === null) {
    jsonResponse(400, [
        'success' => false,
        'message' => 'A valid reservation_id is required.'
    ]);
}

$connection = $pdo;
$paymentId = null;
$orderId = null;
$amount = null;
$reservation = null;

try {

    $connection->beginTransaction();

    $reservationQuery = $connection->prepare("
        SELECT
            r.reservation_id,
            r.user_id,
            r.service_price,
            r.additional_service_price,
            r.reservation_status,
            u.full_name,
            u.phone_number
        FROM reservation AS r
        JOIN users AS u
            ON u.user_id = r.user_id
        WHERE r.reservation_id = :reservation_id
          AND r.user_id = :user_id
        FOR UPDATE OF r
    ");

    $reservationQuery->execute([
        'reservation_id' => $reservationId,
        'user_id' => $_SESSION['user_id']
    ]);

    $reservation = $reservationQuery->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        $connection->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Reservation not found.'
        ]);
    }

    if ($reservation['reservation_status'] !== 'active') {
        $connection->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'This reservation is not available.'
        ]);
    }


   // Get the invoice amount
$invoiceQuery = $connection->prepare("
    SELECT total_payment
    FROM invoice
    WHERE reservation_id = :reservation_id
    FOR UPDATE
");

$invoiceQuery->execute([
    'reservation_id' => $reservationId
]);

$invoice = $invoiceQuery->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    $connection->rollBack();

    jsonResponse(409, [
        'success' => false,
        'message' => 'Invoice not found for this reservation.'
    ]);
}

// Use the invoice amount as the payment amount
$amount = (float) $invoice['total_payment'];

if (
    !is_finite($amount) ||
    $amount <= 0 ||
    $amount !== (float) round($amount)
) {
    $connection->rollBack();

    jsonResponse(409, [
        'success' => false,
        'message' => 'Invoice amount is invalid.'
    ]);
}

    $amount = (float) $reservation['service_price']
        + (float) ($reservation['additional_service_price'] ?? 0);

    if (
        !is_finite($amount) ||
        $amount <= 0 ||
        $amount !== (float) round($amount)
    ) {
        $connection->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'The reservation amount is invalid.'
        ]);
    }

    $invoiceQuery = $connection->prepare("
        SELECT total_payment
        FROM invoice
        WHERE reservation_id = :reservation_id
        FOR UPDATE
    ");

    $invoiceQuery->execute([
        'reservation_id' => $reservationId
    ]);

    $invoice = $invoiceQuery->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {

        $invoiceInsert = $connection->prepare("
            INSERT INTO invoice (
                reservation_id,
                customer_name,
                phone_number,
                total_payment,
                cancellation_deadline
            )
            VALUES (
                :reservation_id,
                :customer_name,
                :phone_number,
                :total_payment,
                CURRENT_TIMESTAMP + INTERVAL '2 hours'
            )
        ");

        $invoiceInsert->execute([
            'reservation_id' => $reservationId,
            'customer_name' => $reservation['full_name'],
            'phone_number' => $reservation['phone_number'],
            'total_payment' => $amount
        ]);

    } elseif (
        abs((float) $invoice['total_payment'] - $amount) > 0.009
    ) {
        $connection->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'Invoice amount does not match the reservation.'
        ]);
    }

    $paymentQuery = $connection->prepare("
        SELECT
            payment_id,
            payment_status::text AS payment_status,
            amount,
            transaction_reference,
            snap_token
        FROM payment
        WHERE reservation_id = :reservation_id
        FOR UPDATE
    ");

    $paymentQuery->execute([
        'reservation_id' => $reservationId
    ]);

    $payment = $paymentQuery->fetch(PDO::FETCH_ASSOC);

    if ($payment) {

        $paymentId = (int) $payment['payment_id'];

        // Validate existing payment amount
        if (abs((float) $payment['amount'] - $amount) > 0.009) {
            $connection->rollBack();

            jsonResponse(409, [
                'success' => false,
                'message' => 'Existing payment amount does not match.'
            ]);
        }

        // Check payment status
        if (
            in_array(
                $payment['payment_status'],
                ['paid', 'verification pending'],
                true
            )
        ) {
            $connection->rollBack();

            jsonResponse(409, [
                'success' => false,
                'message' => 'This reservation is already being processed or completed.'
            ]);
        }

        if (
            $payment['payment_status'] === 'pending'
            && !empty($payment['snap_token'])
        ) {
            $connection->commit();
            unset($_SESSION['booking_draft']);

            jsonResponse(200, [
                'success' => true,
                'message' => 'Reusing the existing checkout.',
                'reservation_id' => (int) $reservationId,
                'amount' => (int) round($amount),
                'snap_token' => $payment['snap_token'],
                'redirect_url' => $snapRedirectBase . rawurlencode($payment['snap_token']),
            ]);
        }

        if (
            !in_array(
                $payment['payment_status'],
                ['pending', 'failed'],
                true
            )
        ) {
            $connection->rollBack();

            jsonResponse(409, [
                'success' => false,
                'message' => 'This payment cannot be redirected in its current state.'
            ]);
        }

    } else {
        $paymentInsert = $connection->prepare("
            INSERT INTO payment (
                reservation_id,
                payment_type,
                payment_status,
                amount
            )
            VALUES (
                :reservation_id,
                'midtrans_snap',
                'pending',
                :amount
            )
            RETURNING payment_id
        ");

        $paymentInsert->execute([
            'reservation_id' => $reservationId,
            'amount' => $amount
        ]);

        $paymentId = (int) $paymentInsert->fetchColumn();
    }

    $orderId = 'PEMON-'
        . $reservationId . '-'
        . $paymentId . '-'
        . bin2hex(random_bytes(4));

    $updatePayment = $connection->prepare("
        UPDATE payment
        SET
            payment_type = 'midtrans_snap',
            payment_status = 'pending',
            transaction_reference = :order_id,
            snap_token = NULL,
            updated_at = CURRENT_TIMESTAMP
        WHERE payment_id = :payment_id
    ");

    $updatePayment->execute([
        'order_id' => $orderId,
        'payment_id' => $paymentId
    ]);

   
    $connection->commit();

    $endpoint = $isProduction
        ? 'https://app.midtrans.com/snap/v1/transactions'
        : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

    // Prepare Midtrans payload
    $payload = [
        'transaction_details' => [
            'order_id' => $orderId,
            'gross_amount' => (int) round($amount)
        ],

        'customer_details' => [
            'first_name' => $reservation['full_name'],
            'phone' => $reservation['phone_number']
        ],

        'expiry' => [
            'unit' => 'hour',
            'duration' => 1
        ]
    ];

    $applicationBaseUrl = rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/');
    if ($applicationBaseUrl !== '' && filter_var($applicationBaseUrl, FILTER_VALIDATE_URL)) {
        $payload['callbacks'] = [
            'finish' => $applicationBaseUrl . '/user/booking/success.php?reservation_id=' . $reservationId,
        ];
    }

    // Initialize cURL
    $curl = curl_init($endpoint);

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $serverKey . ':',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    // Handle connection errors
    if ($responseBody === false) {

        error_log('Midtrans connection error: ' . $curlError);

        jsonResponse(502, [
            'success' => false,
            'message' => 'Could not confirm checkout creation. Contact support before retrying.'
        ]);
    }

    // Decode Midtrans response
    $gatewayResponse = json_decode($responseBody, true);

    if (
        $httpStatus < 200 ||
        $httpStatus >= 300 ||
        !is_array($gatewayResponse) ||
        empty($gatewayResponse['token'])
    ) {

        error_log(
            'Midtrans Snap error. HTTP status: '
            . $httpStatus
            . ' Response: '
            . $responseBody
        );

        jsonResponse(502, [
            'success' => false,
            'message' => 'Midtrans could not confirm checkout creation. Contact support before retrying.'
        ]);
    }

    // Save Snap token
    $connection->beginTransaction();

    $saveToken = $connection->prepare("
        UPDATE payment
        SET
            snap_token = :snap_token,
            updated_at = CURRENT_TIMESTAMP
        WHERE payment_id = :payment_id
          AND transaction_reference = :order_id
          AND payment_status = 'pending'
    ");

    $saveToken->execute([
        'snap_token' => $gatewayResponse['token'],
        'payment_id' => $paymentId,
        'order_id' => $orderId
    ]);

    if ($saveToken->rowCount() !== 1) {

        $connection->rollBack();

        error_log(
            'Could not save Snap token for order: ' . $orderId
        );

        jsonResponse(500, [
            'success' => false,
            'message' => 'Checkout was created but could not be saved. Contact support.'
        ]);
    }

    $connection->commit();
    unset($_SESSION['booking_draft']);

    // Successful response
    jsonResponse(201, [
        'success' => true,
        'message' => 'Checkout created successfully.',
        'reservation_id' => (int) $reservationId,
        'order_id' => $orderId,
        'amount' => (int) round($amount),
        'snap_token' => $gatewayResponse['token'],
        'redirect_url' => $gatewayResponse['redirect_url'] ?? ($snapRedirectBase . rawurlencode($gatewayResponse['token']))
    ]);

} catch (Throwable $exception) {

    if (
        $connection instanceof PDO &&
        $connection->inTransaction()
    ) {
        $connection->rollBack();
    }

    error_log(
        'Create payment error: ' . $exception->getMessage()
    );

    jsonResponse(500, [
        'success' => false,
        'message' => 'An internal error occurred while creating the payment.'
    ]);
}
