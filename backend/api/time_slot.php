<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'){
    header('Allow: GET');

    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$category = isset($_GET['category']) && is_string($_GET['category'])
    ? trim($_GET['category'])
    : '';

if($category === ''){
    jsonResponse(400, ['success' => false, 'message' => 'Category is required.']);
}

try{
    $stmt = $pdo->prepare("
    SELECT
    ts.slot_id,
    ts.slot_date,
    ts.start_time,
    ts.end_time,
    3 - COUNT(r.reservation_id) AS remaining_capacity
FROM time_slot ts
LEFT JOIN reservation r
    ON r.slot_id = ts.slot_id
    AND r.reservation_status = 'active'
WHERE (
    ts.slot_date > CURRENT_DATE
    OR (
        ts.slot_date = CURRENT_DATE
        AND ts.start_time > LOCALTIME
    )
)
GROUP BY
    ts.slot_id,
    ts.slot_date,
    ts.start_time,
    ts.end_time
HAVING COUNT(r.reservation_id) < 3
ORDER BY ts.slot_date, ts.start_time;
");
    $stmt->execute();

    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(200, ['success' => true, 'data' => $slots]);
}catch(PDOException $e){
    error_log('Time slot API error: ' .$e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to load available time slots.']);
}
?>