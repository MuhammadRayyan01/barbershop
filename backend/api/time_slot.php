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
    if ($requestedDate >= new DateTimeImmutable('today', $timezone)) {
        ensureTimeSlotsForDate($pdo, $date);
    }
    $stmt = $pdo->prepare("
        SELECT
            ts.slot_id,
            ts.slot_date,
            to_char(ts.start_time, 'HH24:MI') AS start_time,
            to_char(ts.end_time, 'HH24:MI') AS end_time,
<<<<<<< HEAD
            CASE
                WHEN BOOL_AND(hour_slots.is_available)
                     AND MIN(hour_slots.booking_capacity) > 0
                THEN 'available'
                ELSE 'not available'
            END AS is_available,
            COALESCE(hour_bookings.booked_count, 0)::integer AS booked_count,
            MIN(hour_slots.booking_capacity)::integer AS hourly_capacity
        FROM time_slot AS ts
        INNER JOIN (
            SELECT
                all_slots.slot_date,
                date_trunc('hour', DATE '2000-01-01' + all_slots.start_time) AS slot_hour,
                BOOL_AND(all_slots.is_available::text = 'available') AS is_available,
                MIN(all_slots.booking_capacity) AS booking_capacity
            FROM time_slot AS all_slots
            GROUP BY all_slots.slot_date, date_trunc('hour', DATE '2000-01-01' + all_slots.start_time)
        ) AS hour_slots
            ON hour_slots.slot_date = ts.slot_date
           AND hour_slots.slot_hour = date_trunc('hour', DATE '2000-01-01' + ts.start_time)
        LEFT JOIN LATERAL (
            SELECT
                (SELECT COUNT(*)
                 FROM reservation r
                 INNER JOIN time_slot booked_slot ON booked_slot.slot_id = r.slot_id
                 WHERE r.reservation_status = 'active'
                   AND booked_slot.slot_date = ts.slot_date
                   AND date_trunc('hour', DATE '2000-01-01' + booked_slot.start_time) = date_trunc('hour', DATE '2000-01-01' + ts.start_time))
                +
                (SELECT COUNT(*)
                 FROM booking_hold bh
                 INNER JOIN time_slot held_slot ON held_slot.slot_id = bh.slot_id
                 WHERE bh.hold_status = 'active'
                   AND bh.expires_at > CURRENT_TIMESTAMP
                   AND held_slot.slot_date = ts.slot_date
                   AND date_trunc('hour', DATE '2000-01-01' + held_slot.start_time) = date_trunc('hour', DATE '2000-01-01' + ts.start_time))
                AS booked_count
        ) AS hour_bookings ON TRUE
        WHERE ts.slot_date = CAST(:slot_date AS date)
          AND ts.slot_date >= CURRENT_DATE
          AND EXTRACT(MINUTE FROM ts.start_time) = 0
          AND ts.end_time = (ts.start_time + INTERVAL '60 minutes')::time
        GROUP BY ts.slot_id, ts.slot_date, ts.start_time, ts.end_time,
                 hour_slots.is_available, hour_bookings.booked_count
=======
            ts.is_available::text AS is_available,
            (
                SELECT COUNT(*)::integer
                FROM reservation booked_reservation
                INNER JOIN time_slot booked_slot ON booked_slot.slot_id = booked_reservation.slot_id
                WHERE booked_slot.slot_date = ts.slot_date
                  AND EXTRACT(HOUR FROM booked_slot.start_time) = EXTRACT(HOUR FROM ts.start_time)
                  AND booked_reservation.reservation_status = 'active'
            ) AS booked_count
        FROM time_slot AS ts
        WHERE ts.slot_date = CAST(:slot_date AS date)
          AND ts.slot_date >= CURRENT_DATE
          AND EXTRACT(MINUTE FROM ts.start_time) = 0
          AND ts.end_time = (ts.start_time + INTERVAL '1 hour')::time
>>>>>>> ed543f9 (feat: switch booking to hourly time slots)
        ORDER BY ts.start_time
    ");
    $stmt->execute(['slot_date' => $date]);

    $slots = array_map(static function (array $slot): array {
        $bookedCount = (int) $slot['booked_count'];
        $capacity = (int) $slot['hourly_capacity'];
        $remainingCapacity = max(0, $capacity - $bookedCount);

        return [
            'slot_id' => (int) $slot['slot_id'],
            'slot_date' => $slot['slot_date'],
            'start_time' => $slot['start_time'],
            'end_time' => $slot['end_time'],
            'is_available' => $slot['is_available'],
            'booked_count' => $bookedCount,
            'capacity' => $capacity,
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
