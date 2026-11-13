<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/require_customer.php';
require_once __DIR__ . '/../../backend/config/db.php';

$reservationId = filter_input(INPUT_GET, 'reservation_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$orderId = $_GET['order_id'] ?? '';
$reservation = null;
$pageError = '';
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

try {
    if ($reservationId) {
        $lookup = $pdo->prepare("SELECT r.reservation_id, r.reservation_status::text AS reservation_status,
                s.service_name, ts.slot_date, ts.start_time, ts.end_time,
                u.full_name, u.phone_number, i.invoice_id, i.total_payment,
                p.payment_status::text AS payment_status, p.payment_type, p.transaction_reference
            FROM reservation r
            JOIN users u ON u.user_id = r.user_id
            JOIN service s ON s.service_id = r.service_id
            JOIN time_slot ts ON ts.slot_id = r.slot_id
            JOIN invoice i ON i.reservation_id = r.reservation_id
            LEFT JOIN payment p ON p.reservation_id = r.reservation_id
            WHERE r.reservation_id = :reservation_id AND r.user_id = :user_id");
        $lookup->execute(['reservation_id' => $reservationId, 'user_id' => $_SESSION['user_id']]);
    } elseif (is_string($orderId) && preg_match('/^[A-Za-z0-9_-]{1,255}$/', $orderId)) {
        $lookup = $pdo->prepare("SELECT r.reservation_id, r.reservation_status::text AS reservation_status,
                s.service_name, ts.slot_date, ts.start_time, ts.end_time,
                u.full_name, u.phone_number, i.invoice_id, i.total_payment,
                p.payment_status::text AS payment_status, p.payment_type, p.transaction_reference
            FROM reservation r
            JOIN users u ON u.user_id = r.user_id
            JOIN service s ON s.service_id = r.service_id
            JOIN time_slot ts ON ts.slot_id = r.slot_id
            JOIN invoice i ON i.reservation_id = r.reservation_id
            JOIN payment p ON p.reservation_id = r.reservation_id
            WHERE p.transaction_reference = :order_id AND r.user_id = :user_id");
        $lookup->execute(['order_id' => $orderId, 'user_id' => $_SESSION['user_id']]);
    } else {
        http_response_code(400);
        $pageError = 'ID reservasi tidak ditemukan. Buka halaman ini dari tautan konfirmasi pembayaran.';
    }

    if (isset($lookup)) {
        $reservation = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$reservation) {
            http_response_code(404);
            $pageError = 'Data reservasi tidak ditemukan.';
        }
    }
} catch (PDOException $e) {
    error_log('Reservation success page error: ' . $e->getMessage());
    http_response_code(500);
    $pageError = 'Data reservasi gagal dimuat.';
}

$paymentStatus = (string) ($reservation['payment_status'] ?? 'pending');
$paymentLabels = [
    'paid' => 'Lunas',
    'pending' => 'Menunggu pembayaran',
    'verification pending' => 'Menunggu verifikasi',
    'failed' => 'Gagal',
    'refunded' => 'Dikembalikan',
];
$paymentLabel = $paymentLabels[$paymentStatus] ?? 'Belum ada pembayaran';
$statusClass = $paymentStatus === 'paid'
    ? 'bg-emerald-100 text-emerald-800'
    : ($paymentStatus === 'failed' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800');
$timezone = new DateTimeZone('Asia/Jakarta');
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$date = $reservation ? new DateTimeImmutable((string) $reservation['slot_date'], $timezone) : null;
$dateLabel = $date ? $date->format('d') . ' ' . $monthNames[(int) $date->format('n')] . ' ' . $date->format('Y') : '';

$userPageTitle = 'Reservasi Berhasil | Pemon Barbershop';
$userActivePage = '';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';
$userShowProfileEdit = false;
$userPageScripts = [];
require __DIR__ . '/../includes/header.php';
?>
<main class="mx-auto w-full max-w-3xl flex-1 py-8">
  <?php if ($pageError !== ''): ?>
    <section role="alert" class="rounded-2xl border border-red-100 bg-white p-6 text-center shadow-sm">
      <h1 class="text-xl font-bold text-red-700">Reservasi belum dapat ditampilkan</h1>
      <p class="mt-2 text-sm text-gray-600"><?= $escape($pageError) ?></p>
      <a href="booking.php" class="mt-5 inline-flex rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white">Kembali ke booking</a>
    </section>
  <?php else: ?>
    <div class="mb-6 text-center">
      <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-[#0d3b36] text-xl font-bold text-white">✓</span>
      <h1 class="mt-4 text-2xl font-extrabold">Reservasi berhasil dibuat</h1>
      <p class="mt-2 text-sm text-gray-600">Simpan kode booking ini untuk kunjunganmu.</p>
    </div>

    <section class="space-y-6 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm sm:p-8">
      <div class="flex flex-wrap items-start justify-between gap-4 border-b border-gray-100 pb-5">
        <div>
          <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Pemon Studio Senopati</p>
          <p class="mt-1 text-sm text-gray-600">Jl. Senopati No. 42, Kebayoran Baru, Jakarta Selatan</p>
        </div>
        <div class="text-left sm:text-right">
          <p class="text-xs text-gray-500">Kode booking</p>
          <p class="font-bold">PMN-<?= (int) $reservation['reservation_id'] ?></p>
        </div>
      </div>

      <dl class="grid gap-5 text-sm sm:grid-cols-2">
        <div><dt class="text-xs text-gray-500">Layanan</dt><dd class="mt-1 font-semibold"><?= $escape($reservation['service_name']) ?></dd></div>
        <div><dt class="text-xs text-gray-500">Nama pemesan</dt><dd class="mt-1 font-semibold"><?= $escape($reservation['full_name']) ?></dd></div>
        <div><dt class="text-xs text-gray-500">Tanggal</dt><dd class="mt-1 font-semibold"><?= $escape($dateLabel) ?></dd></div>
        <div><dt class="text-xs text-gray-500">Waktu</dt><dd class="mt-1 font-semibold"><?= $escape(substr((string) $reservation['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $reservation['end_time'], 0, 5)) ?> WIB</dd></div>
        <div><dt class="text-xs text-gray-500">Nomor telepon</dt><dd class="mt-1 font-semibold"><?= $escape($reservation['phone_number']) ?></dd></div>
        <div><dt class="text-xs text-gray-500">Total</dt><dd class="mt-1 font-semibold">Rp <?= number_format((float) $reservation['total_payment'], 0, ',', '.') ?></dd></div>
      </dl>

      <div class="rounded-xl bg-[#f8f8fd] p-4">
        <p class="text-xs text-gray-500">Status pembayaran</p>
        <span class="mt-2 inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass ?>"><?= $escape($paymentLabel) ?></span>
        <?php if ($paymentStatus !== 'paid'): ?>
          <p class="mt-2 text-xs text-gray-600">Status mengikuti pembaruan pembayaran dari Midtrans.</p>
        <?php endif; ?>
      </div>

      <div class="flex flex-col gap-3 sm:flex-row">
        <a href="invoice.php?reservation_id=<?= (int) $reservation['reservation_id'] ?>" target="_blank" rel="noopener" class="inline-flex flex-1 justify-center rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-bold text-white hover:bg-[#082724]">Cetak tiket / nota</a>
        <a href="../dashboard/index.php" class="inline-flex flex-1 justify-center rounded-xl border border-gray-200 px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Kembali ke dashboard</a>
      </div>
    </section>
  <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
