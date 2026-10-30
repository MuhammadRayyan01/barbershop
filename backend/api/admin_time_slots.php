<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';

$adminId = $_SESSION['admin_id'] ?? null;
if (!is_int($adminId) || $adminId < 1) {
    jsonResponse(401, ['success' => false, 'message' => 'Admin sign-in required.']);
}

try {
    $adminStmt = $pdo->prepare('SELECT admin_id FROM admin WHERE admin_id = :admin_id');
    $adminStmt->execute(['admin_id' => $adminId]);
    if (!$adminStmt->fetchColumn()) {
        unset($_SESSION['admin_id']);
        jsonResponse(401, ['success' => false, 'message' => 'Admin sign-in required.']);
    }
} catch (PDOException $e) {
    error_log('Admin time slot auth error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to verify admin access.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

if ($method === 'GET') {
    $date = $_GET['date'] ?? '';
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonResponse(422, ['success' => false, 'message' => 'A valid date is required.']);
    }

    try {
        $stmt = $pdo->prepare("SELECT ts.slot_id, ts.slot_date, ts.start_time, ts.end_time,
                    ts.is_available,
                    COUNT(r.reservation_id) FILTER (WHERE r.reservation_status = 'active') AS booked_count
                FROM time_slot ts
                LEFT JOIN reservation r ON r.slot_id = ts.slot_id
                WHERE ts.slot_date = CAST(:slot_date AS date)
                GROUP BY ts.slot_id
                ORDER BY ts.start_time");
        $stmt->execute(['slot_date' => $date]);
        jsonResponse(200, [
            'success' => true,
            'csrf_token' => getCsrfToken(),
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (PDOException $e) {
        error_log('Admin time slot list error: ' . $e->getMessage());
        jsonResponse(500, ['success' => false, 'message' => 'Unable to load time slots.']);
    }
}

requireValidCsrfToken();
$data = json_decode((string) file_get_contents('php://input'), true);
$slotId = is_array($data) ? ($data['slot_id'] ?? null) : null;
$availability = is_array($data) ? ($data['is_available'] ?? null) : null;

if (!is_int($slotId) || $slotId < 1 || !in_array($availability, ['available', 'not available'], true)) {
    jsonResponse(422, ['success' => false, 'message' => 'A valid slot and availability status are required.']);
}

try {
    $stmt = $pdo->prepare('UPDATE time_slot SET is_available = :is_available WHERE slot_id = :slot_id
        RETURNING slot_id, slot_date, start_time, end_time, is_available');
    $stmt->execute(['is_available' => $availability, 'slot_id' => $slotId]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) {
        jsonResponse(404, ['success' => false, 'message' => 'Time slot not found.']);
    }
    jsonResponse(200, ['success' => true, 'message' => 'Time slot updated.', 'data' => $slot]);
} catch (PDOException $e) {
    error_log('Admin time slot update error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to update time slot.']);
}
