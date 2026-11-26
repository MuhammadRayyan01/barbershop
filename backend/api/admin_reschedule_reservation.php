<?php
declare(strict_types=1);

require_once __DIR__ . '/../middleware/require_admin_api.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/request.php';
require_once __DIR__ . '/../config/booking.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

requireValidCsrfToken();
$data = readJsonResponse();
$reservationId = filter_var($data['reservation_id'] ?? null, FILTER_VALIDATE_INT);
$targetSlotId = filter_var($data['slot_id'] ?? null, FILTER_VALIDATE_INT);
$adminId = (int) $_SESSION['admin_id'];
$customerAgreed = ($data['customer_agreed'] ?? false) === true;
$reason = $data['reason'] ?? 'Kendala operasional';

if (
    !$reservationId || $reservationId < 1 ||
    !$targetSlotId || $targetSlotId < 1 ||
    !$customerAgreed ||
    !is_string($reason)
) {
    jsonResponse(422, ['success' => false, 'message' => 'Reservasi, slot tujuan, dan persetujuan pelanggan harus tersedia.']);
}

$reason = trim($reason);
if ($reason === '') $reason = 'Kendala operasional';
if (preg_match('/^.{0,500}$/us', $reason) !== 1) {
    jsonResponse(422, ['success' => false, 'message' => 'Catatan kendala maksimal 500 karakter.']);
}

$timezone = new DateTimeZone(BOOKING_TIMEZONE);
$now = new DateTimeImmutable('now', $timezone);

try {
    $targetStmt = $pdo->prepare("SELECT slot_id, slot_date::text AS slot_date,
            start_time::text AS start_time, end_time::text AS end_time,
            is_available::text AS is_available
        FROM time_slot
        WHERE slot_id = :slot_id
          AND EXTRACT(MINUTE FROM start_time) = 0
          AND end_time = (start_time + INTERVAL '1 hour')::time");
    $targetStmt->execute(['slot_id' => $targetSlotId]);
    $targetPreview = $targetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$targetPreview) {
        jsonResponse(404, ['success' => false, 'message' => 'Slot tujuan tidak ditemukan.']);
    }
    if ($targetPreview['slot_date'] < $now->format('Y-m-d')) {
        jsonResponse(422, ['success' => false, 'message' => 'Jadwal tujuan harus hari ini atau setelahnya.']);
    }

    ensureTimeSlotsForDate($pdo, (string) $targetPreview['slot_date']);
    $pdo->beginTransaction();

    $reservationStmt = $pdo->prepare("SELECT r.reservation_id,
            r.reservation_status::text AS reservation_status,
            r.slot_id AS current_slot_id,
            p.payment_status::text AS payment_status,
            current_slot.slot_date::text AS current_date,
            current_slot.start_time::text AS current_start
        FROM reservation r
        JOIN time_slot current_slot ON current_slot.slot_id = r.slot_id
        JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
        FOR UPDATE OF r, p");
    $reservationStmt->execute(['reservation_id' => $reservationId]);
    $reservation = $reservationStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reservation) {
        $pdo->rollBack();
        jsonResponse(404, ['success' => false, 'message' => 'Reservasi tidak ditemukan.']);
    }
    if ($reservation['reservation_status'] !== 'active' || $reservation['payment_status'] !== 'paid') {
        $pdo->rollBack();
        jsonResponse(409, ['success' => false, 'message' => 'Hanya reservasi aktif dan lunas yang dapat dijadwalkan ulang.']);
    }

    if ((int) $reservation['current_slot_id'] === $targetSlotId) {
        $pdo->rollBack();
        jsonResponse(422, ['success' => false, 'message' => 'Pilih slot yang berbeda dari jadwal saat ini.']);
    }
    if (
        $reservation['current_date'] === $targetPreview['slot_date'] &&
        substr((string) $reservation['current_start'], 0, 2) === substr((string) $targetPreview['start_time'], 0, 2)
    ) {
        $pdo->rollBack();
        jsonResponse(422, ['success' => false, 'message' => 'Pilih jam yang berbeda dari jadwal saat ini.']);
    }

    $hoursToLock = [
        [(string) $reservation['current_date'], (string) $reservation['current_start']],
        [(string) $targetPreview['slot_date'], (string) $targetPreview['start_time']],
    ];
    usort($hoursToLock, static fn(array $a, array $b): int => [$a[0], substr($a[1], 0, 2)] <=> [$b[0], substr($b[1], 0, 2)]);
    foreach ($hoursToLock as [$slotDate, $startTime]) {
        lockBookingHour($pdo, $slotDate, $startTime);
    }

    $targetLockStmt = $pdo->prepare("SELECT slot_id, slot_date::text AS slot_date,
            start_time::text AS start_time, end_time::text AS end_time,
            is_available::text AS is_available
        FROM time_slot WHERE slot_id = :slot_id FOR UPDATE");
    $targetLockStmt->execute(['slot_id' => $targetSlotId]);
    $target = $targetLockStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        $pdo->rollBack();
        jsonResponse(404, ['success' => false, 'message' => 'Slot tujuan tidak ditemukan.']);
    }

    $targetStart = new DateTimeImmutable($target['slot_date'] . ' ' . $target['start_time'], $timezone);
    if ($targetStart <= new DateTimeImmutable('now', $timezone)) {
        $pdo->rollBack();
        jsonResponse(422, ['success' => false, 'message' => 'Pilih jadwal yang belum lewat.']);
    }
    if (strtolower((string) $target['is_available']) !== 'available') {
        $pdo->rollBack();
        jsonResponse(409, ['success' => false, 'message' => 'Slot tujuan ditutup. Pilih slot lain.']);
    }

    expireBookingHolds($pdo);
    $capacity = getBookingHourCapacity($pdo, (string) $target['slot_date'], (string) $target['start_time']);
    if ($capacity['capacity'] < 1 || $capacity['used_count'] >= $capacity['capacity']) {
        $pdo->rollBack();
        jsonResponse(409, ['success' => false, 'message' => 'Slot tujuan sudah penuh. Pilih slot lain.']);
    }

    $update = $pdo->prepare("UPDATE reservation
        SET slot_id = :slot_id, updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
        WHERE reservation_id = :reservation_id AND reservation_status = 'active'");
    $update->execute(['slot_id' => $targetSlotId, 'reservation_id' => $reservationId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('Reservation changed while the schedule update was in progress.');
    }

    $audit = $pdo->prepare("INSERT INTO reservation_schedule_change
        (reservation_id, old_slot_id, new_slot_id, admin_id, customer_agreed, reason)
        VALUES (:reservation_id, :old_slot_id, :new_slot_id, :admin_id, TRUE, :reason)");
    $audit->execute([
        'reservation_id' => $reservationId,
        'old_slot_id' => $reservation['current_slot_id'],
        'new_slot_id' => $targetSlotId,
        'admin_id' => $adminId,
        'reason' => $reason,
    ]);

    $pdo->commit();
    jsonResponse(200, ['success' => true, 'message' => 'Jadwal reservasi berhasil dipindahkan. Pembayaran tetap tercatat.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Admin reservation reschedule error: ' . $error->getMessage());
    if ($error instanceof PDOException && $error->getCode() === '42P01' && str_contains($error->getMessage(), 'reservation_schedule_change')) {
        jsonResponse(503, ['success' => false, 'message' => 'Fitur ganti jadwal belum siap. Terapkan migrasi reservation_schedule_change terlebih dahulu.']);
    }
    jsonResponse(500, ['success' => false, 'message' => 'Jadwal gagal dipindahkan. Periksa log server atau coba lagi.']);
}
