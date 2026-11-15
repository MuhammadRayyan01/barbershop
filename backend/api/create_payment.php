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

/*
|--------------------------------------------------------------------------
| Request configuration
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Authentication
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
| CSRF validation
|--------------------------------------------------------------------------
*/

requireValidCsrfToken();

/*
|--------------------------------------------------------------------------
| Read JSON request
|--------------------------------------------------------------------------
*/

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (
    stripos($contentType, 'application/json') !== 0
) {
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

if (
    $holdToken === '' ||
    strlen($holdToken) > 64
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'A valid hold token is required.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Load active booking hold
|--------------------------------------------------------------------------
*/

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            hold_id,
            user_id,
            total_amount,
            hold_status,
            expires_at,
            midtrans_order_id,
            snap_token,
            (expires_at > CURRENT_TIMESTAMP) AS is_unexpired
        FROM booking_hold
        WHERE hold_token = :hold_token
          AND user_id = :user_id
        FOR UPDATE
    ");

    $stmt->execute([
        ':hold_token' => $holdToken,
        ':user_id' => $userId
    ]);

    $hold = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$hold) {
        $pdo->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Booking hold not found.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate hold status
    |--------------------------------------------------------------------------
    */

    if ($hold['hold_status'] !== 'active') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'This booking hold is no longer active.'
        ]);
    }

    $isUnexpired = in_array(
        $hold['is_unexpired'],
        [true, 1, '1', 't', 'true'],
        true
    );

    if (!$isUnexpired) {
        $expireStmt = $pdo->prepare("
            UPDATE booking_hold
            SET
                hold_status = 'expired',
                updated_at = CURRENT_TIMESTAMP
            WHERE hold_id = :hold_id
              AND hold_status = 'active'
        ");

        $expireStmt->execute([
            ':hold_id' => $hold['hold_id']
        ]);

        $pdo->commit();

        jsonResponse(410, [
            'success' => false,
            'message' => 'Your booking hold has expired. Please select your booking again.'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate payment amount
    |--------------------------------------------------------------------------
    */

    $amountString = (string) $hold['total_amount'];

    if (
        !preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amountString, $matches)
    ) {
        throw new RuntimeException('Invalid booking amount.');
    }

    $wholeAmount = $matches[1];
    $decimalAmount = $matches[2] ?? '00';

    // Indonesian Rupiah payments must use whole rupiah.
    if (str_pad($decimalAmount, 2, '0') !== '00') {
        throw new RuntimeException('Payment amount must be a whole rupiah value.');
    }

    if (
        strlen($wholeAmount) > strlen((string) PHP_INT_MAX) ||
        (float) $wholeAmount > PHP_INT_MAX
    ) {
        throw new RuntimeException('Payment amount is too large.');
    }

    $grossAmount = (int) $wholeAmount;

    if ($grossAmount <= 0) {
        throw new RuntimeException('Invalid payment amount.');
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve customer information
    |--------------------------------------------------------------------------
    */

    $customerStmt = $pdo->prepare("
        SELECT
            full_name,
            email,
            phone_number
        FROM users
        WHERE user_id = :user_id
        LIMIT 1
    ");

    $customerStmt->execute([
        ':user_id' => $userId
    ]);

    $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        throw new RuntimeException('Customer account not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | Create or reuse stable Midtrans order ID
    |--------------------------------------------------------------------------
    */

    $orderId = $hold['midtrans_order_id'];

    if (empty($orderId)) {
        $orderId = 'PEMON-HOLD-' .
            $hold['hold_id'] . '-' .
            bin2hex(random_bytes(8));

        $orderStmt = $pdo->prepare("
            UPDATE booking_hold
            SET
                midtrans_order_id = :order_id,
                updated_at = CURRENT_TIMESTAMP
            WHERE hold_id = :hold_id
              AND hold_status = 'active'
        ");

        $orderStmt->execute([
            ':order_id' => $orderId,
            ':hold_id' => $hold['hold_id']
        ]);
    }

    $existingSnapToken = $hold['snap_token'] ?? null;

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Create payment preparation error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to prepare your payment.'
    ]);
}

/*
|--------------------------------------------------------------------------
| Reuse existing Snap token
|--------------------------------------------------------------------------
*/

if (!empty($existingSnapToken)) {
    try {
        jsonResponse(200, [
            'success' => true,
            'message' => 'Existing payment session retrieved.',
            'data' => [
                'order_id' => $orderId,
                'snap_token' => $existingSnapToken,
                'client_key' => midtransClientKey(),
                'snap_url' => midtransSnapBaseUrl()
            ]
        ]);
    } catch (Throwable $e) {
        error_log('Midtrans configuration error: ' . $e->getMessage());

        jsonResponse(500, [
            'success' => false,
            'message' => 'Payment gateway configuration error.'
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Create new Midtrans Snap transaction
|--------------------------------------------------------------------------
*/

try {
    $payload = [
        'transaction_details' => [
            'order_id' => $orderId,
            'gross_amount' => $grossAmount
        ],
        'customer_details' => [
            'first_name' => $customer['full_name'],
            'email' => $customer['email'],
            'phone' => $customer['phone_number']
        ]
    ];

    $midtransResponse = midtransCreateSnapTransaction($payload);

    $snapToken = $midtransResponse['token'] ?? null;

    if (!is_string($snapToken) || $snapToken === '') {
        throw new RuntimeException('Midtrans did not return a Snap token.');
    }

    /*
    |--------------------------------------------------------------------------
    | Save Snap token
    |--------------------------------------------------------------------------
    */

    $saveStmt = $pdo->prepare("
        UPDATE booking_hold
        SET
            snap_token = :snap_token,
            updated_at = CURRENT_TIMESTAMP
        WHERE hold_id = :hold_id
          AND user_id = :user_id
          AND hold_status = 'active'
          AND expires_at > CURRENT_TIMESTAMP
    ");

    $saveStmt->execute([
        ':snap_token' => $snapToken,
        ':hold_id' => $hold['hold_id'],
        ':user_id' => $userId
    ]);

    if ($saveStmt->rowCount() !== 1) {
        throw new RuntimeException(
            'Booking hold expired or changed while preparing payment.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Return payment information
    |--------------------------------------------------------------------------
    */

    jsonResponse(201, [
        'success' => true,
        'message' => 'Payment session created successfully.',
        'data' => [
            'order_id' => $orderId,
            'snap_token' => $snapToken,
            'redirect_url' => $midtransResponse['redirect_url'] ?? null,
            'client_key' => midtransClientKey(),
            'snap_url' => midtransSnapBaseUrl()
        ]
    ]);

} catch (Throwable $e) {
    error_log('Midtrans transaction creation error: ' . $e->getMessage());

    /*
     * Another request may have completed the same order
     * while this request was communicating with Midtrans.
     * Retrieve the saved token if one is now available.
     */

    try {
        $retryStmt = $pdo->prepare("
            SELECT snap_token
            FROM booking_hold
            WHERE hold_id = :hold_id
              AND user_id = :user_id
              AND hold_status = 'active'
              AND expires_at > CURRENT_TIMESTAMP
        ");

        $retryStmt->execute([
            ':hold_id' => $hold['hold_id'],
            ':user_id' => $userId
        ]);

        $savedToken = $retryStmt->fetchColumn();

        if (is_string($savedToken) && $savedToken !== '') {
            jsonResponse(200, [
                'success' => true,
                'message' => 'Existing payment session retrieved.',
                'data' => [
                    'order_id' => $orderId,
                    'snap_token' => $savedToken,
                    'client_key' => midtransClientKey(),
                    'snap_url' => midtransSnapBaseUrl()
                ]
            ]);
        }

    } catch (Throwable $retryError) {
        error_log('Payment token recovery error: ' . $retryError->getMessage());
    }

    jsonResponse(502, [
        'success' => false,
        'message' => 'Unable to create the payment session. Please try again.'
    ]);
}
