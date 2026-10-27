<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../middleware/require-auth.php';

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){
    header('Allow: GET');

    jsonResponse(405, ['success' => false, 'message' => 'Method not found.']);
}

try{
    $stmt = $pdo->prepare('Select 
            i.invoice_id,
            i.reservation_id,
            i.customer_name,
            i.phone_number,
            i.total_payment,
            i.cancellation_deadline,
            i.created_at,
            r.reservation_status,
            s.service_name,
            ts.slot_date,
            ts.start_time,
            ts.end_time,
            p.payment_status,
            p.payment_type
         FROM invoice AS i
         JOIN reservation AS r
            ON r.reservation_id = i.reservation_id
         JOIN service AS s
            ON s.service_id = r.service_id
         JOIN time_slot AS ts
            ON ts.slot_id = r.slot_id
         LEFT JOIN payment AS p
            ON p.reservation_id = r.reservation_id
         WHERE r.user_id = :user_id
         ORDER BY i.created_at DESC');

         $stmt->execute(['user_id' => $authenticatedUserId]);

         jsonResponse(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}catch(PDOException $e){
    error_log('Invoices API error: ' . $e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to load inovices']);
}
?>