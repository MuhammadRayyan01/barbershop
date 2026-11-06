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
    $date = $_GET['date'] ?? '';
    $query = trim((string) ($_GET['q'] ?? ''));
    $status = $_GET['status'] ?? 'all';
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));

    if ($date !== '' && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))) {
        jsonResponse(422, ['success' => false, 'message' => 'Date must use YYYY-MM-DD format.']);
    }
    if (!in_array($status, ['all', 'active', 'inactive'], true)) {
        jsonResponse(422, ['success' => false, 'message' => 'Unsupported reservation status.']);
    }

    $where = [];
    $params = [];
    if ($date !== '') {
        $where[] = 'ts.slot_date = CAST(:slot_date AS date)';
        $params['slot_date'] = $date;
    }
    if ($status !== 'all') {
        $where[] = 'r.reservation_status = :reservation_status';
        $params['reservation_status'] = $status;
    }
    if ($query !== '') {
        $where[] = '(u.full_name ILIKE :name_query OR u.phone_number ILIKE :phone_query OR s.service_name ILIKE :service_query OR CAST(r.reservation_id AS text) = :reservation_id)';
        $params['name_query'] = '%' . $query . '%';
        $params['phone_query'] = '%' . $query . '%';
        $params['service_query'] = '%' . $query . '%';
        $params['reservation_id'] = $query;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    try {
        $count = $pdo->prepare("SELECT COUNT(*) FROM reservation r JOIN users u ON u.user_id = r.user_id JOIN service s ON s.service_id = r.service_id JOIN time_slot ts ON ts.slot_id = r.slot_id $whereSql");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $pdo->prepare("SELECT r.reservation_id, r.reservation_status, r.created_at,
                u.full_name AS customer_name, u.phone_number,
                s.service_name, r.service_price,
                ts.slot_date, ts.start_time, ts.end_time,
                i.total_payment,
                p.payment_status, p.payment_type
            FROM reservation r
            JOIN users u ON u.user_id = r.user_id
            JOIN service s ON s.service_id = r.service_id
            JOIN time_slot ts ON ts.slot_id = r.slot_id
            LEFT JOIN invoice i ON i.reservation_id = r.reservation_id
            LEFT JOIN payment p ON p.reservation_id = r.reservation_id
            $whereSql
            ORDER BY ts.slot_date DESC, ts.start_time DESC, r.reservation_id DESC
            LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(200, [
            'success' => true,
            'csrf_token' => getCsrfToken(),
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage],
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    } catch (PDOException $e) {
        error_log('Admin reservation list error: ' . $e->getMessage());
        jsonResponse(500, ['success' => false, 'message' => 'Unable to load reservations.']);
    }
}

requireValidCsrfToken();
$data = readJsonResponse();
$reservationId = $data['reservation_id'] ?? null;
$action = $data['action'] ?? '';
if (!is_int($reservationId) || $reservationId < 1 || !in_array($action, ['mark_paid', 'cancel'], true)) {
    jsonResponse(422, ['success' => false, 'message' => 'A valid reservation and action are required.']);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT reservation_id, reservation_status FROM reservation WHERE reservation_id = :id FOR UPDATE');
    $stmt->execute(['id' => $reservationId]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reservation) {
        $pdo->rollBack();
        jsonResponse(404, ['success' => false, 'message' => 'Reservation not found.']);
    }
    if ($reservation['reservation_status'] !== 'active') {
        $pdo->rollBack();
        jsonResponse(409, ['success' => false, 'message' => 'Only active reservations can be changed.']);
    }

    if ($action === 'cancel') {
        $stmt = $pdo->prepare("UPDATE reservation SET reservation_status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE reservation_id = :id");
        $stmt->execute(['id' => $reservationId]);
        $message = 'Reservation cancelled.';
    } else {
        $stmt = $pdo->prepare('SELECT total_payment FROM invoice WHERE reservation_id = :id');
        $stmt->execute(['id' => $reservationId]);
        $amount = $stmt->fetchColumn();
        if ($amount === false) {
            $pdo->rollBack();
            jsonResponse(409, ['success' => false, 'message' => 'Reservation invoice is missing.']);
        }
        $stmt = $pdo->prepare("INSERT INTO payment (reservation_id, payment_type, payment_status, amount)
            VALUES (:id, 'cash', 'paid', :amount)
            ON CONFLICT (reservation_id) DO UPDATE SET payment_type = 'cash', payment_status = 'paid', amount = EXCLUDED.amount, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute(['id' => $reservationId, 'amount' => $amount]);
        $message = 'Cash payment recorded.';
    }
    $pdo->commit();
    jsonResponse(200, ['success' => true, 'message' => $message]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Admin reservation update error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to update reservation.']);
}
