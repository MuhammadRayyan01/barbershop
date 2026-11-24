<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/booking.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$date = $_GET['date'] ?? '';
$timezone = new DateTimeZone('Asia/Jakarta');
$requestedDate = is_string($date)
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone)
    : false;

if (!$requestedDate || $requestedDate->format('Y-m-d') !== $date) {
    jsonResponse(422, ['success' => false, 'message' => 'A valid date is required.']);
    exit;
}

$now = new DateTimeImmutable('now', $timezone);
if ($requestedDate->format('Y-m-d') < $now->format('Y-m-d')) {
    jsonResponse(422, ['success' => false, 'message' => 'Past dates are not available.']);
    exit;
}

try {
    if (!function_exists('ensureTimeSlotsForDate')) {
        throw new RuntimeException('Time slot initialization is unavailable.');
    }
    ensureTimeSlotsForDate($pdo, $requestedDate->format('Y-m-d'));

    $sql = "SELECT
                ts.slot_id,
                ts.slot_date,
                to_char(ts.start_time, 'HH24:MI') AS start_time,
                to_char(ts.end_time, 'HH24:MI') AS end_time,
<<<<<<< HEAD
                ts.is_available::text AS is_available
            FROM time_slot ts
            WHERE ts.slot_date = CAST(:slot_date AS date)";
    $sql .= " AND EXTRACT(MINUTE FROM ts.start_time) = 0
              AND ts.end_time = (ts.start_time + INTERVAL '60 minutes')::time";
=======
                ts.is_available::text AS is_available,
                (
                    SELECT COUNT(*)::integer
                    FROM reservation r
                    INNER JOIN time_slot booked_slot ON booked_slot.slot_id = r.slot_id
                    WHERE booked_slot.slot_date = ts.slot_date
                      AND EXTRACT(HOUR FROM booked_slot.start_time) = EXTRACT(HOUR FROM ts.start_time)
                      AND r.reservation_status::text = 'active'
                ) AS booked_count
            FROM time_slot ts
            WHERE ts.slot_date = CAST(:slot_date AS date)
              AND EXTRACT(MINUTE FROM ts.start_time) = 0
              AND ts.end_time = (ts.start_time + INTERVAL '1 hour')::time";
>>>>>>> ed543f9 (feat: switch booking to hourly time slots)
    $params = ['slot_date' => $requestedDate->format('Y-m-d')];

    if ($requestedDate->format('Y-m-d') === $now->format('Y-m-d')) {
        $sql .= ' AND ts.start_time > CAST(:current_time AS time)';
        $params['current_time'] = $now->format('H:i:s');
    }

<<<<<<< HEAD
    $sql .= " ORDER BY ts.start_time";
=======
    $sql .= ' ORDER BY ts.start_time';
>>>>>>> ed543f9 (feat: switch booking to hourly time slots)

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $slots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $capacity = getBookingHourCapacity($pdo, $slot['slot_date'], $slot['start_time']);
        $booked = $capacity['used_count'];
        $remaining = $capacity['available_count'];
        $adminAvailable = strtolower((string)$slot['is_available']) === 'available';

        $slots[] = [
            'slot_id' => (int)$slot['slot_id'],
            'slot_date' => $slot['slot_date'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'is_available' => $slot['is_available'],
            'booked_count' => $booked,
            'capacity' => $capacity['capacity'],
            'remaining_capacity' => $remaining,
            'is_full' => !$adminAvailable || $capacity['capacity'] === 0 || $remaining === 0,
        ];
    }

    jsonResponse(200, ['success' => true, 'data' => $slots]);
    exit;
} catch (Throwable $e) {
    error_log('Availability API error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to load time slot availability.']);
    exit;
}
?>
