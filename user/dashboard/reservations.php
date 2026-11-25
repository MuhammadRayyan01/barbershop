<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/require_customer.php';
require_once __DIR__ . '/../../backend/config/db.php';

$timezone = new DateTimeZone('Asia/Jakarta');
$now = new DateTimeImmutable('now', $timezone);
$reservations = [];
$pageError = '';
$query = trim((string) ($_GET['q'] ?? ''));
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

try {
    $where = 'r.user_id = :user_id';
    $params = ['user_id' => $_SESSION['user_id']];
    if ($query !== '') {
        $where .= " AND (
            to_tsvector('simple', COALESCE(s.service_name, '') || ' ' || COALESCE(s.description, '')) @@ websearch_to_tsquery('simple', :service_search)
            OR s.service_name ILIKE :service_partial
            OR CAST(r.reservation_id AS text) = :reservation_id
        )";
        $params += [
            'service_search' => $query,
            'service_partial' => '%' . $query . '%',
            'reservation_id' => $query,
        ];
    }
    $stmt = $pdo->prepare("SELECT
            r.reservation_id,
            r.reservation_status::text AS reservation_status,
            r.admin_cancelled_at,
            s.service_name,
            r.service_price,
            r.additional_service_price,
            ts.slot_date::text AS slot_date,
            TO_CHAR(ts.start_time, 'HH24:MI') AS start_time,
            TO_CHAR(ts.end_time, 'HH24:MI') AS end_time,
            latest_schedule_change.created_at AS schedule_changed_at,
            latest_schedule_change.reason AS schedule_change_reason,
            latest_schedule_change.old_slot_date,
            latest_schedule_change.old_start_time,
            i.total_payment,
            p.payment_status::text AS payment_status,
            p.refund_status,
            p.refund_method
        FROM reservation r
        INNER JOIN service s ON s.service_id = r.service_id
        INNER JOIN time_slot ts ON ts.slot_id = r.slot_id
        LEFT JOIN invoice i ON i.reservation_id = r.reservation_id
        LEFT JOIN payment p ON p.reservation_id = r.reservation_id
        LEFT JOIN LATERAL (
            SELECT change.created_at, change.reason,
                   old_slot.slot_date::text AS old_slot_date,
                   TO_CHAR(old_slot.start_time, 'HH24:MI') AS old_start_time
            FROM reservation_schedule_change change
            JOIN time_slot old_slot ON old_slot.slot_id = change.old_slot_id
            WHERE change.reservation_id = r.reservation_id
            ORDER BY change.created_at DESC, change.schedule_change_id DESC
            LIMIT 1
        ) latest_schedule_change ON TRUE
        WHERE $where
        ORDER BY ts.slot_date DESC, ts.start_time DESC, r.reservation_id DESC");
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Customer reservation history error: ' . $e->getMessage());
    $pageError = 'Riwayat reservasi gagal dimuat. Silakan coba lagi.';
}

$upcoming = [];
$history = [];
foreach ($reservations as $reservation) {
    $appointment = new DateTimeImmutable(
        $reservation['slot_date'] . ' ' . $reservation['start_time'],
        $timezone
    );

    if ($reservation['reservation_status'] === 'active' && $appointment >= $now) {
        $upcoming[] = $reservation;
    } else {
        $history[] = $reservation;
    }
}

usort($upcoming, static fn(array $a, array $b): int =>
    strcmp($a['slot_date'] . ' ' . $a['start_time'], $b['slot_date'] . ' ' . $b['start_time'])
);

$monthNames = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];
$paymentLabels = [
    'paid' => ['Lunas', 'bg-emerald-50 text-emerald-800'],
    'pending' => ['Menunggu pembayaran', 'bg-amber-50 text-amber-800'],
    'verification pending' => ['Menunggu verifikasi', 'bg-amber-50 text-amber-800'],
    'failed' => ['Gagal', 'bg-rose-50 text-rose-700'],
    'refunded' => ['Refund', 'bg-rose-50 text-rose-700'],
];

