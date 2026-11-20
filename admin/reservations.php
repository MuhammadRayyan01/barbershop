<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';

$timezone = new DateTimeZone('Asia/Jakarta');
$today = new DateTimeImmutable('today', $timezone);
$date = $_GET['date'] ?? $today->format('Y-m-d');
if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today->format('Y-m-d');
} else {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) $date = $today->format('Y-m-d');
}
$query = trim((string) ($_GET['q'] ?? ''));
$pageError = '';
$reservations = [];
$dailyRevenue = [];
$revenueTotal = 0.0;
$selectedDayRevenue = 0.0;
$selectedDayCount = 0;

try {
    // Total income is the sum of payments that Midtrans/admin has confirmed as paid.
    $revenueTotal = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payment WHERE payment_status = 'paid'")->fetchColumn();

    // The selected date chooses the month displayed by the income chart.
    $chartStmt = $pdo->prepare("SELECT ts.slot_date, COALESCE(SUM(p.amount), 0) AS revenue
        FROM time_slot ts
        JOIN reservation r ON r.slot_id = ts.slot_id
        JOIN payment p ON p.reservation_id = r.reservation_id AND p.payment_status = 'paid'
        WHERE ts.slot_date >= date_trunc('month', CAST(:date AS date))::date
          AND ts.slot_date < (date_trunc('month', CAST(:date AS date)) + INTERVAL '1 month')::date
        GROUP BY ts.slot_date ORDER BY ts.slot_date");
    $chartStmt->execute(['date' => $date]);
    foreach ($chartStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dailyRevenue[$row['slot_date']] = (float) $row['revenue'];
    }

    $where = ['ts.slot_date = CAST(:date AS date)'];
    $params = ['date' => $date];
    if ($query !== '') {
        $where[] = '(u.full_name ILIKE :name_query OR u.phone_number ILIKE :phone_query OR s.service_name ILIKE :service_query OR CAST(r.reservation_id AS text) = :id_query)';
        $params += [
            'name_query' => '%' . $query . '%',
            'phone_query' => '%' . $query . '%',
            'service_query' => '%' . $query . '%',
            'id_query' => $query,
        ];
    }
    $stmt = $pdo->prepare("SELECT r.reservation_id, r.reservation_status, u.full_name,
            u.phone_number, s.service_name, ts.slot_date, ts.start_time, ts.end_time,
            COALESCE(i.total_payment, r.service_price, 0) AS total_payment,
            p.payment_status, p.payment_type
        FROM reservation r
        JOIN users u ON u.user_id = r.user_id
        JOIN service s ON s.service_id = r.service_id
        JOIN time_slot ts ON ts.slot_id = r.slot_id
        LEFT JOIN invoice i ON i.reservation_id = r.reservation_id
        LEFT JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE " . implode(' AND ', $where) . '
        ORDER BY ts.start_time, r.reservation_id');
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reservations as $reservation) {
        if ($reservation['payment_status'] === 'paid') {
            $selectedDayRevenue += (float) $reservation['total_payment'];
        }
    }
    $selectedDayCount = count($reservations);
} catch (PDOException $e) {
    error_log('Admin revenue and reservation page error: ' . $e->getMessage());
    $pageError = 'Data pemasukan dan reservasi gagal dimuat.';
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$monthStart = new DateTimeImmutable(substr($date, 0, 7) . '-01', $timezone);
$selectedDateLabel = substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
$daysInMonth = (int) $monthStart->format('t');
$monthLabel = $monthStart->format('F Y');
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$monthLabel = $monthNames[(int) $monthStart->format('n')] . ' ' . $monthStart->format('Y');
$maxRevenue = max($dailyRevenue ?: [0]);
$adminPageTitle = 'Pemasukan & Reservasi | Pemon Admin';
$adminPageScript = null;
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<main class="lg:ml-72 px-4 py-8 sm:px-8">
  <div class="flex flex-wrap items-start justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Laporan</p>
      <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">Pemasukan &amp; Reservasi</h1>
      <p class="mt-1 text-sm text-slate-500">Total pemasukan terkonfirmasi dan reservasi pada tanggal yang dipilih.</p>
    </div>
    <a href="dashboard.php?date=<?= $escape($date) ?>" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Kembali ke Dashboard</a>
  </div>

  <?php if ($pageError !== ''): ?><p role="alert" class="mt-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>

  <section class="mt-6 grid gap-4 sm:grid-cols-2">
    <div class="rounded-2xl bg-[#0d3b36] p-5 text-white shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-emerald-100">TOTAL PEMASUKAN SEPANJANG WAKTU</p>
      <p class="mt-2 text-3xl font-extrabold">Rp <?= number_format($revenueTotal, 0, ',', '.') ?></p>
      <p class="mt-2 text-xs text-emerald-100">Berdasarkan pembayaran berstatus lunas.</p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-slate-500">TANGGAL DIPILIH</p>
      <p class="mt-2 text-3xl font-extrabold text-slate-900"><?= $selectedDayCount ?> reservasi</p>
      <p class="mt-2 text-sm text-slate-500">Pemasukan lunas pada tanggal ini: <strong class="text-slate-800">Rp <?= number_format($selectedDayRevenue, 0, ',', '.') ?></strong></p>
    </div>
  </section>

  <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm sm:p-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h2 class="text-lg font-bold">Grafik pemasukan harian</h2>
        <p class="mt-1 text-sm text-slate-500">Pembayaran lunas per hari · <?= $escape($monthLabel) ?></p>
      </div>
      <form method="get" action="reservations.php" class="flex flex-wrap items-end gap-3">
        <?php if ($query !== ''): ?><input type="hidden" name="q" value="<?= $escape($query) ?>"><?php endif; ?>
        <label class="text-xs font-semibold text-slate-500" for="date-filter">Pilih tanggal reservasi
          <input id="date-filter" name="date" type="date" value="<?= $escape($date) ?>" onchange="this.form.requestSubmit()" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
        </label>
        <button class="rounded-xl bg-[#0d3b36] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#082724]">Tampilkan</button>
      </form>
    </div>

    <div class="mt-6 overflow-x-auto pb-2" role="img" aria-label="Grafik batang pemasukan harian untuk <?= $escape($monthLabel) ?>">
      <div class="flex h-56 min-w-[720px] items-end gap-1 border-b border-slate-200 px-1">
        <?php for ($day = 1; $day <= $daysInMonth; $day++):
          $dayKey = $monthStart->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
          $amount = $dailyRevenue[$dayKey] ?? 0;
          $height = $maxRevenue > 0 ? max(3, (int) round(($amount / $maxRevenue) * 100)) : 3;
        ?>
          <div class="flex h-full min-w-4 flex-1 flex-col items-center justify-end" title="<?= $day ?>: Rp <?= number_format($amount, 0, ',', '.') ?>">
            <span class="mb-1 hidden text-[9px] text-slate-500 sm:block"><?= $amount > 0 ? number_format($amount / 1000, 0, ',', '.') . 'k' : '' ?></span>
            <div class="w-full max-w-6 rounded-t-md <?= $dayKey === $date ? 'bg-emerald-700' : 'bg-emerald-200 hover:bg-emerald-400' ?>" style="height: <?= $height ?>%"></div>
            <span class="mt-2 text-[10px] text-slate-500"><?= $day ?></span>
          </div>
        <?php endfor; ?>
      </div>
      <p class="mt-2 text-right text-xs text-slate-400">Nilai label dalam ribuan rupiah</p>
    </div>
  </section>

  <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
      <div>
        <h2 class="text-lg font-bold">Semua reservasi · <?= $escape($selectedDateLabel) ?></h2>
        <p class="mt-1 text-sm text-slate-500">Termasuk reservasi lunas, menunggu pembayaran, dan dibatalkan.</p>
      </div>
      <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800"><?= count($reservations) ?> reservasi</span>
    </div>
    <form method="get" action="reservations.php" class="flex flex-wrap gap-3 border-b border-slate-100 p-4">
      <input type="hidden" name="date" value="<?= $escape($date) ?>">
      <label class="sr-only" for="reservation-search">Cari reservasi</label>
      <input id="reservation-search" name="q" type="search" value="<?= $escape($query) ?>" placeholder="Cari nama, telepon, layanan, atau ID..." class="min-w-[220px] flex-1 rounded-xl bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/20">
      <button type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Cari</button>
      <?php if ($query !== ''): ?><a href="reservations.php?date=<?= $escape(rawurlencode($date)) ?>" class="rounded-xl bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-600">Hapus pencarian</a><?php endif; ?>
    </form>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[900px] text-sm">
        <thead class="bg-indigo-50/60 text-[11px] uppercase tracking-wider text-slate-500"><tr>
          <th class="px-4 py-3 text-left font-semibold">Pelanggan</th><th class="px-4 py-3 text-left font-semibold">Telepon</th><th class="px-4 py-3 text-left font-semibold">Layanan</th><th class="px-4 py-3 text-left font-semibold">Waktu</th><th class="px-4 py-3 text-left font-semibold">Status pembayaran</th><th class="px-4 py-3 text-right font-semibold">Total</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($reservations === []): ?>
            <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500"><?= $query === '' ? 'Belum ada reservasi pada tanggal ini.' : 'Reservasi yang cocok tidak ditemukan pada tanggal ini.' ?></td></tr>
          <?php else: foreach ($reservations as $reservation):
            $cancelled = $reservation['reservation_status'] === 'inactive';
            $paymentStatus = $reservation['payment_status'] ?? 'pending';
            $paymentLabel = $paymentStatus === 'paid' ? 'Lunas' : ($paymentStatus === 'refunded' ? 'Dikembalikan' : ($paymentStatus === 'failed' ? 'Gagal' : 'Menunggu'));
            $paymentClass = $paymentStatus === 'paid' ? 'bg-emerald-50 text-emerald-800' : ($paymentStatus === 'refunded' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600');
          ?>
            <tr class="<?= $cancelled ? 'bg-red-50/40' : 'hover:bg-slate-50' ?>">
              <td class="px-4 py-4"><p class="font-semibold text-slate-900"><?= $escape($reservation['full_name']) ?></p><p class="text-xs text-slate-500">Reservasi #<?= (int) $reservation['reservation_id'] ?><?= $cancelled ? ' · Dibatalkan' : '' ?></p></td>
              <td class="px-4 py-4 font-mono text-xs text-slate-600"><?= $escape($reservation['phone_number']) ?></td>
              <td class="px-4 py-4 font-medium"><?= $escape($reservation['service_name']) ?></td>
              <td class="px-4 py-4"><p><?= $escape(substr((string) $reservation['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $reservation['end_time'], 0, 5)) ?></p><p class="text-xs text-slate-500">WIB</p></td>
              <td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-semibold <?= $paymentClass ?>"><?= $escape($paymentLabel) ?></span><?php if (!empty($reservation['payment_type'])): ?><p class="mt-1 text-xs text-slate-500"><?= $escape($reservation['payment_type']) ?></p><?php endif; ?></td>
              <td class="px-4 py-4 text-right font-semibold">Rp <?= number_format((float) $reservation['total_payment'], 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
