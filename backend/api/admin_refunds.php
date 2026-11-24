<?php
declare(strict_types=1);

require_once __DIR__ . '/../middleware/require_admin_api.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/request.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

if ($method === 'GET') {
    $status = $_GET['status'] ?? 'all';
    $query = trim((string) ($_GET['q'] ?? ''));
    if (!in_array($status, ['all', 'waiting', 'manual', 'refunded', 'void'], true)) {
        jsonResponse(422, ['success' => false, 'message' => 'Unsupported refund status.']);
    }
    $where = ["r.reservation_status = 'inactive'"];
    $params = [];
    if ($query !== '') {
        $where[] = '(i.customer_name ILIKE :name_query OR i.phone_number ILIKE :phone_query OR s.service_name ILIKE :service_query OR CAST(i.invoice_id AS text) = :invoice_id OR CAST(r.reservation_id AS text) = :reservation_id)';
        $params['name_query'] = '%' . $query . '%';
        $params['phone_query'] = '%' . $query . '%';
        $params['service_query'] = '%' . $query . '%';
        $params['invoice_id'] = $query;
        $params['reservation_id'] = $query;
    }
    $stateSql = "CASE WHEN p.payment_status = 'refunded' THEN 'refunded' WHEN p.payment_status = 'paid' AND p.refund_method = 'manual' AND p.refund_status = 'pending' THEN 'manual' WHEN p.payment_status = 'paid' THEN 'waiting' ELSE 'void' END";
    if ($status !== 'all') {
        $where[] = "$stateSql = :state";
        $params['state'] = $status;
    }
    try {
        $stmt = $pdo->prepare("SELECT r.reservation_id, i.invoice_id, i.customer_name, i.phone_number,
                s.service_name, i.total_payment, i.created_at,
                p.payment_status, p.payment_type, p.refund_method, p.refund_status, $stateSql AS state
            FROM reservation r
            JOIN invoice i ON i.reservation_id = r.reservation_id
            JOIN service s ON s.service_id = r.service_id
            LEFT JOIN payment p ON p.reservation_id = r.reservation_id
            WHERE " . implode(' AND ', $where) . ' ORDER BY i.created_at DESC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats = ['total' => count($rows), 'waiting' => 0, 'refunded_amount' => 0.0];
        foreach ($rows as $row) {
            if (in_array($row['state'], ['waiting', 'manual'], true)) $stats['waiting']++;
            if ($row['state'] === 'refunded') $stats['refunded_amount'] += (float) $row['total_payment'];
        }
        jsonResponse(200, ['success' => true, 'csrf_token' => getCsrfToken(), 'meta' => $stats, 'data' => $rows]);
    } catch (PDOException $e) {
        error_log('Admin refund list error: ' . $e->getMessage());
        jsonResponse(500, ['success' => false, 'message' => 'Unable to load refund history.']);
    }
}

requireValidCsrfToken();
$data = readJsonResponse();
$reservationId = $data['reservation_id'] ?? null;
if (!is_int($reservationId) || $reservationId < 1) jsonResponse(422, ['success' => false, 'message' => 'A valid reservation ID is required.']);

try {
    $stmt = $pdo->prepare("UPDATE payment p SET payment_status = 'refunded', refund_status = 'completed', refunded_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta', updated_at = CURRENT_TIMESTAMP
        FROM reservation r
        WHERE p.reservation_id = r.reservation_id
          AND p.reservation_id = :reservation_id
          AND p.payment_status = 'paid'
          AND p.refund_status = 'pending'
          AND p.refund_method = 'manual'
          AND r.reservation_status = 'inactive'
        RETURNING p.reservation_id");
    $stmt->execute(['reservation_id' => $reservationId]);
    if (!$stmt->fetchColumn()) {
        jsonResponse(409, ['success' => false, 'message' => 'Only a cancelled reservation awaiting a manual refund can be completed here.']);
    }
    jsonResponse(200, ['success' => true, 'message' => 'Manual refund recorded as completed.']);
} catch (PDOException $e) {
    error_log('Admin refund update error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to update refund status.']);
}
