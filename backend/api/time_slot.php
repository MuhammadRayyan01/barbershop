<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');

    jsonResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$date = isset($_GET['date']) && is_string($_GET['date'])
    ? trim($_GET['date'])
    : '';

<<<<<<< HEAD
if ($date === '') {
    jsonResponse(422, ['success' => false, 'message' => 'A valid date is required.']);
}

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    jsonResponse(422, ['success' => false, 'message' => 'A valid date is required.']);
}

require_once __DIR__ . '/../config/booking.php';

try{
=======
if ($category === '') {
    jsonResponse(400, [
        'success' => false,
        'message' => 'Category is required.'
    ]);
}

try {

    // Generate time slots for today and the next two days
    $generateSlots = $pdo->prepare("
        INSERT INTO time_slot (
            slot_date,
            start_time,
            end_time
        )
        SELECT
            dates.slot_date::date,
            times.start_time::time,
            (times.start_time + INTERVAL '1 hour')::time
        FROM generate_series(
            CURRENT_DATE::timestamp,
            (CURRENT_DATE + 2)::timestamp,
            INTERVAL '1 day'
        ) AS dates(slot_date)
        CROSS JOIN generate_series(
            TIMESTAMP '2000-01-01 10:00:00',
            TIMESTAMP '2000-01-01 20:00:00',
            INTERVAL '1 hour'
        ) AS times(start_time)
        ON CONFLICT (slot_date, start_time, end_time)
        DO NOTHING
    ");

    $generateSlots->execute();

    // Retrieve available time slots
>>>>>>> 9684674 (feat : automatic time slots)
    $stmt = $pdo->prepare("
        SELECT
            ts.slot_id,
            ts.slot_date,
<<<<<<< HEAD
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
=======
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
        AND ts.slot_date <= CURRENT_DATE + 2
        GROUP BY
            ts.slot_id,
            ts.slot_date,
            ts.start_time,
            ts.end_time
        HAVING COUNT(r.reservation_id) < 3
        ORDER BY
            ts.slot_date,
            ts.start_time
    ");

    $stmt->execute();
>>>>>>> 9684674 (feat : automatic time slots)

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
            'is_full' => $bookedCount >= BOOKING_SLOT_CAPACITY,
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

<<<<<<< HEAD
    jsonResponse(200, ['success' => true, 'data' => $slots]);
}catch(PDOException $e){
    error_log('Time slot API error: ' . $e->getMessage());

    jsonResponse(500, ['success' => false, 'message' => 'Unable to load time slot availability.']);
}
?>
=======
    jsonResponse(200, [
        'success' => true,
        'data' => $slots
    ]);

} catch (PDOException $e) {

    error_log('Time slot API error: ' . $e->getMessage());

    jsonResponse(500, [
        'success' => false,
        'message' => 'Unable to load available time slots.'
    ]);
}

>>>>>>> 9684674 (feat : automatic time slots)
