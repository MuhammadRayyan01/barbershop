<?php
declare(strict_types=1);

// Shared capacity contract for availability display and reservation creation.
const BOOKING_SLOT_CAPACITY = 3;

function ensureTimeSlotsForDate(PDO $pdo, string $date): void
{
    $stmt = $pdo->prepare("INSERT INTO time_slot (slot_date, start_time, end_time)
        SELECT CAST(:slot_date AS date), times.start_time::time,
            (times.start_time + INTERVAL '30 minutes')::time
        FROM generate_series(
            TIMESTAMP '2000-01-01 10:00:00',
            TIMESTAMP '2000-01-01 21:30:00',
            INTERVAL '30 minutes'
        ) AS times(start_time)
        ON CONFLICT (slot_date, start_time, end_time) DO NOTHING");
    $stmt->execute(['slot_date' => $date]);
}
