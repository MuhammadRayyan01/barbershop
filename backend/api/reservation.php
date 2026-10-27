<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/require-auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT
                r.reservation_id,
                r.reservation_status,
                r.service_price,
                r.additional_service_price,
                r.created_at,
                s.service_name,
                s.category,
                ts.slot_date,
                ts.start_time,
                ts.end_time,
                ads.additional_service_name
            FROM reservation r
            JOIN service s
                ON s.service_id = r.service_id
            JOIN time_slot ts
                ON ts.slot_id = r.slot_id
            LEFT JOIN additional_service ads
                ON ads.additional_service_id = r.additional_service_id
            WHERE r.user_id = :user_id
            ORDER BY ts.slot_date DESC, ts.start_time DESC
        ");

        $stmt->execute([
            'user_id' => $authenticatedUserId
        ]);

        jsonResponse(200, [
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (PDOException $e) {
        error_log('Reservation list API error: ' . $e->getMessage());

        jsonResponse(500, [
            'success' => false,
            'message' => 'Unable to load reservations.'
        ]);
    }
}

require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';

if (!function_exists('readJsonRequest')) {
    function readJsonRequest(): array
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!is_array($data)) {
            jsonResponse(400, [
                'success' => false,
                'message' => 'Invalid JSON request body.'
            ]);
        }

        return $data;
    }
}

requireValidCsrfToken();

$data = readJsonRequest();

$slotId = $data['slot_id'] ?? null;
$serviceId = $data['service_id'] ?? null;
$additionalServiceId = $data['additional_service_id'] ?? null;

if (
    !is_int($slotId) || $slotId < 1 ||
    !is_int($serviceId) || $serviceId < 1 ||
    (
        $additionalServiceId !== null &&
        (!is_int($additionalServiceId) || $additionalServiceId < 1)
    )
) {
    jsonResponse(422, [
        'success' => false,
        'message' => 'Invalid slot or service selection.'
    ]);
}

try {
    $pdo->beginTransaction();

    $slotStmt = $pdo->prepare("
        SELECT slot_id
        FROM time_slot
        WHERE slot_id = :slot_id
          AND (
              slot_date > CURRENT_DATE
              OR (
                  slot_date = CURRENT_DATE
                  AND start_time > LOCALTIME
              )
          )
        FOR UPDATE
    ");

    $slotStmt->execute([
        'slot_id' => $slotId
    ]);

    if (!$slotStmt->fetchColumn()) {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'This time slot is unavailable or has already passed.'
        ]);
    }

    $serviceStmt = $pdo->prepare("
        SELECT service_id, category, price
        FROM service
        WHERE service_id = :service_id
          AND status = 'active'
    ");

    $serviceStmt->execute([
        'service_id' => $serviceId
    ]);

    $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$service) {
        $pdo->rollBack();

        jsonResponse(422, [
            'success' => false,
            'message' => 'The selected service is unavailable.'
        ]);
    }

    $availabilityStmt = $pdo->prepare("
        SELECT 1
        FROM reservation r
        JOIN service s
            ON s.service_id = r.service_id
        WHERE r.slot_id = :slot_id
          AND s.category = :category
          AND r.reservation_status = 'active'
        LIMIT 1
    ");

    $availabilityStmt->execute([
        'slot_id' => $slotId,
        'category' => $service['category']
    ]);

    if ($availabilityStmt->fetchColumn()) {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'This time slot is already booked for the selected category.'
        ]);
    }

    $additionalPrice = null;

    if ($additionalServiceId !== null) {
        $additionalStmt = $pdo->prepare("
            SELECT additional_service_id, additional_service_price
            FROM additional_service
            WHERE additional_service_id = :additional_service_id
              AND (
                  service_id = :service_id
                  OR service_id IS NULL
              )
        ");

        $additionalStmt->execute([
            'additional_service_id' => $additionalServiceId,
            'service_id' => $serviceId
        ]);

        $additionalService = $additionalStmt->fetch(PDO::FETCH_ASSOC);

        if (!$additionalService) {
            $pdo->rollBack();

            jsonResponse(422, [
                'success' => false,
                'message' => 'The selected additional service is unavailable.'
            ]);
        }

        $additionalPrice = $additionalService['additional_service_price'];
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO reservation (
            user_id,
            slot_id,
            service_id,
            service_price,
            additional_service_id,
            additional_service_price,
            reservation_status
        )
        VALUES (
            :user_id,
            :slot_id,
            :service_id,
            :service_price,
            :additional_service_id,
            :additional_service_price,
            'active'
        )
        RETURNING reservation_id, slot_id, service_id,
                  service_price, additional_service_id,
                  additional_service_price, reservation_status,
                  created_at
    ");

    $insertStmt->execute([
        'user_id' => $authenticatedUserId,
        'slot_id' => $slotId,
        'service_id' => $serviceId,
        'service_price' => $service['price'],
        'additional_service_id' => $additionalServiceId,
        'additional_service_price' => $additionalPrice
    ]);

    $reservation = $insertStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    jsonResponse(201, [
        'success' => true,
        'message' => 'Reservation created successfully.',
        'data' => $reservation
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Reservation creation API error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to create reservation.'
    ]);
}