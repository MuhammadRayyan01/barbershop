<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/load_env.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$serverKey = getenv('MIDTRANS_SERVER_KEY');
$notification = json_decode(file_get_contents('php://input'), true);

if (!$serverKey) {
    jsonResponse(503, ['success' => false, 'message' => 'Payment service is not configured.']);
}

if (!is_array($notification)) {
    jsonResponse(400, ['success' => false, 'message' => 'Invalid notification.']);
}

$orderId = $notification['order_id'] ?? '';
$statusCode = $notification['status_code'] ?? '';
$grossAmount = $notification['gross_amount'] ?? '';
$signature = $notification['signature_key'] ?? '';

if (
    !is_string($orderId) || $orderId === '' ||
    !is_string($statusCode) || $statusCode === '' ||
    !is_string($grossAmount) || !is_numeric($grossAmount) ||
    !is_string($signature) || $signature === ''
) {
    jsonResponse(400, ['success' => false, 'message' => 'Notification fields are incomplete.']);
}

$expectedSignature = hash('sha512', $orderId . $statusCode . $grossAmount . $serverKey);
if (!hash_equals($expectedSignature, $signature)) {
    jsonResponse(403, ['success' => false, 'message' => 'Invalid notification signature.']);
}

$transactionStatus = $notification['transaction_status'] ?? '';
$fraudStatus = $notification['fraud_status'] ?? '';

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT payment_id, amount, payment_status::text AS payment_status
        FROM payment
        WHERE transaction_reference = :order_id
        FOR UPDATE");
    $stmt->execute(['order_id' => $orderId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $pdo->rollBack();
        jsonResponse(404, ['success' => false, 'message' => 'Payment order was not found.']);
    }

    if (abs((float) $payment['amount'] - (float) $grossAmount) > 0.009) {
        $pdo->rollBack();
        error_log('Midtrans notification amount mismatch for order: ' . $orderId);
        jsonResponse(409, ['success' => false, 'message' => 'Payment amount does not match.']);
    }

    $nextStatus = match ($transactionStatus) {
        'settlement' => 'paid',
        'capture' => $fraudStatus === 'accept' ? 'paid' : ($fraudStatus === 'challenge' ? 'verification pending' : 'pending'),
        'pending' => 'pending',
        'deny', 'cancel', 'expire' => 'failed',
        'refund' => 'refunded',
        'partial_refund' => $payment['payment_status'],
        default => $payment['payment_status'],
    };

    if ($payment['payment_status'] === 'refunded') {
        $nextStatus = 'refunded';
    } elseif ($payment['payment_status'] === 'paid' && $nextStatus !== 'refunded') {
        $nextStatus = 'paid';
    }

    $update = $pdo->prepare("UPDATE payment
        SET payment_status = :status, updated_at = CURRENT_TIMESTAMP
        WHERE payment_id = :payment_id");
    $update->execute([
        'status' => $nextStatus,
        'payment_id' => $payment['payment_id'],
    ]);

    $pdo->commit();
    jsonResponse(200, ['success' => true, 'message' => 'Notification processed.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Midtrans notification error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to process notification.']);
}
