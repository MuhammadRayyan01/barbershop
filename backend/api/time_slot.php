<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){
    header('Allow: GET');

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

try{
    $stmt = $pdo->query("select
                            ts.slot_id, ts.slot_date, ts.start_time, ts.end_time 
                            from time_slot as ts 
                            where ts.is_avalible = 'available'
                            and( ts.slot_date > CURRENT_DATE and ts.start_time > LOCALTIME))
                            and not exists (select 1 from reservarion as r
                            where r.slot_id = ts.slot_id and r.reservation_status = 'active')
                            order by ts.slot_date, ts.start_time");

    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(200, ['success' => true, 'data' => $slots]);
}catch(PDOException $e){
    error_log('Time slot API error: ' .$e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to load avalible time slots.']);
}
?>