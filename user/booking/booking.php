<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/require_customer.php';
require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/config/csrf.php';
require_once __DIR__ . '/../../backend/config/booking.php';

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrfToken = getCsrfToken();
$services = [];
$pageError = '';

try {
    $stmt = $pdo->query("SELECT service_id, service_name, description, price
        FROM service WHERE status = 'active' ORDER BY service_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Booking service list error: ' . $e->getMessage());
    $pageError = 'Daftar layanan gagal dimuat.';
}
$selectedServiceId = (int) ($_POST['service_id'] ?? ($services[0]['service_id'] ?? 0));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $pageError === '') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);
    $slotId = filter_var($_POST['slot_id'] ?? null, FILTER_VALIDATE_INT);
    $date = $_POST['booking_date'] ?? '';
    $timezone = new DateTimeZone('Asia/Jakarta');
    $selectedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone) : false;

    if (!is_string($submittedToken) || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $pageError = 'Sesi formulir berakhir. Muat ulang halaman lalu coba lagi.';
    } elseif (!$serviceId || !$slotId || !is_string($date) || !$selectedDate || $selectedDate->format('Y-m-d') !== $date) {
        $pageError = 'Pilih layanan, tanggal, dan waktu yang tersedia.';
    } elseif ($selectedDate < new DateTimeImmutable('today', $timezone)) {
        $pageError = 'Tanggal yang dipilih sudah lewat.';
    } else {
        try {
            $serviceStmt = $pdo->prepare("SELECT service_id FROM service WHERE service_id = :service_id AND status = 'active'");
            $serviceStmt->execute(['service_id' => $serviceId]);
            $slotStmt = $pdo->prepare("SELECT ts.slot_id, ts.is_available::text AS is_available,
                    ts.slot_date, ts.start_time,
                    COUNT(r.reservation_id)::integer AS booked_count
                FROM time_slot ts
                LEFT JOIN reservation r
                    ON r.slot_id = ts.slot_id
                   AND r.reservation_status = 'active'
                WHERE ts.slot_id = :slot_id
                  AND ts.slot_date = CAST(:slot_date AS date)
                GROUP BY ts.slot_id");
            $slotStmt->execute(['slot_id' => $slotId, 'slot_date' => $date]);
            $slot = $slotStmt->fetch(PDO::FETCH_ASSOC);
            $slotStart = $slot ? new DateTimeImmutable($date . ' ' . $slot['start_time'], $timezone) : null;

            if (!$serviceStmt->fetchColumn()) {
                $pageError = 'Layanan yang dipilih sudah tidak tersedia.';
            } elseif (!$slot || $slot['is_available'] !== 'available' || (int) $slot['booked_count'] >= BOOKING_SLOT_CAPACITY || $slotStart <= new DateTimeImmutable('now', $timezone)) {
                $pageError = 'Slot yang dipilih tidak tersedia lagi. Pilih waktu lain.';
            } else {
                $_SESSION['booking_draft'] = [
                    'service_id' => (int) $serviceId,
                    'slot_id' => (int) $slotId,
                    'date' => $date,
                ];
                header('Location: confirmation.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Booking draft validation error: ' . $e->getMessage());
            $pageError = 'Pilihan reservasi gagal diperiksa. Coba lagi.';
        }
    }
}
?>
<?php
$userPageTitle = 'Booking | Pemon Barbershop';
$userActivePage = 'booking';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';
$userShowProfileEdit = false;
$userPageScripts = ['../../assets/js/booking-availability.js'];
require __DIR__ . '/../includes/header.php';
?>
<main class="max-w-6xl w-full mx-auto my-6 space-y-6">

    <!-- Stepper / Indikator Langkah -->
    <div class="flex items-center gap-2 text-xs md:text-sm text-gray-400 font-medium px-2">
      <span class="text-gray-400"><strong class="text-gray-700">1</strong> Lokasi</span>
      <span>/</span>
      <span class="text-[#0d3b36] font-bold"><strong class="text-[#0d3b36]">2</strong> Layanan & Jadwal</span>
      <span>/</span>
      <span><strong class="text-gray-400">3</strong> Data Diri</span>
      <span>/</span>
      <span><strong class="text-gray-400">4</strong> Selesai</span>
    </div>

    <div class="space-y-4">
        <div class="flex justify-between items-center px-1">
          <h2 class="text-xs font-bold uppercase tracking-wider text-gray-500">Detail Reservasi</h2>
          <span class="text-xs font-bold text-gray-400 tracking-wider">LANGKAH 2/4</span>
        </div>

        <?php if ($pageError !== ''): ?><p role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-xs text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>
        <form id="booking-form" method="post" action="booking.php" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
          <input type="hidden" name="service_id" id="selected-service-id" value="<?= $selectedServiceId ?>">
          <input type="hidden" name="slot_id" id="selected-slot-id" value="">

        <div class="max-w-4xl mx-auto grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
          <?php if ($services === []): ?>
            <p class="col-span-full rounded-xl bg-white p-5 text-sm text-gray-500">Belum ada layanan aktif yang dapat dipesan.</p>
          <?php else: foreach ($services as $service):
              $serviceId = (int) $service['service_id'];
              $isSelected = $serviceId === $selectedServiceId;
          ?>
            <button type="button" aria-pressed="<?= $isSelected ? 'true' : 'false' ?>"
                    data-service-id="<?= $serviceId ?>"
                    class="service-option aspect-square overflow-hidden rounded-2xl bg-white p-4 text-left shadow-sm transition-all duration-200 flex flex-col justify-between <?= $isSelected ? 'border-2 border-[#0d3b36]' : 'border border-gray-200 hover:border-[#0d3b36]' ?>">
              <span>
                <span class="block text-sm font-bold leading-tight text-[#0d3b36]"><?= $escape($service['service_name']) ?></span>
                <span class="mt-2 block text-[11px] text-gray-600"><?= $escape($service['description'] ?: 'Layanan barber') ?></span>
              </span>
              <span>
                <span class="block text-[10px] text-gray-400">Biaya layanan</span>
                <span class="text-sm font-bold text-[#0d3b36]">Rp <?= number_format((float) $service['price'], 0, ',', '.') ?></span>
              </span>
            </button>
          <?php endforeach; endif; ?>
        </div>

        <!-- Tanggal dipilih dari kalender; label tanggal dibuat dari waktu lokal browser. -->
        <div>
          <label class="block text-xs font-bold text-[#12372a] mb-2">Tanggal Reservasi</label>
          <input id="booking-date" name="booking_date" type="date" class="w-full sm:w-auto bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-xl text-sm focus:outline-none focus:border-[#12372a]">
          <p class="text-[11px] text-gray-500 mt-2">Pilih tanggal untuk melihat ketersediaan. Jam operasional 10.00–22.00, dengan interval 30 menit.</p>
        </div>

        <!-- BAGIAN SLOT WAKTU -->
        <div>
          <div class="flex justify-between items-center mb-2">
            <label class="text-xs font-bold text-[#12372a]">Slot Waktu Tersedia</label>
            <span class="text-[11px] text-gray-400">Zona Waktu WIB</span>
          </div>
          <div id="time-group" class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2" aria-live="polite">
            <p id="slot-message" class="col-span-full text-xs text-gray-500">Memuat ketersediaan jadwal…</p>
          </div>
        </div>

        <!-- Tombol Aksi Bawah -->
        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 pt-2">
          <span class="text-xs text-gray-500 flex items-center gap-1">
             Konfirmasi instan tanpa biaya admin
          </span>
          <div class="flex gap-3 w-full sm:w-auto">
            <a href="../dashboard/index.php" class="px-4 py-2 border border-gray-300 rounded-xl text-xs font-semibold text-gray-700 hover:bg-gray-50 text-center">&larr; Kembali</a>
            <button id="continue-booking" type="submit" disabled class="px-5 py-2 bg-gray-400 text-white rounded-xl text-xs font-bold text-center disabled:cursor-not-allowed">Lanjut ke Data Diri &rarr;</button>
          </div>
        </div>

        </form>

    </div>
  </main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
