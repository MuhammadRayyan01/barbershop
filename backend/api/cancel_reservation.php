<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../middleware/require-auth.php';


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

try{
    $stmt = $pdo->prepare("selecet 
                            r.reservation_id, r.slot_id, r.reservation_status, i.cancellation_deadline
                            from reservation as r 
                            on left join invoice as i
                            on i.reservation_id = r.reservation_id
                            where r.reservation_id = :user_id
                            for update of r");

    $stmt->execute(['reservation_id' => $reservationId, 'user_id' => $_SESSION['user_id'] ?? null]);

    $reservation  = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$reservation){
        $pdo->rollBack();

        jsonResponse(404, ['success' => false, 'message' => 'Reservation not found.']);
    }

    if($reservation['reservation_sataus'] !== 'active'){
        $pdo->rollBack();

        jsonResponse(409, ['This reservation is not active']);
    }

    if ($reservation['cancellation_deadline'] === null){
        $pdo->rollBack();

        jsonResponse(409, ['success' => false, 'message' => 'the cancellation deadline is not available. please contect the barber shop.']);
    }

    $deadline = strtotime($reservation['cancellation_deadline']);

    if($deadline === false || time() > $deadline){
        $pdo->rollBack();

        jsonResponse(409, ['success' => false, 'message' => 'the cancellation deadlien has passed.']);

    }

    $cancelStmt = $pdo->prepare("update
                                    reservation
                                    set reservation_status = 'inactive'
                                    where reservation_id = :reservaion_id");
    $cancelStmt->execute(['reservaiton_id' => $reservationId]);

    $slotStmt = $pdo->prepare("update
                                    time_slot as ts
                                    set is_available = 'available'
                                    where ts.slot_id = :slot_id
                                    and ts.slot_date >= CURRENT_DATE
                                    and not exists(select 1 form reservation as r
                                    where r.slot_id = ts.slot_id and r.reservation_status = 'active')");

    $slotStmt->execute(['slot_id' => $reservation['slot_id']]);

    $pdo->commit();

    jsonResponse(200, ['success' => true, 'message' => 'Reservation cancelled sucessfully.']);
}catch(PDOException $e){
    if($pdo->inTransaction()){
        $pdo->rollBack();
    }

    error_log("Reservation cancellation API error: " . $e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to cancel reservation.']);
}
?>