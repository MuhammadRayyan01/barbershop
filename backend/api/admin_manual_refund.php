<?php
declare(strict_types=1);

require_once __DIR__ . '/../middleware/require_admin_api.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/request.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    jsonResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

requireValidCsrfToken();
$data = readJsonResponse();
$reservationId = filter_var($data['reservation_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($data['reason'] ?? 'Kendala operasional'));
if (!$reservationId || $reservationId < 1 || $reason === '') {
    jsonResponse(422, ['success' => false, 'message' => 'Reservasi dan alasan pembatalan harus diisi.']);
}
$reason = substr($reason, 0, 500);
$timezone = new DateTimeZone('Asia/Jakarta');
$now = new DateTimeImmutable('now', $timezone);

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT r.reservation_status::text AS reservation_status,
            ts.slot_date::text AS slot_date, ts.start_time::text AS start_time,
            p.payment_id, p.payment_status::text AS payment_status, p.refund_status,
            p.refund_method
        FROM reservation r
        JOIN time_slot ts ON ts.slot_id = r.slot_id
        JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE r.reservation_id = :reservation_id
        FOR UPDATE OF r, p");
    $stmt->execute(['reservation_id' => $reservationId]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        $pdo->rollBack();
        jsonResponse(404, ['success' => false, 'message' => 'Reservasi atau pembayaran tidak ditemukan.']);
    }
    if ($reservation['reservation_status'] !== 'active' || $reservation['payment_status'] !== 'paid') {
        $pdo->rollBack();
        jsonResponse(409, ['success' => false, 'message' => 'Hanya reservasi aktif yang sudah lunas dapat diajukan untuk refund manual.']);
    }
    $appointment = new DateTimeImmutable($reservation['slot_date'] . ' ' . $reservation['start_time'], $timezone);
    if ($appointment <= $now) {
        $pdo->rollBack();
        jsonResponse(409, ['success' => false, 'message' => 'Refund manual dari alur kendala ini hanya tersedia untuk reservasi mendatang.']);
    }
    if ($reservation['refund_status'] !== 'not_requested') {
        $pdo->rollBack();
        jsonResponse(409, ['success' => false, 'message' => 'Refund sudah pernah diajukan atau sedang diproses. Periksa halaman Refund sebelum mencoba lagi.']);
    }

    $refundKey = 'PEMON-ADMIN-MANUAL-' . $reservation['payment_id'] . '-' . bin2hex(random_bytes(8));
    $paymentUpdate = $pdo->prepare("UPDATE payment
        SET refund_status = 'pending',
            refund_method = 'manual',
            refund_key = :refund_key,
            refund_reason = :reason,
            refund_requested_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta',
            refunded_at = NULL,
            updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
        WHERE payment_id = :payment_id
          AND payment_status = 'paid'
          AND refund_status = 'not_requested'");
    $paymentUpdate->execute([
        'refund_key' => $refundKey,
        'reason' => $reason,
        'payment_id' => $reservation['payment_id'],
    ]);
    if ($paymentUpdate->rowCount() !== 1) {
        throw new RuntimeException('Payment changed before the manual refund could be recorded.');
    }

    $reservationUpdate = $pdo->prepare("UPDATE reservation
        SET reservation_status = 'inactive',
            admin_cancelled_at = COALESCE(admin_cancelled_at, clock_timestamp() AT TIME ZONE 'Asia/Jakarta'),
            updated_at = clock_timestamp() AT TIME ZONE 'Asia/Jakarta'
        WHERE reservation_id = :reservation_id AND reservation_status = 'active'");
    $reservationUpdate->execute(['reservation_id' => $reservationId]);
    if ($reservationUpdate->rowCount() !== 1) {
        throw new RuntimeException('Reservation changed before cancellation could be recorded.');
    }

    $pdo->commit();
    jsonResponse(200, [
        'success' => true,
        'message' => 'Reservasi dibatalkan dan refund manual dicatat sebagai menunggu. Transfer dana, lalu tandai selesai di halaman Refund.',
        'refund_status' => 'pending',
        'refund_method' => 'manual',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Admin manual refund request error: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Pembatalan atau pencatatan refund gagal. Periksa log server.']);
}