$userPageTitle = 'Reservasi Saya | Pemon Barbershop';
$userActivePage = 'reservations';
$userAssetPrefix = '../../';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';
require_once __DIR__ . '/../../includes/header.php';
?>
<main class="mx-auto w-full max-w-5xl flex-1 py-6">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Akun Saya</p>
      <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">Reservasi Saya</h1>
      <p class="mt-2 text-sm text-gray-600">Lihat jadwal mendatang dan reservasi yang sudah berlalu.</p>
    </div>
    <a href="../booking/booking.php" class="inline-flex rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Buat reservasi</a>
  </div>

  <form method="get" action="reservations.php" class="mb-6 flex flex-wrap gap-2 rounded-2xl border border-gray-100 bg-white p-3 shadow-sm">
    <label class="sr-only" for="reservation-search">Cari layanan atau kode reservasi</label>
    <input id="reservation-search" name="q" type="search" value="<?= $escape($query) ?>" placeholder="Cari layanan atau kode reservasi..." class="min-w-0 flex-1 rounded-xl bg-[#f8fbf9] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/20">
    <button type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Cari</button>
    <?php if ($query !== ''): ?><a href="reservations.php" class="rounded-xl border border-gray-200 px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Reset</a><?php endif; ?>
  </form>

  <?php if ($pageError !== ''): ?>
    <div role="alert" class="rounded-xl border border-red-100 bg-red-50 p-4 text-sm text-red-700"><?= $escape($pageError) ?></div>
  <?php else: ?>
    <?php foreach ([['Mendatang', $upcoming], ['Riwayat', $history]] as [$sectionTitle, $sectionReservations]): ?>
      <section class="mb-6 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" aria-label="Reservasi <?= $escape(strtolower($sectionTitle)) ?>">
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h2 class="font-bold"><?= $escape($sectionTitle) ?></h2>
          <span class="rounded-full bg-[#e8f3ee] px-3 py-1 text-xs font-semibold text-[#0d3b36]"><?= count($sectionReservations) ?> reservasi</span>
        </div>

        <?php if ($sectionReservations === []): ?>
          <p class="px-5 py-8 text-center text-sm text-gray-500">
            <?= $query !== '' ? 'Tidak ada reservasi yang cocok dengan pencarian.' : ($sectionTitle === 'Mendatang' ? 'Belum ada reservasi mendatang.' : 'Belum ada reservasi yang selesai atau dibatalkan.') ?>
          </p>
        <?php else: ?>
          <div class="divide-y divide-gray-100">
            <?php foreach ($sectionReservations as $reservation):
                $paymentStatus = (string) ($reservation['payment_status'] ?? 'pending');
                [$paymentLabel, $paymentClass] = $paymentLabels[$paymentStatus] ?? ['Belum dibayar', 'bg-gray-100 text-gray-700'];
                $reservationStatus = $reservation['reservation_status'] === 'active'
                    ? ($sectionTitle === 'Mendatang' ? 'Mendatang' : 'Selesai')
                    : 'Dibatalkan';
                $reservationClass = $reservationStatus === 'Dibatalkan'
                    ? 'bg-rose-50 text-rose-700'
                    : ($reservationStatus === 'Selesai' ? 'bg-gray-100 text-gray-700' : 'bg-emerald-50 text-emerald-800');
                $date = new DateTimeImmutable($reservation['slot_date'], $timezone);
                $dateLabel = $date->format('d') . ' ' . $monthNames[(int) $date->format('n')] . ' ' . $date->format('Y');
                $oldScheduleLabel = '';
                if (!empty($reservation['old_slot_date']) && !empty($reservation['old_start_time'])) {
                    $oldDate = new DateTimeImmutable($reservation['old_slot_date'], $timezone);
                    $oldScheduleLabel = $oldDate->format('d') . ' ' . $monthNames[(int) $oldDate->format('n')] . ' ' . $oldDate->format('Y') . ' pukul ' . $reservation['old_start_time'];
                }
                $total = $reservation['total_payment']
                    ?? ((float) $reservation['service_price'] + (float) ($reservation['additional_service_price'] ?? 0));
            ?>
              <article class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-bold"><?= $escape($reservation['service_name']) ?></h3>
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold <?= $reservationClass ?>"><?= $escape($reservationStatus) ?></span>
                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold <?= $paymentClass ?>"><?= $escape($paymentLabel) ?></span>
                  </div>
                  <p class="mt-2 text-sm text-gray-600"><?= $escape($dateLabel) ?> · <?= $escape($reservation['start_time']) ?>–<?= $escape($reservation['end_time']) ?> WIB</p>
                  <?php if ($oldScheduleLabel !== ''): ?>
                    <p class="mt-2 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs text-amber-900">Jadwal diubah oleh barbershop dari <?= $escape($oldScheduleLabel) ?>. Silakan gunakan jadwal terbaru di atas.<?= !empty($reservation['schedule_change_reason']) ? ' Alasan: ' . $escape($reservation['schedule_change_reason']) . '.' : '' ?></p>
                  <?php endif; ?>
                  <?php if (!empty($reservation['admin_cancelled_at'])): ?>
                    <p class="mt-2 rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                      <?php if ($paymentStatus === 'refunded' || $reservation['refund_status'] === 'completed'): ?>Refund selesai; dana telah dikembalikan.<?php elseif ($reservation['refund_status'] === 'pending' && $reservation['refund_method'] === 'manual'): ?>Reservasi dibatalkan oleh barbershop. Refund manual sedang diproses; barbershop akan mengembalikan dana.<?php elseif ($reservation['refund_status'] === 'pending'): ?>Reservasi dibatalkan oleh barbershop. Refund sedang diproses melalui penyedia pembayaran.<?php else: ?>Reservasi ini dibatalkan oleh barbershop.<?php endif; ?>
                    </p>
                  <?php endif; ?>
                  <p class="mt-1 text-xs text-gray-500">Kode booking: PMN-<?= (int) $reservation['reservation_id'] ?></p>
                </div>
                <div class="flex items-center justify-between gap-4 sm:justify-end">
                  <p class="text-sm font-bold">Rp <?= number_format((float) $total, 0, ',', '.') ?></p>
                  <a href="../booking/success.php?reservation_id=<?= (int) $reservation['reservation_id'] ?>" class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">Detail</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
