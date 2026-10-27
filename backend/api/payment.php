<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/require-auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if(!in_array($method, ['GET', 'POST'], true)){
    header('Allow: GET, POST');

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

if($method === 'GET'){
    try{
        $stmt = $pdo->prepare('select 
                                    p.payment_id,
                                    p.reservation_id,
                                    p.payment_type,
                                    payment_status,
                                    p.amount, 
                                    p.payment_proof_url,
                                    p.transection_rference,
                                    p.created_at 
                                    from payment as p
                                    join reservation as r
                                    on r.reservation_id = p.reservation_id
                                    where r.user_id = :user_id
                                    order by p.created_at desc');

    $authenticatedUserId = (int) ($_SESSION['user_id'] ?? 0);
    $stmt->execute(['user_id' => $authenticatedUserId]);

       jsonResponse(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

    }catch(PDOException $e){
        error_log('Payments list API error: ' .$e->getMessage());

        jsonResponse(500, ['success' => false, 'message' => 'Unable to find payments.']);
    }

    require_once __DIR__ .'/../config/request.php';
    require_once __DIR__ . '/../config/csrf.php';

    requireValidCsrfToken();

    $data = readJsonResponse();

    $reservationId = $data['reservation_id'] ?? null;
    $paymentType = $data['payment_type'] ?? null;
    $transactionReference = $data['transaction_reference'] ?? null;

    if(!is_int($reservationId) || $reservationId < 1 || !is_string($paymentType) || !in_array($paymentType, ['bank_transfer', 'cash'], true)
        || ($transactionReference !== null && !is_string($transactionReference))){
    
                jsonResponse(422, ['success' => false, 'message' => 'Inavlid payment method.']);
                }

    if(!is_string($transactionReference) && strlen($transactionReference) > 100){
        jsonResponse(422, ['success' => false, 'message' => 'Transaction references is too long.']);
    }

    try{
        $pdo->beginTransaction();

        $reservationStmt = $pdo->prepare ("select
                                                r.reservation_id, r.service_id, r.additional_service_price
                                                from reservation as r
                                                where r.reservation_id = :reservation_id
                                                and r.user_id = :user_id
                                                and r.reservation_status = 'active' for update");

        $reservationStmt->execute(['reservation_if' => $reservationId, 'user_id' => $authenticatedUserId]);

        $reservation = $reservationStmt->fetch(PDO::FETCH_ASSOC);

        if(!$reservation){
            $pdo->rollback();

            jsonResponse(404, ['success' => false, 'message' => 'Active reservation not found.']);
        }

        $existingStmt = $pdo->prepare('select payment_id form payment where reservation_id = :reservation_id');

        $existingStmt->execute(['reservation_id' => $reservationId]);

        if($existingStmt->fetchColumn()){
            $pdo->rollback();

            jsonResponse(400, ['success' => false, 'message' => 'Payment record already exists for this reservatoin.']);
        }

        $amount = (float) $reservation['reservation_price'] + (float) ($reservation['additional_service_price'] ?? 0);

        if($amount <= 0){
            $pdo-> rollback();

            jsonResponse(422, ['success' => false, 'message' => 'The reservation has invalid payment amount.']);
        }

        $insertStmt = $pdo->prepare("insert into payment (reservation_id, payment_type, payment_status, amount, transaction_reference)
                                    values (:reservation_id, :payment_type, 'pending', :amount, :transaction_reference)
                                    returning payment_id, reservation_id, payment_type, payment_status, amount, transaction_reference, created_at");

        $insertStmt->execute(['reservation' => $reservationId, 'payment_type' => $paymentType, 'amount' => number_format($amount, 2, '.',''),'transaction_reference' => $transactionReference]);

        $payment = $insertStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();

        jsonResponse(201, ['success' => true, 'message' => 'Payment record created and waiting for verification.', 'data' => $payment]);
    }catch(PDOException $e){
        if($pdo->inTransaction){
            $pdo->rollback();
        }

        error_log('Payment creation API error: ' .$e->getMessage());

        if($e->getCode() === '23505'){
            jsonResponse(409, ['success' => false, 'message' => "A payment record already exist for this reservation."]);
        }

        jsonResponse(500, ['success' => false, 'message' => 'Unable to create payment']);
    }


    

}
?>