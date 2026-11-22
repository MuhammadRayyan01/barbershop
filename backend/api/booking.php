<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/response.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/booking.php';

header('Cache-Control: no-store');
header('Content-Type: application/json; charset=UTF-8');


/*
|--------------------------------------------------------------------------
| JSON response helper
|--------------------------------------------------------------------------
*/

function bookingApiResponse(int $status, array $payload): never
{
    http_response_code($status);
    jsonResponse($status, $payload);
    exit;
}


/*
|--------------------------------------------------------------------------
| Request body helper
|--------------------------------------------------------------------------
*/

function bookingReadJsonBody(): array
{
    try {
        $raw = file_get_contents('php://input');

        $body = json_decode(
            $raw ?: '',
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($body)) {
            bookingApiResponse(400, [
                'success' => false,
                'message' => 'Invalid request body.'
            ]);
        }

        return $body;

    } catch (JsonException $e) {
        bookingApiResponse(400, [
            'success' => false,
            'message' => 'Invalid JSON request.'
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| Validate date
|--------------------------------------------------------------------------
*/

function bookingValidateDate(string $date): bool
{
    $dateObject = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone(BOOKING_TIMEZONE)
    );

    return $dateObject !== false
        && $dateObject->format('Y-m-d') === $date;
}


/*
|--------------------------------------------------------------------------
| Get customer ID
|--------------------------------------------------------------------------
*/

$userId = filter_var(
    $_SESSION['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$userId || $userId < 1) {
    bookingApiResponse(401, [
        'success' => false,
        'message' => 'Please log in first.'
    ]);
}


/*
|--------------------------------------------------------------------------
| GET: Availability
|--------------------------------------------------------------------------
|
| Example:
| GET /backend/api/booking.php?date=2026-11-14
|
*/

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {

    $timezone = new DateTimeZone(BOOKING_TIMEZONE);

    $date = trim((string) ($_GET['date'] ?? ''));

    if ($date === '') {
        $date = (new DateTimeImmutable('now', $timezone))
            ->format('Y-m-d');
    }

    if (!bookingValidateDate($date)) {
        bookingApiResponse(422, [
            'success' => false,
            'message' => 'Invalid booking date.'
        ]);
    }

    $today = new DateTimeImmutable('today', $timezone);
    $requestedDate = new DateTimeImmutable($date, $timezone);

    if ($requestedDate < $today) {
        bookingApiResponse(422, [
            'success' => false,
            'message' => 'You cannot select a date in the past.'
        ]);
    }

    try {

        ensureTimeSlotsForDate($pdo, $date);

        expireBookingHolds($pdo);

        $stmt = $pdo->prepare(
            "SELECT
                slot_id,
                slot_date::text AS slot_date,
                TO_CHAR(start_time, 'HH24:MI:SS') AS start_time,
                TO_CHAR(end_time, 'HH24:MI:SS') AS end_time,
                is_available::text AS is_available
             FROM time_slot
             WHERE slot_date = CAST(:slot_date AS date)
               AND EXTRACT(MINUTE FROM start_time) = 0
               AND end_time = (start_time + INTERVAL '60 minutes')::time
             ORDER BY start_time"
        );

        $stmt->execute([
            'slot_date' => $date
        ]);

        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = new DateTimeImmutable('now', $timezone);

        $result = [];

        foreach ($slots as $slot) {

            $capacity = getBookingHourCapacity(
                $pdo,
                $date,
                $slot['start_time']
            );

            $slotDateTime = new DateTimeImmutable(
                $date . ' ' . $slot['start_time'],
                $timezone
            );

            $isFuture = $slotDateTime > $now;

            $slotStatus = strtolower(
                (string) $slot['is_available']
            );

            $isAvailable = (
                $isFuture
                && $slotStatus === 'available'
                && $capacity['available_count'] > 0
            );

            $result[] = [
                'slot_id' => (int) $slot['slot_id'],
                'slot_date' => $slot['slot_date'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],

                'capacity' => $capacity['capacity'],
                'booked_count' => $capacity['booked_count'],
                'held_count' => $capacity['held_count'],
                'used_count' => $capacity['used_count'],
                'available_count' => $capacity['available_count'],

                'is_full' => $capacity['is_full'],
                'is_available' => $isAvailable
            ];
        }

        bookingApiResponse(200, [
            'success' => true,
            'message' => 'Availability retrieved successfully.',
            'data' => $result
        ]);

    } catch (Throwable $e) {

        error_log(
            'Booking availability error: ' . $e->getMessage()
        );

        bookingApiResponse(500, [
            'success' => false,
            'message' => 'Unable to retrieve booking availability.'
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| Only POST is allowed for booking actions
|--------------------------------------------------------------------------
*/

if ($method !== 'POST') {

    header('Allow: GET, POST');

    bookingApiResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}


/*
|--------------------------------------------------------------------------
| Read POST body and validate CSRF
|--------------------------------------------------------------------------
*/

try {

    $body = bookingReadJsonBody();

    requireValidCsrfToken();

} catch (Throwable $e) {

    bookingApiResponse(403, [
        'success' => false,
        'message' => 'Invalid or missing CSRF token.'
    ]);
}


$action = trim((string) ($body['action'] ?? ''));


/*
|--------------------------------------------------------------------------
| POST: Retrieve customer's existing active booking hold
|--------------------------------------------------------------------------
*/

if ($action === 'get_active_hold') {

    try {

        // Mark expired holds before retrieving the customer's hold.
        expireBookingHolds($pdo);

        $stmt = $pdo->prepare(
            "SELECT
                bh.hold_id,
                bh.hold_token,
                bh.slot_id,
                bh.service_id,
                bh.additional_service_id,
                bh.total_amount,
                bh.expires_at,

                ts.slot_date::text AS slot_date,
                TO_CHAR(ts.start_time, 'HH24:MI:SS') AS start_time,
                TO_CHAR(ts.end_time, 'HH24:MI:SS') AS end_time,

                s.service_name,

                a.additional_service_name

             FROM booking_hold bh

             INNER JOIN time_slot ts
                ON ts.slot_id = bh.slot_id

             INNER JOIN service s
                ON s.service_id = bh.service_id

             LEFT JOIN additional_service a
                ON a.additional_service_id = bh.additional_service_id

             WHERE bh.user_id = :user_id
               AND bh.hold_status = 'active'
               AND bh.expires_at > CURRENT_TIMESTAMP

             ORDER BY bh.created_at DESC

             LIMIT 1"
        );

        $stmt->execute([
            'user_id' => $userId
        ]);

        $hold = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            bookingApiResponse(200, [
                'success' => true,
                'message' => 'No active booking hold found.',
                'data' => null
            ]);
        }

        bookingApiResponse(200, [
            'success' => true,
            'message' => 'Active booking hold retrieved successfully.',
            'data' => [
                'hold_id' => (int) $hold['hold_id'],
                'hold_token' => $hold['hold_token'],
                'slot_id' => (int) $hold['slot_id'],
                'service_id' => (int) $hold['service_id'],
                'additional_service_id' => $hold['additional_service_id'] !== null
                    ? (int) $hold['additional_service_id']
                    : null,
                'total_amount' => (float) $hold['total_amount'],
                'expires_at' => $hold['expires_at'],
                'slot_date' => $hold['slot_date'],
                'start_time' => $hold['start_time'],
                'end_time' => $hold['end_time'],
                'service_name' => $hold['service_name'],
                'additional_service_name' => $hold['additional_service_name']
            ]
        ]);

    } catch (Throwable $e) {

        error_log(
            'Retrieve active booking hold error: ' . $e->getMessage()
        );

        bookingApiResponse(500, [
            'success' => false,
            'message' => 'Unable to retrieve your active booking.'
        ]);
    }
}



/*
|--------------------------------------------------------------------------
| POST: Create temporary booking hold
|--------------------------------------------------------------------------
|
| Required JSON:
|
| {
|   "action": "create_hold",
|   "slot_id": 1,
|   "service_id": 1,
|   "additional_service_id": null
| }
|
*/

if ($action === 'create_hold') {

    $slotId = filter_var(
        $body['slot_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $serviceId = filter_var(
        $body['service_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $addonRaw = $body['additional_service_id'] ?? null;

    $addonId = (
        $addonRaw === null || $addonRaw === ''
    )
        ? null
        : filter_var($addonRaw, FILTER_VALIDATE_INT);

    if (
        !$slotId || $slotId < 1 ||
        !$serviceId || $serviceId < 1 ||
        (
            $addonRaw !== null &&
            $addonRaw !== '' &&
            (!$addonId || $addonId < 1)
        )
    ) {
        bookingApiResponse(422, [
            'success' => false,
            'message' => 'Please select a valid service and time slot.'
        ]);
    }

    try {

        $pdo->beginTransaction();

        // Expire previous holds before checking capacity.
        expireBookingHolds($pdo);

        // Retrieve the selected slot.
        $slot = getBookingSlot($pdo, $slotId);

        if (!$slot) {
            $pdo->rollBack();

            bookingApiResponse(404, [
                'success' => false,
                'message' => 'Selected time slot does not exist.'
            ]);
        }

        if (!isBookableHourlySlot($slot)) {
            $pdo->rollBack();

            bookingApiResponse(409, [
                'success' => false,
                'message' => 'This time slot is no longer available. Please choose an hourly slot.'
            ]);
        }

        // Lock the shared clock hour.
        lockBookingHour(
            $pdo,
            $slot['slot_date'],
            $slot['start_time']
        );

        // Lock the individual slot row as well.
        $slot = getBookingSlotForUpdate($pdo, $slotId);

        if (!$slot) {
            $pdo->rollBack();

            bookingApiResponse(404, [
                'success' => false,
                'message' => 'Selected time slot does not exist.'
            ]);
        }

        if (strtolower((string) $slot['is_available']) !== 'available') {
            $pdo->rollBack();

            bookingApiResponse(409, [
                'success' => false,
                'message' => 'This time slot is unavailable.'
            ]);
        }

        // Reject past booking times.
        $timezone = new DateTimeZone(BOOKING_TIMEZONE);

        $slotDateTime = new DateTimeImmutable(
            $slot['slot_date'] . ' ' . $slot['start_time'],
            $timezone
        );

        $now = new DateTimeImmutable('now', $timezone);

        if ($slotDateTime <= $now) {
            $pdo->rollBack();

            bookingApiResponse(422, [
                'success' => false,
                'message' => 'This time has already passed.'
            ]);
        }

        // Prevent one customer from holding multiple slots.
        $existingHoldStmt = $pdo->prepare(
            "SELECT hold_id
             FROM booking_hold
             WHERE user_id = :user_id
               AND hold_status = 'active'
               AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1"
        );

        $existingHoldStmt->execute([
            'user_id' => $userId
        ]);

        if ($existingHoldStmt->fetchColumn()) {
            $pdo->rollBack();

            bookingApiResponse(409, [
                'success' => false,
                'message' => 'You already have an active booking hold. Please complete or release it first.'
            ]);
        }

        // Check shared hourly capacity.
        $capacity = getBookingHourCapacity(
            $pdo,
            $slot['slot_date'],
            $slot['start_time']
        );

        if ($capacity['available_count'] <= 0) {
            $pdo->rollBack();

            bookingApiResponse(409, [
                'success' => false,
                'message' => 'This hour is fully booked. Please select another time.'
            ]);
        }

        // Retrieve the main service.
        $serviceStmt = $pdo->prepare(
            "SELECT
                service_id,
                service_name,
                price
             FROM service
             WHERE service_id = :service_id
               AND status::text = 'active'"
        );

        $serviceStmt->execute([
            'service_id' => $serviceId
        ]);

        $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$service) {
            $pdo->rollBack();

            bookingApiResponse(422, [
                'success' => false,
                'message' => 'Selected service is unavailable.'
            ]);
        }

        // Retrieve optional additional service.
        $addon = null;

        if ($addonId !== null) {

            $addonStmt = $pdo->prepare(
                "SELECT
                    additional_service_id,
                    additional_service_name,
                    additional_service_price
                 FROM additional_service
                 WHERE additional_service_id = :addon_id
                   AND service_id = :service_id"
            );

            $addonStmt->execute([
                'addon_id' => $addonId,
                'service_id' => $serviceId
            ]);

            $addon = $addonStmt->fetch(PDO::FETCH_ASSOC);

            if (!$addon) {
                $pdo->rollBack();

                bookingApiResponse(422, [
                    'success' => false,
                    'message' => 'Selected additional service is invalid.'
                ]);
            }
        }

        // Check customer profile.
        $customerStmt = $pdo->prepare(
            "SELECT full_name, phone_number
             FROM users
             WHERE user_id = :user_id"
        );

        $customerStmt->execute([
            'user_id' => $userId
        ]);

        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$customer ||
            trim((string) ($customer['full_name'] ?? '')) === '' ||
            trim((string) ($customer['phone_number'] ?? '')) === ''
        ) {
            $pdo->rollBack();

            bookingApiResponse(422, [
                'success' => false,
                'message' => 'Please complete your name and phone number in your profile before booking.'
            ]);
        }

        // Calculate price using database values.
        $servicePrice = (float) $service['price'];

        $addonPrice = $addon
            ? (float) $addon['additional_service_price']
            : null;

        $totalAmount = $servicePrice + ($addonPrice ?? 0);

        if ($totalAmount <= 0) {
            $pdo->rollBack();

            bookingApiResponse(422, [
                'success' => false,
                'message' => 'Invalid booking amount.'
            ]);
        }

        // Generate a secure 64-character hold token.
        $holdToken = bin2hex(random_bytes(32));

        // Create the five-minute hold.
        $insertStmt = $pdo->prepare(
            "INSERT INTO booking_hold (
                hold_token,
                user_id,
                slot_id,
                service_id,
                service_price,
                additional_service_id,
                additional_service_price,
                total_amount,
                hold_status,
                expires_at
             ) VALUES (
                :hold_token,
                :user_id,
                :slot_id,
                :service_id,
                :service_price,
                :addon_id,
                :addon_price,
                :total_amount,
                'active',
                CURRENT_TIMESTAMP + INTERVAL '5 minutes'
             )
             RETURNING
                hold_id,
                expires_at"
        );

        $insertStmt->execute([
            'hold_token' => $holdToken,
            'user_id' => $userId,
            'slot_id' => $slotId,
            'service_id' => $serviceId,
            'service_price' => $servicePrice,
            'addon_id' => $addonId,
            'addon_price' => $addonPrice,
            'total_amount' => $totalAmount
        ]);

        $hold = $insertStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();

        bookingApiResponse(201, [
            'success' => true,
            'message' => 'Your time slot has been held for five minutes.',
            'data' => [
                'hold_id' => (int) $hold['hold_id'],
                'hold_token' => $holdToken,
                'expires_at' => $hold['expires_at'],
                'duration_seconds' => 300,
                'total_amount' => $totalAmount
            ]
        ]);

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Create booking hold error: ' . $e->getMessage()
        );

        bookingApiResponse(500, [
            'success' => false,
            'message' => 'Unable to hold this time slot. Please try again.'
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| POST: Release temporary booking hold
|--------------------------------------------------------------------------
|
| Required JSON:
|
| {
|   "action": "release_hold",
|   "hold_token": "..."
| }
|
*/

if ($action === 'release_hold') {

    $holdToken = trim(
        (string) ($body['hold_token'] ?? '')
    );

    if (!preg_match('/^[a-f0-9]{64}$/', $holdToken)) {
        bookingApiResponse(422, [
            'success' => false,
            'message' => 'Invalid booking hold token.'
        ]);
    }

    try {

        $pdo->beginTransaction();

        // Find the customer's hold.
        $holdStmt = $pdo->prepare(
            "SELECT
                hold_id,
                slot_id,
                hold_status
             FROM booking_hold
             WHERE hold_token = :hold_token
               AND user_id = :user_id"
        );

        $holdStmt->execute([
            'hold_token' => $holdToken,
            'user_id' => $userId
        ]);

        $hold = $holdStmt->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            $pdo->rollBack();

            bookingApiResponse(404, [
                'success' => false,
                'message' => 'Booking hold not found.'
            ]);
        }

        if ($hold['hold_status'] !== 'active') {
            $pdo->rollBack();

            bookingApiResponse(409, [
                'success' => false,
                'message' => 'This booking hold is no longer active.'
            ]);
        }

        // Retrieve slot and lock its shared hour.
        $slot = getBookingSlot($pdo, (int) $hold['slot_id']);

        if (!$slot) {
            $pdo->rollBack();

            bookingApiResponse(404, [
                'success' => false,
                'message' => 'Booking slot not found.'
            ]);
        }

        lockBookingHour(
            $pdo,
            $slot['slot_date'],
            $slot['start_time']
        );

        $updateStmt = $pdo->prepare(
            "UPDATE booking_hold
             SET
                hold_status = 'released',
                updated_at = CURRENT_TIMESTAMP
             WHERE hold_id = :hold_id
               AND user_id = :user_id
               AND hold_status = 'active'"
        );

        $updateStmt->execute([
            'hold_id' => $hold['hold_id'],
            'user_id' => $userId
        ]);

        if ($updateStmt->rowCount() !== 1) {
            $pdo->rollBack();

            bookingApiResponse(409, [
                'success' => false,
                'message' => 'Booking hold could not be released.'
            ]);
        }

        $pdo->commit();

        bookingApiResponse(200, [
            'success' => true,
            'message' => 'Booking hold released successfully.'
        ]);

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log(
            'Release booking hold error: ' . $e->getMessage()
        );

        bookingApiResponse(500, [
            'success' => false,
            'message' => 'Unable to release booking hold.'
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| Unknown action
|--------------------------------------------------------------------------
*/

bookingApiResponse(400, [
    'success' => false,
    'message' => 'Unknown booking action.'
]);


/*
|--------------------------------------------------------------------------
| Retrieve and lock a time slot
|--------------------------------------------------------------------------
*/

function getBookingSlotForUpdate(PDO $pdo, int $slotId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            slot_id,
            slot_date,
            start_time,
            end_time,
            is_available::text AS is_available
         FROM time_slot
         WHERE slot_id = :slot_id
         FOR UPDATE"
    );

    $stmt->execute([
        'slot_id' => $slotId
    ]);

    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    return $slot ?: null;
}

