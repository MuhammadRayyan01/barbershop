<?php
// backend/api/submit_booking.php
declare(strict_types=1);

require_once __DIR__ . '/../middleware/require_customer.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/booking.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan.']);
    exit;
}

$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
$serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);
$slotId = filter_var($_POST['slot_id'] ?? null, FILTER_VALIDATE_INT);
$date = $_POST['booking_date'] ?? '';
$timezone = new DateTimeZone('Asia/Jakarta');
$selectedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) : false;

if (!is_string($submittedToken) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    echo json_encode(['status' => 'error', 'message' => 'Sesi formulir berakhir. Muat ulang halaman lalu coba lagi.']);
    exit;
} elseif (!$serviceId || !$slotId || !is_string($date) || !$selectedDate || $selectedDate->format('Y-m-d') !== $date) {
    echo json_encode(['status' => 'error', 'message' => 'Pilih layanan, tanggal, dan waktu yang tersedia.']);
    exit;
} elseif ($selectedDate < new DateTimeImmutable('today', $timezone)) {
    echo json_encode(['status' => 'error', 'message' => 'Tanggal yang dipilih sudah lewat.']);
    exit;
}

try {
    $serviceStmt = $pdo->prepare("SELECT service_id FROM service WHERE service_id = :service_id AND status = 'active'");
    $serviceStmt->execute(['service_id' => $serviceId]);
    
    $slotStmt = $pdo->prepare("SELECT ts.slot_id, ts.is_available::text AS is_available, ts.slot_date, ts.start_time, COUNT(r.reservation_id)::integer AS booked_count 
                               FROM time_slot ts LEFT JOIN reservation r ON r.slot_id = ts.slot_id AND r.reservation_status = 'active' 
                               WHERE ts.slot_id = :slot_id AND ts.slot_date = CAST(:slot_date AS date) GROUP BY ts.slot_id");
    $slotStmt->execute(['slot_id' => $slotId, 'slot_date' => $date]);
    $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);
    $slotStart = $slot ? new DateTimeImmutable($date . ' ' . $slot['start_time'], $timezone) : null;

    if (!$serviceStmt->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'Layanan yang dipilih sudah tidak tersedia.']);
    } elseif (!$slot || $slot['is_available'] !== 'available' || (int) $slot['booked_count'] >= BOOKING_SLOT_CAPACITY || $slotStart <= new DateTimeImmutable('now', $timezone)) {
        echo json_encode(['status' => 'error', 'message' => 'Slot yang dipilih tidak tersedia lagi. Pilih waktu lain.']);
    } else {
        // Berhasil! Simpan draft ke session dan beritahu frontend untuk redirect
        $_SESSION['booking_draft'] = [
            'service_id' => (int) $serviceId,
            'slot_id' => (int) $slotId,
            'date' => $date,
        ];
        echo json_encode(['status' => 'success', 'redirect_url' => 'confirmation.php']);
    }
} catch (PDOException $e) {
    error_log('Booking draft validation error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Pilihan reservasi gagal diperiksa. Coba lagi.']);
}