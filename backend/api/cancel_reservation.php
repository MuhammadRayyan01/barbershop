<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../middleware/auth_require.php';


if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'){
    header("Allow: POST");

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
    
}

requireValidCsrfToken();

$data = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

$reservationId = $data['reservation_id'] ?? null;

if(!is_int($reservationId) || $reservationId < 1){
    jsonResponse(422, ['success' => false, 'message' => 'A valid reservation ID id required']);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT
            r.reservation_id,
            r.slot_id,
            r.reservation_status,
            i.cancellation_deadline
        FROM reservation AS r
        LEFT JOIN invoice AS i
            ON i.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
          AND r.user_id = :user_id
        FOR UPDATE OF r
    ");

    $stmt->execute([
        'reservation_id' => $reservationId,
        'user_id' => $authenticatedUserId
    ]);

    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        $pdo->rollBack();

        jsonResponse(404, [
            'success' => false,
            'message' => 'Reservation not found.'
        ]);
    }

    if ($reservation['reservation_status'] !== 'active') {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'This reservation is not active.'
        ]);
    }

    if ($reservation['cancellation_deadline'] === null) {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'Cancellation deadline is not available.'
        ]);
    }

    $deadline = strtotime($reservation['cancellation_deadline']);

    if ($deadline === false || time() > $deadline) {
        $pdo->rollBack();

        jsonResponse(409, [
            'success' => false,
            'message' => 'The cancellation deadline has passed.'
        ]);
    }

    $cancelStmt = $pdo->prepare("
        UPDATE reservation
        SET reservation_status = 'inactive'
        WHERE reservation_id = :reservation_id
    ");

    $cancelStmt->execute([
        'reservation_id' => $reservationId
    ]);

    $pdo->commit();

    jsonResponse(200, [
        'success' => true,
        'message' => 'Reservation cancelled successfully.'
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Reservation cancellation API error: " . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to cancel reservation.'
    ]);
}
?>