<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/require_customer.php';
require_once __DIR__ . '/../../backend/config/db.php';

$reservationId = filter_input(INPUT_GET, 'reservation_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reservation = null;
$pageError = '';
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

if (!$reservationId) {
    http_response_code(400);
    $pageError = 'ID reservasi tidak valid.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT r.reservation_id, r.reservation_status::text AS reservation_status,
                r.service_price, r.additional_service_price, u.full_name, u.phone_number,
                s.service_name, ts.slot_date, ts.start_time, ts.end_time,
                i.invoice_id, i.total_payment, i.created_at AS invoice_created_at,
                p.payment_status::text AS payment_status, p.payment_type, p.transaction_reference
            FROM reservation r
            JOIN users u ON u.user_id = r.user_id
            JOIN service s ON s.service_id = r.service_id
            JOIN time_slot ts ON ts.slot_id = r.slot_id
            JOIN invoice i ON i.reservation_id = r.reservation_id
            LEFT JOIN payment p ON p.reservation_id = r.reservation_id
            WHERE r.reservation_id = :reservation_id AND r.user_id = :user_id");
        $stmt->execute([
            'reservation_id' => $reservationId,
            'user_id' => $_SESSION['user_id'],
        ]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reservation) {
            http_response_code(404);
            $pageError = 'Nota reservasi tidak ditemukan.';
        }
    } catch (PDOException $e) {
        error_log('Invoice page error: ' . $e->getMessage());
        http_response_code(500);
        $pageError = 'Nota gagal dimuat.';
    }
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
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$date = $reservation ? new DateTimeImmutable((string) $reservation['slot_date'], new DateTimeZone('Asia/Jakarta')) : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nota Reservasi | Pemon Barbershop</title>
  <link rel="stylesheet" href="../../assets/css/tailwind.css">
  <style>
    @media print {
      body { background: white !important; padding: 0 !important; }
      .no-print { display: none !important; }
      .receipt { box-shadow: none !important; border: 0 !important; margin: 0 auto !important; }
    }
  </style>
</head>
<body class="min-h-screen bg-gray-100 p-4 text-gray-900 sm:py-8">
  <main class="receipt mx-auto max-w-xl rounded-2xl border border-gray-100 bg-white p-6 shadow-sm sm:p-8">
    <?php if ($pageError !== ''): ?>
      <section role="alert" class="text-center">
        <h1 class="text-xl font-bold text-red-700">Nota tidak tersedia</h1>
        <p class="mt-2 text-sm text-gray-600"><?= $escape($pageError) ?></p>
      </section>
    <?php else: ?>
      <div class="text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-[#0d3b36] text-xl font-bold text-white">P</span>
        <h1 class="mt-3 text-xl font-extrabold">Pemon Barbershop</h1>
        <p class="mt-1 text-xs text-gray-500">Jl. Senopati No. 42, Kebayoran Baru, Jakarta Selatan</p>
        <p class="mt-5 text-2xl font-extrabold">Rp <?= number_format((float) $reservation['total_payment'], 0, ',', '.') ?></p>
        <span class="mt-2 inline-flex rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">Nota reservasi</span>
      </div>

      <div class="my-6 border-t border-dashed border-gray-300"></div>

      <dl class="space-y-4 text-sm">
        <div class="flex justify-between gap-4"><dt class="text-gray-500">No. nota</dt><dd class="text-right font-medium">INV-<?= str_pad((string) $reservation['invoice_id'], 6, '0', STR_PAD_LEFT) ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-gray-500">Kode booking</dt><dd class="text-right font-medium">PMN-<?= (int) $reservation['reservation_id'] ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-gray-500">Tanggal reservasi</dt><dd class="text-right font-medium"><?= $escape($date->format('d') . ' ' . $monthNames[(int) $date->format('n')] . ' ' . $date->format('Y')) ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-gray-500">Waktu</dt><dd class="text-right font-medium"><?= $escape(substr((string) $reservation['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $reservation['end_time'], 0, 5)) ?> WIB</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-gray-500">Nama pemesan</dt><dd class="text-right font-medium"><?= $escape($reservation['full_name']) ?></dd></div>
        <div class="flex justify-between gap-4"><dt class="text-gray-500">No. telepon</dt><dd class="text-right font-medium"><?= $escape($reservation['phone_number']) ?></dd></div>
      </dl>

      <div class="my-6 border-t border-dashed border-gray-300"></div>

      <div class="space-y-3 text-sm">
        <div class="flex justify-between gap-4"><span class="text-gray-500">Layanan</span><span class="text-right font-medium"><?= $escape($reservation['service_name']) ?></span></div>
        <?php if ($reservation['additional_service_price'] !== null && (float) $reservation['additional_service_price'] > 0): ?>
          <div class="flex justify-between gap-4"><span class="text-gray-500">Layanan tambahan</span><span class="font-medium">Rp <?= number_format((float) $reservation['additional_service_price'], 0, ',', '.') ?></span></div>
        <?php endif; ?>
        <div class="flex justify-between gap-4 border-t border-gray-200 pt-3 font-bold"><span>Total</span><span>Rp <?= number_format((float) $reservation['total_payment'], 0, ',', '.') ?></span></div>
        <div class="flex justify-between gap-4"><span class="text-gray-500">Status pembayaran</span><span class="text-right font-semibold"><?= $escape($paymentLabel) ?></span></div>
        <div class="flex justify-between gap-4"><span class="text-gray-500">Metode</span><span class="text-right font-medium"><?= $escape($reservation['payment_type'] === 'midtrans_snap' ? 'Midtrans' : ($reservation['payment_type'] ?? 'Belum dipilih')) ?></span></div>
      </div>

      <p class="mt-6 text-center text-xs text-gray-500">Terima kasih telah memilih Pemon Barbershop.</p>
    <?php endif; ?>

    <div class="no-print mt-6 flex justify-center gap-3">
      <a href="success.php?reservation_id=<?= (int) ($reservation['reservation_id'] ?? $reservationId ?? 0) ?>" class="rounded-xl border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-700">Kembali</a>
      <?php if ($reservation): ?><button type="button" onclick="window.print()" class="rounded-xl bg-[#0d3b36] px-4 py-2.5 text-sm font-semibold text-white">Cetak nota</button><?php endif; ?>
    </div>
  </main>
</body>
</html>
