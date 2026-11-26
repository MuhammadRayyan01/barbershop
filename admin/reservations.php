<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$timezone = new DateTimeZone('Asia/Jakarta');
$today = new DateTimeImmutable('today', $timezone);
$now = new DateTimeImmutable('now', $timezone);
$csrfToken = getCsrfToken();
$range = $_GET['range'] ?? 'day';
if (!in_array($range, ['day', 'all'], true)) $range = 'day';
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
$chartRevenue = [];
$revenueTotal = 0.0;
$selectedDayRevenue = 0.0;
$selectedDayCount = 0;

try {
    // Total income is the sum of payments that Midtrans/admin has confirmed as paid.
    $revenueTotal = (float) $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payment WHERE payment_status = 'paid'")->fetchColumn();

    if ($range === 'all') {
        $chartStmt = $pdo->query("SELECT date_trunc('month', ts.slot_date)::date AS period,
                SUM(p.amount) AS revenue
            FROM time_slot ts
            JOIN reservation r ON r.slot_id = ts.slot_id
            JOIN payment p ON p.reservation_id = r.reservation_id
            WHERE p.payment_status = 'paid'
            GROUP BY date_trunc('month', ts.slot_date)::date
            ORDER BY period");
        foreach ($chartStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chartRevenue[$row['period']] = (float) $row['revenue'];
        }
    } else {
        $chartStmt = $pdo->prepare("SELECT ts.slot_date AS period, SUM(p.amount) AS revenue
            FROM time_slot ts
            JOIN reservation r ON r.slot_id = ts.slot_id
            JOIN payment p ON p.reservation_id = r.reservation_id
            WHERE p.payment_status = 'paid'
              AND ts.slot_date >= date_trunc('month', CAST(:date AS date))::date
              AND ts.slot_date < (date_trunc('month', CAST(:date AS date)) + INTERVAL '1 month')::date
            GROUP BY ts.slot_date ORDER BY ts.slot_date");
        $chartStmt->execute(['date' => $date]);
        foreach ($chartStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chartRevenue[$row['period']] = (float) $row['revenue'];
        }
    }

    $where = ["p.payment_status IN ('paid', 'refunded')"];
    $params = [];
    if ($range === 'day') {
        $where[] = 'ts.slot_date = CAST(:date AS date)';
        $params['date'] = $date;
    }
    if ($query !== '') {
        $where[] = "(
            to_tsvector('simple', COALESCE(u.full_name, '')) @@ websearch_to_tsquery('simple', :name_search)
            OR u.full_name ILIKE :name_partial
            OR to_tsvector('simple', COALESCE(s.service_name, '') || ' ' || COALESCE(s.description, '')) @@ websearch_to_tsquery('simple', :service_search)
            OR s.service_name ILIKE :service_partial
            OR u.phone_number ILIKE :phone_query
            OR CAST(r.reservation_id AS text) = :id_query
        )";
        $params += [
            'name_search' => $query,
            'name_partial' => '%' . $query . '%',
            'service_search' => $query,
            'service_partial' => '%' . $query . '%',
            'phone_query' => '%' . $query . '%',
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
        JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE " . implode(' AND ', $where) . '
        ORDER BY ts.slot_date, ts.start_time, r.reservation_id');
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
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$monthLabel = $monthNames[(int) $monthStart->format('n')] . ' ' . $monthStart->format('Y');
$chartItems = [];
$listHeading = $range === 'all' ? 'Semua tanggal' : $selectedDateLabel;

if ($range === 'all') {
    if ($chartRevenue !== []) {
        $firstMonth = new DateTimeImmutable((string) array_key_first($chartRevenue), $timezone);
        $lastMonth = new DateTimeImmutable((string) array_key_last($chartRevenue), $timezone);
        for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->modify('+1 month')) {
            $period = $cursor->format('Y-m-d');
            $chartItems[] = [
                'key' => $period,
                'label' => substr($monthNames[(int) $cursor->format('n')], 0, 3) . ' ' . $cursor->format('y'),
                'tooltip' => $monthNames[(int) $cursor->format('n')] . ' ' . $cursor->format('Y'),
                'amount' => $chartRevenue[$period] ?? 0.0,
            ];
        }
    }
    $chartSubtitle = 'Pemasukan lunas per bulan · semua tanggal';
} else {
    $daysInMonth = (int) $monthStart->format('t');
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $period = $monthStart->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        $chartItems[] = [
            'key' => $period,
            'label' => (string) $day,
            'tooltip' => $day . ' ' . $monthNames[(int) $monthStart->format('n')] . ' ' . $monthStart->format('Y'),
            'amount' => $chartRevenue[$period] ?? 0.0,
        ];
    }
    $chartSubtitle = 'Pemasukan lunas per hari · ' . $monthLabel;
}

$maxRevenue = max(array_column($chartItems, 'amount') ?: [0]);
$chartWidth = max(1000, count($chartItems) * 42);
$chartBaseY = 205;
$chartMaxHeight = 155;
$chartStep = $chartWidth / max(1, count($chartItems));
$chartBarWidth = min(22, $chartStep * 0.62);
$adminPageTitle = 'Pemasukan & Reservasi | Pemon Admin';
$adminPageScript = '../assets/js/admin-reservations.js';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

<main class="lg:ml-72 px-4 py-8 sm:px-8">
  <div class="flex flex-wrap items-start justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Laporan</p>
      <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">Pemasukan &amp; Reservasi</h1>
      <p class="mt-1 text-sm text-slate-500">Lihat pembayaran per hari atau seluruh riwayat reservasi.</p>
    </div>
    <a href="dashboard.php<?= $range === 'day' ? '?date=' . rawurlencode($date) : '' ?>" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Kembali ke Dashboard</a>
  </div>

  <?php if ($pageError !== ''): ?><p role="alert" class="mt-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>

  <section class="mt-6 grid gap-4 sm:grid-cols-2">
    <div class="rounded-2xl bg-[#0d3b36] p-5 text-white shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-emerald-100">TOTAL PEMASUKAN SEPANJANG WAKTU</p>
      <p class="mt-2 text-3xl font-extrabold">Rp <?= number_format($revenueTotal, 0, ',', '.') ?></p>
      <p class="mt-2 text-xs text-emerald-100">Berdasarkan pembayaran berstatus lunas.</p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-slate-500"><?= $range === 'all' ? 'SELURUH RENTANG TANGGAL' : 'TANGGAL DIPILIH' ?></p>
      <p class="mt-2 text-3xl font-extrabold text-slate-900"><?= $selectedDayCount ?> reservasi</p>
      <p class="mt-2 text-sm text-slate-500"><?= $range === 'all' ? 'Total pembayaran lunas pada daftar ini:' : 'Pemasukan lunas pada tanggal ini:' ?> <strong class="text-slate-800">Rp <?= number_format($selectedDayRevenue, 0, ',', '.') ?></strong></p>
    </div>
  </section>

  <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm sm:p-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h2 class="text-lg font-bold"><?= $range === 'all' ? 'Grafik pemasukan bulanan' : 'Grafik pemasukan harian' ?></h2>
        <p class="mt-1 text-sm text-slate-500"><?= $escape($chartSubtitle) ?></p>
      </div>
      <form method="get" action="reservations.php" class="flex flex-wrap items-end gap-3">
        <?php if ($query !== ''): ?><input type="hidden" name="q" value="<?= $escape($query) ?>"><?php endif; ?>
        <label class="text-xs font-semibold text-slate-500" for="range-filter">Rentang reservasi
          <select id="range-filter" name="range" onchange="this.form.requestSubmit()" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
            <option value="day" <?= $range === 'day' ? 'selected' : '' ?>>Pilih satu tanggal</option>
            <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>Semua tanggal</option>
          </select>
        </label>
        <?php if ($range === 'day'): ?>
          <label class="text-xs font-semibold text-slate-500" for="date-filter">Pilih tanggal
            <input id="date-filter" name="date" type="date" value="<?= $escape($date) ?>" onchange="this.form.requestSubmit()" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
          </label>
        <?php else: ?>
          <input type="hidden" name="date" value="<?= $escape($date) ?>">
        <?php endif; ?>
        <button class="rounded-xl bg-[#0d3b36] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#082724]">Tampilkan</button>
      </form>
    </div>

    <div class="mt-6 overflow-x-auto pb-2">
      <svg viewBox="0 0 <?= $chartWidth ?> 250" role="img" aria-label="Grafik <?= $escape($chartSubtitle) ?>" width="<?= $chartWidth ?>" height="250" style="display:block;height:250px">
        <line x1="0" y1="<?= $chartBaseY ?>" x2="<?= $chartWidth ?>" y2="<?= $chartBaseY ?>" stroke="#cbd5e1" stroke-width="1" />
        <?php foreach ($chartItems as $index => $chartItem):
          $dayKey = $chartItem['key'];
          $amount = $chartItem['amount'];
          $barHeight = ($maxRevenue > 0 && $amount > 0)
              ? max(8, (int) round(($amount / $maxRevenue) * $chartMaxHeight))
              : 0;
          $barX = (($index + 0.5) * $chartStep) - ($chartBarWidth / 2);
          $barY = $chartBaseY - $barHeight;
          $dayCenter = ($index + 0.5) * $chartStep;
          $barColor = $range === 'day' && $dayKey === $date ? '#047857' : '#34d399';
          $valueLabelY = max(18, $barY - 8);
        ?>
          <?php if ($amount > 0): ?>
            <rect x="<?= round($barX, 2) ?>" y="<?= $barY ?>" width="<?= round($chartBarWidth, 2) ?>" height="<?= $barHeight ?>" rx="4" fill="<?= $barColor ?>">
              <title><?= $escape($chartItem['tooltip']) ?>: Rp <?= number_format($amount, 0, ',', '.') ?></title>
            </rect>
            <text x="<?= round($dayCenter, 2) ?>" y="<?= $valueLabelY ?>" text-anchor="middle" font-size="11" fill="#475569"><?= number_format($amount / 1000, 0, ',', '.') ?>k</text>
          <?php endif; ?>
          <text x="<?= round($dayCenter, 2) ?>" y="230" text-anchor="middle" font-size="10" fill="#64748b"><?= $escape($chartItem['label']) ?></text>
        <?php endforeach; ?>
      </svg>
      <p class="mt-2 text-right text-xs text-slate-400">Nilai label dalam ribuan rupiah</p>
    </div>
  </section>

  <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
      <div>
        <h2 class="text-lg font-bold">Reservasi lunas &amp; refund · <?= $escape($listHeading) ?></h2>
        <p class="mt-1 text-sm text-slate-500"><?= $range === 'all' ? 'Riwayat lengkap dari tanggal paling awal sampai tanggal terbaru.' : 'Hanya pembayaran lunas dan refund yang ditampilkan; pending dan gagal tidak ditampilkan.' ?></p>
      </div>
      <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800"><?= count($reservations) ?> reservasi</span>
    </div>
    <form method="get" action="reservations.php" class="flex flex-wrap gap-3 border-b border-slate-100 p-4">
      <input type="hidden" name="range" value="<?= $escape($range) ?>">
      <input type="hidden" name="date" value="<?= $escape($date) ?>">
      <label class="sr-only" for="reservation-search">Cari reservasi</label>
      <input id="reservation-search" name="q" type="search" value="<?= $escape($query) ?>" placeholder="Cari nama, telepon, layanan, atau ID..." class="min-w-[220px] flex-1 rounded-xl bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/20">
      <button type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Cari</button>
      <?php if ($query !== ''): ?><a href="reservations.php?<?= $escape(http_build_query(['range' => $range, 'date' => $date])) ?>" class="rounded-xl bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-600">Hapus pencarian</a><?php endif; ?>
    </form>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[900px] text-sm">
        <thead class="bg-indigo-50/60 text-[11px] uppercase tracking-wider text-slate-500"><tr>
          <th class="px-4 py-3 text-left font-semibold">Pelanggan</th><th class="px-4 py-3 text-left font-semibold">Telepon</th><th class="px-4 py-3 text-left font-semibold">Layanan</th><th class="px-4 py-3 text-left font-semibold">Jadwal</th><th class="px-4 py-3 text-left font-semibold">Status pembayaran</th><th class="px-4 py-3 text-right font-semibold">Total</th><th class="px-4 py-3 text-right font-semibold">Tindakan</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($reservations === []): ?>
            <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500"><?= $query === '' ? ($range === 'all' ? 'Belum ada pembayaran lunas atau refund.' : 'Belum ada pembayaran lunas atau refund pada tanggal ini.') : 'Pembayaran lunas atau refund yang cocok tidak ditemukan.' ?></td></tr>
          <?php else: foreach ($reservations as $reservation):
            $cancelled = $reservation['reservation_status'] === 'inactive';
            $paymentStatus = $reservation['payment_status'];
            $isRefunded = $paymentStatus === 'refunded';
            $paymentLabel = $isRefunded ? 'Refund' : 'Lunas';
            $paymentClass = $isRefunded ? 'bg-red-100 text-red-700' : 'bg-emerald-50 text-emerald-800';
          ?>
            <tr class="<?= $cancelled ? 'bg-red-50/40' : 'hover:bg-slate-50' ?>">
              <td class="px-4 py-4"><p class="font-semibold text-slate-900"><?= $escape($reservation['full_name']) ?></p><p class="text-xs text-slate-500">Reservasi #<?= (int) $reservation['reservation_id'] ?><?= $cancelled ? ' · Dibatalkan' : '' ?></p></td>
              <td class="px-4 py-4 font-mono text-xs text-slate-600"><?= $escape($reservation['phone_number']) ?></td>
              <td class="px-4 py-4 font-medium"><?= $escape($reservation['service_name']) ?></td>
              <?php $reservationStart = new DateTimeImmutable((string) $reservation['slot_date'] . ' ' . (string) $reservation['start_time'], $timezone); ?>
              <td class="px-4 py-4"><p><?= $escape($reservationStart->format('d/m/Y')) ?></p><p class="text-xs text-slate-500"><?= $escape(substr((string) $reservation['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $reservation['end_time'], 0, 5)) ?> WIB</p></td>
              <td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-semibold <?= $paymentClass ?>"><?= $escape($paymentLabel) ?></span><?php if (!empty($reservation['payment_type'])): ?><p class="mt-1 text-xs text-slate-500"><?= $escape($reservation['payment_type']) ?></p><?php endif; ?></td>
              <td class="px-4 py-4 text-right font-semibold">Rp <?= number_format((float) $reservation['total_payment'], 0, ',', '.') ?></td>
              <td class="px-4 py-4 text-right">
                <?php if (!$cancelled && $paymentStatus === 'paid' && $reservationStart > $now): ?>
                  <button type="button" class="open-reschedule rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900 hover:bg-emerald-100" data-reservation-id="<?= (int) $reservation['reservation_id'] ?>" data-customer="<?= $escape($reservation['full_name']) ?>" data-current="<?= $escape($reservationStart->format('d/m/Y H:i')) ?>">Ganti jadwal</button>
                <?php else: ?>
                  <span class="text-xs text-slate-300">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <input id="admin-reservations-csrf" type="hidden" value="<?= $escape($csrfToken) ?>">
  <dialog id="reschedule-dialog" class="w-[min(94vw,32rem)] rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40">
    <form id="reschedule-form" class="space-y-5 p-6">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-emerald-800">Penanganan reservasi</p>
        <h2 class="mt-1 text-xl font-bold">Ganti jadwal</h2>
        <p id="reschedule-customer" class="mt-2 text-sm text-slate-600"></p>
        <p id="reschedule-current" class="text-sm text-slate-500"></p>
      </div>
      <input id="reschedule-id" type="hidden">
      <label class="block text-sm font-semibold">Tanggal baru
        <input id="reschedule-date" type="date" required min="<?= $escape($today->format('Y-m-d')) ?>" class="mt-2 block w-full rounded-xl border border-slate-200 px-3 py-3 font-normal">
      </label>
      <label class="block text-sm font-semibold">Slot yang tersedia
        <select id="reschedule-slot" required disabled class="mt-2 block w-full rounded-xl border border-slate-200 bg-white px-3 py-3 font-normal"><option value="">Pilih tanggal terlebih dahulu</option></select>
      </label>
      <label class="block text-sm font-semibold">Catatan kendala
        <input id="reschedule-reason" maxlength="500" value="Kendala operasional" class="mt-2 block w-full rounded-xl border border-slate-200 px-3 py-3 font-normal">
      </label>
      <label class="flex items-start gap-3 text-sm text-slate-700"><input id="reschedule-consent" type="checkbox" required class="mt-1 accent-[#0d3b36]">Saya sudah menghubungi pelanggan dan pelanggan menyetujui jadwal baru ini.</label>
      <p id="reschedule-message" role="status" aria-live="polite" class="hidden rounded-xl px-4 py-3 text-sm"></p>
      <div class="flex justify-end gap-3">
        <button type="button" id="close-reschedule" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700">Kembali</button>
        <button id="submit-reschedule" type="submit" disabled class="rounded-xl bg-[#0d3b36] px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:bg-slate-300">Pindahkan reservasi</button>
      </div>
    </form>
  </dialog>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
