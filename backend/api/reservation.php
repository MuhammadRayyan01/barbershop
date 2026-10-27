<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if(!in_array($method, ['GET', 'POST'], true)){
    header('Allow: GET, POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

require_once __DIR__ . '/../middleware/auth_require.php';

if($method === 'GET'){
    try{
        $stmt = $pdo->prepare('select
                                    r.reservation_id, r.reservation_status,
                                    r.service_price, r.additional_service_price,
                                    r.created_at, s.service_name, ts.slot_date,
                                    ts.start_time, ts.end_time, ads.additional_service_name
                                    from reservation as r join service as s on s.service_id = r.service_id
                                    join time_slot as ts on ts.slot_id = r.slot_id
                                    left join additional_service_id as ads on ads.additional_service_id = r.additional_service_id
                                    where r.user_id = :user_id
                                    order by ts.slot_date desc, ts.start_time desc');

                $stmt->execute(['user_id' => $authenticatedUserId]);

                $reservationFatch = $stmt->fetchAll(PDO::FETCH_ASSOC);

                jsonResponse(200, ['success' => true, 'data' => $reservationFatch ]);
    }catch(PDOException $e){
            error_log('Reservation list API error: ' .$e->getMessage());
            jsonResponse(500, ["success" => false, 'message' => 'Unable to load data']);

    }
}

require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/csrf.php';

requireValidCsrfToken();

$data = readJsonResponse();

$slotId = $data['slot_id'] ?? null;
$serviceId = $data['service_id'] ?? null;
$additionalServiceId = $data['additional_service_id'] ?? null;

if(!is_int($slotId) || $slotId < 1 || !is_int($serviceId) || $serviceId < 1 ||
    ($additionalServiceId !== null && (!is_int($additionalServiceId) || $additionalServiceId < 1))){
        jsonResponse(422, ['success' => false, 'message' => 'Invalid slot or service selection.']);
    }

    try{
        $pdo->beginTransaction();

        $slotStmt = $pdo->prepare("select 
                                    slot_id from time_slot where slot_id = :user_id
                                    and is_available = 'available' and(
                                    slot_date > CURRENT_DATE or(
                                    slot_date = CURRENT_DATE and
                                    start_time > LOCALTIME)) FOR UDATE");
                    
        $slotStmt->execute(['slot_id' => $slotId]);

        if(!$slotStmt->fetchColumn()){
            $pdo->rollBack();

            jsonResponse(409, ['success' => false, 'mession' => 'This time slot is unavalible']);
        }

        $serviceStmt = $pdo->prepare("
                                        select service_id from service where service_id = :service_id
                                        and status = 'active'");

        $serviceStmt->execute(['service_id' => $serviceId]);
        $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);

        if(!$service){
        $pdo->rollBack();

        jsonResponse(422, ['success' => false, 'message' => 'Selected service is unavalible']);
        }

        $additionalPrice = null;

        if($additionalServiceId !== null){
            $additionalStmt = $pdo->prepare('select
                                            additional_service_id, additional_service_price
                                            from additional_service where additional_service_id = :additional_service_id
                                            and(service_id = :service_id ot service_id is null)');

                                            $additionalStmt->execute(['additional_service_id' => $additionalServiceId, 'service_id' => $serviceId]);

                                            $additionalService = $additionalStmt->fetch(PDO::FETCH_ASSOC);

                                            if(!$additionalService){
                                                $pdo->rollBack();

                                                jsonResponse(422, ['success' => false, 'message' => 'Selected additional service not available']);
                                            }

                                            $additionalPrice = $additionalService['additional_service_price'];

                                            $insertStmt = $pdo->prepare("
                                                                           insert into reservation(
                                                                           user_id, slod_id, service_id, service_price,
                                                                           additional_service_id, additional_service_id,
                                                                           additional_service_price, reservation_status)
                                                                           values
                                                                           (:user_id, :slot_id, :service_id, :service_price, :additional_service_id, :additional_service_price, 'active') ");

                                                                           $insertStmt->execute(['user_id' => $authenticatedUserId,
                                                                                                    'slot_id' => $slotId,
                                                                                                    'service_id' => $serviceId,
                                                                                                    'service_price' => $service['price'],
                                                                                                    'additional_service_id' => $additionalServiceId,
                                                                                                    'additional_service_price' => $additionalPrice]);
                                                                            $reservation = $insertStmt->fetch(PDO::FETCH_ASSOC);

                                                                            $updateSlotStmt = $pdo->prepare("update time_slot set is_available = 'not available' where slot_id = :slot_id");

                                                                             $updateSlotStmt->execute(['slot_id' => $slotId]);

                                                                             $pdo->commit();

                                                                             jsonResponse(201, ['success' => true, 'message' => 'reservation created successfully.', 'data' => $reservation]);
        }                                  


    }catch(PDOException $e){
            if($pdo->inTransaction()){
                $pdo->rollBack();
            }

            error_log('Reservation creation API error: '. $e->getMessage());

            if($e->getCode() === '23505'){
                jsonResponse(409, ['success' => false, 'message' => 'This time slot is already been booked.']);
            }

            jsonResponse(500, ['success' => false, 'message' => 'Unable to create reservation.']);
    }



?>