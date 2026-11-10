<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/require_customer.php';
require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/config/csrf.php';

$draft = $_SESSION['booking_draft'] ?? null;
if (!is_array($draft) || !isset($draft['service_id'], $draft['slot_id'], $draft['date'])) {
    header('Location: booking.php');
    exit;
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$csrfToken = getCsrfToken();
$reservationPreview = null;
$pageError = '';

try {
    $stmt = $pdo->prepare("SELECT s.service_name, s.price, ts.slot_date, ts.start_time, ts.end_time,
            u.full_name, u.phone_number
        FROM service s
        JOIN time_slot ts ON ts.slot_id = :slot_id
        JOIN users u ON u.user_id = :user_id
        WHERE s.service_id = :service_id AND s.status = 'active'");
    $stmt->execute([
        'slot_id' => (int) $draft['slot_id'],
        'user_id' => $_SESSION['user_id'],
        'service_id' => (int) $draft['service_id'],
    ]);
    $reservationPreview = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$reservationPreview || $reservationPreview['slot_date'] !== $draft['date']) {
        unset($_SESSION['booking_draft']);
        header('Location: booking.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Booking confirmation preview error: ' . $e->getMessage());
    $pageError = 'Ringkasan reservasi gagal dimuat. Coba kembali ke halaman booking.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= $escape($csrfToken) ?>">
  <title>Konfirmasi Reservasi | Pemon Barbershop</title>
  <link rel="stylesheet" href="../../assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6">
  <main class="mx-auto max-w-3xl py-8">
    <p class="text-center text-xs font-semibold uppercase tracking-widest text-gray-500">Langkah 3 dari 4</p>
    <h1 class="mt-2 text-center text-2xl font-extrabold">Konfirmasi Reservasi</h1>
    <p class="mt-2 text-center text-sm text-gray-600">Periksa layanan dan jadwal sebelum membuat reservasi.</p>

    <?php if ($pageError !== ''): ?>
      <p role="alert" class="mt-6 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p>
    <?php elseif ($reservationPreview): ?>
      <section class="mt-7 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm sm:p-7">
        <dl class="grid gap-5 sm:grid-cols-2">
          <div><dt class="text-xs text-gray-500">Layanan</dt><dd class="mt-1 font-bold"><?= $escape($reservationPreview['service_name']) ?></dd></div>
          <div><dt class="text-xs text-gray-500">Pelanggan</dt><dd class="mt-1 font-semibold"><?= $escape($reservationPreview['full_name']) ?></dd></div>
          <div><dt class="text-xs text-gray-500">Nomor telepon</dt><dd class="mt-1 font-semibold"><?= $escape($reservationPreview['phone_number']) ?></dd></div>
          <div><dt class="text-xs text-gray-500">Tanggal</dt><dd class="mt-1 font-semibold"><?= $escape((new DateTimeImmutable((string) $reservationPreview['slot_date']))->format('d/m/Y')) ?></dd></div>
          <div><dt class="text-xs text-gray-500">Waktu</dt><dd class="mt-1 font-semibold"><?= $escape(substr((string) $reservationPreview['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $reservationPreview['end_time'], 0, 5)) ?> WIB</dd></div>
          <div><dt class="text-xs text-gray-500">Total layanan</dt><dd class="mt-1 font-bold">Rp <?= number_format((float) $reservationPreview['price'], 0, ',', '.') ?></dd></div>
        </dl>
        <p class="mt-6 rounded-xl bg-[#f4f7f5] p-4 text-xs leading-relaxed text-gray-600">Reservasi akan diperiksa kembali terhadap ketersediaan slot saat dikonfirmasi. Jika slot sudah penuh, kamu akan diminta memilih waktu lain.</p>

        <form id="confirmation-form" class="mt-6 flex flex-col-reverse justify-between gap-3 sm:flex-row">
          <input type="hidden" id="confirm-service-id" value="<?= (int) $draft['service_id'] ?>">
          <input type="hidden" id="confirm-slot-id" value="<?= (int) $draft['slot_id'] ?>">
          <a href="booking.php" class="rounded-xl border border-gray-300 px-5 py-3 text-center text-sm font-semibold text-gray-700 hover:bg-gray-50">Kembali ubah jadwal</a>
          <button id="confirm-submit" type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-bold text-white hover:bg-[#082724]">Konfirmasi &amp; Lanjut ke Pembayaran</button>
        </form>
        <p id="confirmation-message" class="mt-4 hidden rounded-xl px-4 py-3 text-sm" role="status" aria-live="polite"></p>
      </section>
    <?php endif; ?>
  </main>
  <script src="../../assets/js/confirmation.js" defer></script>
</body>
</html>
