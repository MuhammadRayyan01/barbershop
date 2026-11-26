<?php
declare(strict_types=1);

// Booking configuration
// Each clock hour has a maximum capacity of 3 bookings.
// Each one-hour slot uses this capacity.

const BOOKING_SLOT_CAPACITY = 3;
const BOOKING_HOLD_DURATION_MINUTES = 5;

const BOOKING_OPENING_TIME = '10:00:00';
const BOOKING_CLOSING_TIME = '22:00:00';

const BOOKING_SLOT_INTERVAL_MINUTES = 60;

const BOOKING_TIMEZONE = 'Asia/Jakarta';


// Generate time slots for a date
// Creates one-hour slots from 10:00 until 22:00.
// Existing slots are not duplicated.

function ensureTimeSlotsForDate(PDO $pdo, string $date): void
{
    $dateObject = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone(BOOKING_TIMEZONE)
    );

    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid booking date.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO time_slot (
            slot_date,
            start_time,
            end_time
        )
        SELECT
            CAST(:slot_date AS date),
            times.start_time::time,
            (times.start_time + INTERVAL '1 hour')::time
        FROM generate_series(
            TIMESTAMP '2000-01-01 10:00:00',
            TIMESTAMP '2000-01-01 21:00:00',
            INTERVAL '1 hour'
        ) AS times(start_time)
        ON CONFLICT (slot_date, start_time, end_time)
        DO NOTHING"
    );

    $stmt->execute([
        'slot_date' => $date
    ]);
}


// Expire old booking holds
// Holds that have passed their expiration time no longer consume capacity.

function expireBookingHolds(PDO $pdo): int
{
    $stmt = $pdo->prepare(
        "UPDATE booking_hold
         SET
            hold_status = 'expired',
            updated_at = CURRENT_TIMESTAMP
         WHERE hold_status = 'active'
           AND expires_at <= CURRENT_TIMESTAMP"
    );

    $stmt->execute();

    return $stmt->rowCount();
}


// Lock a date and clock hour
// Requests for a clock hour use the same lock.
// This prevents simultaneous requests from exceeding hourly capacity.
// IMPORTANT:
// Call this function inside an active database transaction.

function lockBookingHour(
    PDO $pdo,
    string $slotDate,
    string $startTime
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException(
            'Hourly booking locks require an active transaction.'
        );
    }

    $dateObject = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $slotDate,
        new DateTimeZone(BOOKING_TIMEZONE)
    );

    if (!$dateObject || $dateObject->format('Y-m-d') !== $slotDate) {
        throw new InvalidArgumentException('Invalid booking date.');
    }

    $timeObject = DateTimeImmutable::createFromFormat(
        '!H:i:s',
        $startTime
    );

    if (!$timeObject) {
        $timeObject = DateTimeImmutable::createFromFormat(
            '!H:i',
            $startTime
        );
    }

    if (!$timeObject) {
        throw new InvalidArgumentException('Invalid booking time.');
    }

    $hour = (int) $timeObject->format('H');

    $lockKey = $slotDate . ':' . str_pad(
        (string) $hour,
        2,
        '0',
        STR_PAD_LEFT
    );

    $stmt = $pdo->prepare(
        'SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))'
    );

    $stmt->execute([
        'lock_key' => $lockKey
    ]);
}


// Retrieve a time slot

function getBookingSlot(PDO $pdo, int $slotId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            slot_id,
            slot_date,
            start_time,
            end_time,
            is_available::text AS is_available
         FROM time_slot
         WHERE slot_id = :slot_id"
    );

    $stmt->execute([
        'slot_id' => $slotId
    ]);

    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    return $slot ?: null;
}


/**
 * Only full-hour slots are bookable through the current customer flow.
 * Legacy half-hour rows remain in the database to preserve old reservations.
 */
function isBookableHourlySlot(array $slot): bool
{
    $startText = substr(trim((string) ($slot['start_time'] ?? '')), 0, 8);
    $endText = substr(trim((string) ($slot['end_time'] ?? '')), 0, 8);
    if (strlen($startText) === 5) $startText .= ':00';
    if (strlen($endText) === 5) $endText .= ':00';

    $start = DateTimeImmutable::createFromFormat('!H:i:s', $startText);
    $end = DateTimeImmutable::createFromFormat('!H:i:s', $endText);

    if ($start === false || $end === false || $start->format('i:s') !== '00:00') {
        return false;
    }

    $expectedEnd = $start
        ->modify('+' . BOOKING_SLOT_INTERVAL_MINUTES . ' minutes')
        ->format('H:i:s');

    return $end->format('H:i:s') === $expectedEnd;
}


// Calculate shared hourly capacity
// Counts:
// 1. Active permanent reservations.
// 2. Active temporary holds that have not expired.
// All booking records in the clock hour count toward the slot capacity.

function getBookingHourCapacity(
    PDO $pdo,
    string $slotDate,
    string $startTime
): array {
    $capacityStmt = $pdo->prepare(
        "SELECT COALESCE(MIN(booking_capacity), CAST(:default_capacity AS smallint))
         FROM time_slot
         WHERE slot_date = CAST(:slot_date AS date)
           AND EXTRACT(HOUR FROM start_time) = EXTRACT(HOUR FROM CAST(:start_time AS time))"
    );
    $capacityStmt->execute([
        'default_capacity' => BOOKING_SLOT_CAPACITY,
        'slot_date' => $slotDate,
        'start_time' => $startTime,
    ]);
    $hourCapacity = (int) $capacityStmt->fetchColumn();

    $reservationStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM reservation r
         INNER JOIN time_slot ts
            ON ts.slot_id = r.slot_id
         WHERE ts.slot_date = CAST(:slot_date AS date)
           AND EXTRACT(HOUR FROM ts.start_time)
                = EXTRACT(HOUR FROM CAST(:start_time AS time))
           AND r.reservation_status::text = 'active'"
    );

    $reservationStmt->execute([
        'slot_date' => $slotDate,
        'start_time' => $startTime
    ]);

    $bookedCount = (int) $reservationStmt->fetchColumn();


    $holdStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM booking_hold bh
         INNER JOIN time_slot ts
            ON ts.slot_id = bh.slot_id
         WHERE ts.slot_date = CAST(:slot_date AS date)
           AND EXTRACT(HOUR FROM ts.start_time)
                = EXTRACT(HOUR FROM CAST(:start_time AS time))
           AND bh.hold_status = 'active'
           AND bh.expires_at > CURRENT_TIMESTAMP"
    );

    $holdStmt->execute([
        'slot_date' => $slotDate,
        'start_time' => $startTime
    ]);

    $heldCount = (int) $holdStmt->fetchColumn();


    $totalUsed = $bookedCount + $heldCount;

    return [
        'capacity' => $hourCapacity,
        'booked_count' => $bookedCount,
        'held_count' => $heldCount,
        'used_count' => $totalUsed,
        'available_count' => max(
            0,
            $hourCapacity - $totalUsed
        ),
        'is_full' => $totalUsed >= $hourCapacity
    ];
}
