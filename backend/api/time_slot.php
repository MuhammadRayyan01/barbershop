<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/booking.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$date = $_GET['date'] ?? '';
$timezone = new DateTimeZone('Asia/Jakarta');
$requestedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) : false;

if ($date === '') {
    jsonResponse(422, ['success' => false, 'message' => 'A valid date is required.']);
}

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    jsonResponse(422, ['success' => false, 'message' => 'A valid date is required.']);
}

require_once __DIR__ . '/../config/booking.php';

try{
    $stmt = $pdo->prepare("
        SELECT
            ts.slot_id,
            ts.slot_date,
            to_char(ts.start_time, 'HH24:MI') AS start_time,
            to_char(ts.end_time, 'HH24:MI') AS end_time,
            ts.is_available::text AS is_available,
            COUNT(r.reservation_id)::integer AS booked_count
        FROM time_slot AS ts
        LEFT JOIN reservation AS r
            ON r.slot_id = ts.slot_id
           AND r.reservation_status = 'active'
        WHERE ts.slot_date = CAST(:slot_date AS date)
          AND ts.slot_date >= CURRENT_DATE
        GROUP BY ts.slot_id, ts.slot_date, ts.start_time, ts.end_time, ts.is_available
        ORDER BY ts.start_time
    ");
    $stmt->execute(['slot_date' => $date]);

    $slots = array_map(static function (array $slot): array {
        $bookedCount = (int) $slot['booked_count'];
        $remainingCapacity = max(0, BOOKING_SLOT_CAPACITY - $bookedCount);

        return [
            'slot_id' => (int) $slot['slot_id'],
            'slot_date' => $slot['slot_date'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'is_available' => $slot['is_available'],
            'booked_count' => $bookedCount,
            'capacity' => BOOKING_SLOT_CAPACITY,
            'remaining_capacity' => $remainingCapacity,
            'is_full' => $remainingCapacity === 0,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    jsonResponse(200, ['success' => true, 'data' => $slots]);
}catch(PDOException $e){
    error_log('Time slot API error: ' . $e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to load time slot availability.']);
}
?>
