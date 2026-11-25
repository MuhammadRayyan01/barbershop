<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';

$timezone = new DateTimeZone('Asia/Jakarta');
$today = new DateTimeImmutable('today', $timezone);
$range = $_GET['range'] ?? '30';
$validRanges = ['day', '7', '30', '90', 'custom', 'all'];
if (!in_array($range, $validRanges, true)) $range = '30';
$date = $_GET['date'] ?? $today->format('Y-m-d');
$parseDate = static function ($value) use ($timezone) {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    return $parsed && $parsed->format('Y-m-d') === $value ? $parsed : false;
};
$selectedDate = $parseDate($date);
if (!$selectedDate) {
    $selectedDate = $today;
    $date = $today->format('Y-m-d');
}
$customStartValue = $_GET['start_date'] ?? $today->modify('-29 days')->format('Y-m-d');
$customEndValue = $_GET['end_date'] ?? $today->format('Y-m-d');
$pageError = '';
$periodStart = null;
$periodEndExclusive = null;
if ($range === 'day') {
    $periodStart = $selectedDate;
    $periodEndExclusive = $selectedDate->modify('+1 day');
} elseif (in_array($range, ['7', '30', '90'], true)) {
    $days = (int) $range;
    $periodStart = $today->modify('-' . ($days - 1) . ' days');
    $periodEndExclusive = $today->modify('+1 day');
} elseif ($range === 'custom') {
    $customStart = $parseDate($customStartValue);
    $customEnd = $parseDate($customEndValue);
    if (!$customStart || !$customEnd || $customEnd < $customStart) {
        $customStart = $today->modify('-29 days');
        $customEnd = $today;
        $customStartValue = $customStart->format('Y-m-d');
        $customEndValue = $customEnd->format('Y-m-d');
        $pageError = 'Rentang tanggal tidak valid. Rentang 30 hari terakhir ditampilkan.';
    }
    $periodStart = $customStart;
    $periodEndExclusive = $customEnd->modify('+1 day');
}
$query = trim((string) ($_GET['q'] ?? ''));
$reservations = [];
$chartRevenue = [];
$periodReservationCount = 0;
$paidRevenue = 0.0;
$refundCount = 0;
$refundAmount = 0.0;
$netRevenue = 0.0;
$popularServices = [];

try {
    $rangeFilterSql = $range === 'all'
        ? ''
        : ' AND ts.slot_date >= CAST(:period_start AS date) AND ts.slot_date < CAST(:period_end AS date)';
    $rangeParams = $range === 'all' ? [] : [
        'period_start' => $periodStart->format('Y-m-d'),
        'period_end' => $periodEndExclusive->format('Y-m-d'),
    ];
    $chartUsesMonths = $range === 'all' ||
        ($periodStart && $periodEndExclusive && $periodStart->diff($periodEndExclusive)->days > 90);
    $chartPeriodSql = $chartUsesMonths
        ? "date_trunc('month', ts.slot_date)::date"
        : 'ts.slot_date';
    $chartStmt = $pdo->prepare("SELECT $chartPeriodSql AS period, SUM(p.amount) AS revenue
        FROM time_slot ts
        JOIN reservation r ON r.slot_id = ts.slot_id
        JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE p.payment_status = 'paid'" . $rangeFilterSql . "
        GROUP BY period ORDER BY period");
    $chartStmt->execute($rangeParams);
    foreach ($chartStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chartRevenue[$row['period']] = (float) $row['revenue'];
    }

    // Aggregate statistics in PostgreSQL; do not load raw rows for summary cards.
    $summaryStmt = $pdo->prepare("SELECT
            COUNT(*)::integer AS reservation_count,
            COUNT(*) FILTER (WHERE p.payment_status = 'refunded')::integer AS refund_count,
            COALESCE(SUM(p.amount) FILTER (WHERE p.payment_status = 'paid'), 0) AS paid_revenue,
            COALESCE(SUM(p.amount) FILTER (WHERE p.payment_status = 'refunded'), 0) AS refund_amount
        FROM reservation r
        JOIN time_slot ts ON ts.slot_id = r.slot_id
        JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE p.payment_status IN ('paid', 'refunded')" . $rangeFilterSql);
    $summaryStmt->execute($rangeParams);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    $periodReservationCount = (int) $summary['reservation_count'];
    $refundCount = (int) $summary['refund_count'];
    $paidRevenue = (float) $summary['paid_revenue'];
    $refundAmount = (float) $summary['refund_amount'];
    $netRevenue = $paidRevenue - $refundAmount;

    $popularStmt = $pdo->prepare("SELECT s.service_name, COUNT(*)::integer AS reservation_count,
            COALESCE(SUM(p.amount), 0) AS paid_revenue
        FROM reservation r
        JOIN time_slot ts ON ts.slot_id = r.slot_id
        JOIN service s ON s.service_id = r.service_id
        JOIN payment p ON p.reservation_id = r.reservation_id
        WHERE p.payment_status = 'paid'" . $rangeFilterSql . "
        GROUP BY s.service_id, s.service_name
        ORDER BY reservation_count DESC, paid_revenue DESC, s.service_name
        LIMIT 5");
    $popularStmt->execute($rangeParams);
    $popularServices = $popularStmt->fetchAll(PDO::FETCH_ASSOC);

    $where = ["p.payment_status IN ('paid', 'refunded')"];
    $params = $rangeParams;
    if ($range !== 'all') {
        $where[] = 'ts.slot_date >= CAST(:period_start AS date) AND ts.slot_date < CAST(:period_end AS date)';
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
    $whereSql = implode(' AND ', $where);
    $joins = "FROM reservation r
        JOIN users u ON u.user_id = r.user_id
        JOIN service s ON s.service_id = r.service_id
        JOIN time_slot ts ON ts.slot_id = r.slot_id
        JOIN payment p ON p.reservation_id = r.reservation_id";
    $countStmt = $pdo->prepare("SELECT COUNT(*) $joins WHERE $whereSql");
    $countStmt->execute($params);
    $totalReservations = (int) $countStmt->fetchColumn();
    $perPage = 25;
    $totalPages = max(1, (int) ceil($totalReservations / $perPage));
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $page = min($page, $totalPages);
    $stmt = $pdo->prepare("SELECT r.reservation_id, r.reservation_status, u.full_name,
            u.phone_number, s.service_name, ts.slot_date, ts.start_time, ts.end_time,
            COALESCE(i.total_payment, r.service_price, 0) AS total_payment,
            p.payment_status, p.payment_type
        $joins
        LEFT JOIN invoice i ON i.reservation_id = r.reservation_id
        WHERE $whereSql
        ORDER BY ts.slot_date DESC, ts.start_time, r.reservation_id
        LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin revenue and reservation page error: ' . $e->getMessage());
    $pageError = 'Data pemasukan dan reservasi gagal dimuat.';
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$selectedDateLabel = $selectedDate->format('d/m/Y');
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
if ($range === 'all') {
    $periodLabel = 'Semua tanggal';
} elseif ($range === 'day') {
    $periodLabel = 'Tanggal ' . $selectedDateLabel;
} elseif ($range === 'custom') {
    $periodLabel = $periodStart->format('d/m/Y') . '–' . $periodEndExclusive->modify('-1 day')->format('d/m/Y');
} else {
    $periodLabel = $range . ' hari terakhir';
}
$listHeading = $periodLabel;
$chartItems = [];
if ($chartUsesMonths) {
    if ($chartRevenue !== []) {
        $firstMonth = new DateTimeImmutable((string) array_key_first($chartRevenue), $timezone);
        $lastMonth = new DateTimeImmutable((string) array_key_last($chartRevenue), $timezone);
        if ($range !== 'all') {
            $firstMonth = $periodStart->modify('first day of this month');
            $lastMonth = $periodEndExclusive->modify('-1 day')->modify('first day of this month');
        }
        for ($cursor = $firstMonth; $cursor <= $lastMonth; $cursor = $cursor->modify('+1 month')) {
            $key = $cursor->format('Y-m-01');
            $chartItems[] = [
                'key' => $key,
                'label' => substr($monthNames[(int) $cursor->format('n')], 0, 3) . ' ' . $cursor->format('y'),
                'tooltip' => $monthNames[(int) $cursor->format('n')] . ' ' . $cursor->format('Y'),
                'amount' => $chartRevenue[$key] ?? 0.0,
            ];
        }
    }
    $chartSubtitle = 'Pemasukan lunas per bulan · ' . $periodLabel;
} elseif ($periodStart && $periodEndExclusive) {
    for ($cursor = $periodStart; $cursor < $periodEndExclusive; $cursor = $cursor->modify('+1 day')) {
        $key = $cursor->format('Y-m-d');
        $chartItems[] = [
            'key' => $key,
            'label' => $cursor->format('d/m'),
            'tooltip' => $cursor->format('d/m/Y'),
            'amount' => $chartRevenue[$key] ?? 0.0,
        ];
    }
    $chartSubtitle = 'Pemasukan lunas per hari · ' . $periodLabel;
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

  <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="rounded-2xl bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-slate-500">RESERVASI</p>
      <p class="mt-2 text-3xl font-extrabold text-slate-900"><?= $periodReservationCount ?></p>
      <p class="mt-2 text-xs text-slate-500"><?= $escape($periodLabel) ?> · lunas dan refund</p>
    </div>
    <div class="rounded-2xl bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-slate-500">PEMASUKAN LUNAS</p>
      <p class="mt-2 text-2xl font-extrabold text-[#0d3b36]">Rp <?= number_format($paidRevenue, 0, ',', '.') ?></p>
      <p class="mt-2 text-xs text-slate-500"><?= $escape($periodLabel) ?></p>
    </div>
    <div class="rounded-2xl bg-red-50 p-5 shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-red-700">REFUND</p>
      <p class="mt-2 text-2xl font-extrabold text-red-700"><?= $refundCount ?> transaksi</p>
      <p class="mt-2 text-xs text-red-700">Rp <?= number_format($refundAmount, 0, ',', '.') ?> dikembalikan</p>
    </div>
    <div class="rounded-2xl bg-[#0d3b36] p-5 text-white shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-emerald-100">PEMASUKAN BERSIH</p>
      <p class="mt-2 text-2xl font-extrabold">Rp <?= number_format($netRevenue, 0, ',', '.') ?></p>
      <p class="mt-2 text-xs text-emerald-100">Lunas dikurangi refund · <?= $escape($periodLabel) ?></p>
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
            <option value="7" <?= $range === '7' ? 'selected' : '' ?>>7 hari terakhir</option>
            <option value="30" <?= $range === '30' ? 'selected' : '' ?>>30 hari terakhir</option>
            <option value="90" <?= $range === '90' ? 'selected' : '' ?>>90 hari terakhir</option>
            <option value="custom" <?= $range === 'custom' ? 'selected' : '' ?>>Rentang khusus</option>
            <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>Semua tanggal</option>
          </select>
        </label>
        <?php if ($range === 'day'): ?>
          <label class="text-xs font-semibold text-slate-500" for="date-filter">Pilih tanggal
            <input id="date-filter" name="date" type="date" value="<?= $escape($date) ?>" onchange="this.form.requestSubmit()" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
          </label>
        <?php elseif ($range === 'custom'): ?>
          <label class="text-xs font-semibold text-slate-500" for="start-date">Dari tanggal
            <input id="start-date" name="start_date" type="date" value="<?= $escape($customStartValue) ?>" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
          </label>
          <label class="text-xs font-semibold text-slate-500" for="end-date">Sampai tanggal
            <input id="end-date" name="end_date" type="date" value="<?= $escape($customEndValue) ?>" class="mt-1 block rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800">
          </label>
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
    <div class="border-b border-slate-100 p-5">
      <h2 class="text-lg font-bold">Layanan terpopuler</h2>
      <p class="mt-1 text-sm text-slate-500">Diurutkan berdasarkan jumlah transaksi lunas · <?= $escape($periodLabel) ?></p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[520px] text-sm">
        <thead class="bg-indigo-50/60 text-[11px] uppercase tracking-wider text-slate-500"><tr>
          <th class="px-4 py-3 text-left font-semibold">Layanan</th><th class="px-4 py-3 text-right font-semibold">Reservasi lunas</th><th class="px-4 py-3 text-right font-semibold">Pemasukan lunas</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($popularServices === []): ?>
            <tr><td colspan="3" class="px-4 py-8 text-center text-slate-500">Belum ada transaksi lunas pada periode ini.</td></tr>
          <?php else: foreach ($popularServices as $service): ?>
            <tr><td class="px-4 py-3 font-semibold"><?= $escape($service['service_name']) ?></td><td class="px-4 py-3 text-right"><?= (int) $service['reservation_count'] ?></td><td class="px-4 py-3 text-right font-semibold">Rp <?= number_format((float) $service['paid_revenue'], 0, ',', '.') ?></td></tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5">
      <div>
        <h2 class="text-lg font-bold">Reservasi lunas &amp; refund · <?= $escape($listHeading) ?></h2>
        <p class="mt-1 text-sm text-slate-500"><?= $range === 'all' ? 'Riwayat lengkap dari tanggal paling awal sampai tanggal terbaru.' : 'Hanya pembayaran lunas dan refund yang ditampilkan; pending dan gagal tidak ditampilkan.' ?></p>
      </div>
      <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800"><?= $totalReservations ?> reservasi</span>
    </div>
    <form method="get" action="reservations.php" class="flex flex-wrap gap-3 border-b border-slate-100 p-4">
      <input type="hidden" name="range" value="<?= $escape($range) ?>">
      <?php if ($range === 'day'): ?>
        <input type="hidden" name="date" value="<?= $escape($date) ?>">
      <?php elseif ($range === 'custom'): ?>
        <input type="hidden" name="start_date" value="<?= $escape($customStartValue) ?>">
        <input type="hidden" name="end_date" value="<?= $escape($customEndValue) ?>">
      <?php endif; ?>
      <label class="sr-only" for="reservation-search">Cari reservasi</label>
      <input id="reservation-search" name="q" type="search" value="<?= $escape($query) ?>" placeholder="Cari nama, telepon, layanan, atau ID..." class="min-w-[220px] flex-1 rounded-xl bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/20">
      <button type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Cari</button>
      <?php if ($query !== ''): ?><a href="reservations.php?<?= $escape(http_build_query(array_filter(['range' => $range, 'date' => $range === 'day' ? $date : null, 'start_date' => $range === 'custom' ? $customStartValue : null, 'end_date' => $range === 'custom' ? $customEndValue : null]))) ?>" class="rounded-xl bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-600">Hapus pencarian</a><?php endif; ?>
    </form>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[900px] text-sm">
        <thead class="bg-indigo-50/60 text-[11px] uppercase tracking-wider text-slate-500"><tr>
          <th class="px-4 py-3 text-left font-semibold">Pelanggan</th><th class="px-4 py-3 text-left font-semibold">Telepon</th><th class="px-4 py-3 text-left font-semibold">Layanan</th><th class="px-4 py-3 text-left font-semibold">Jadwal</th><th class="px-4 py-3 text-left font-semibold">Status pembayaran</th><th class="px-4 py-3 text-right font-semibold">Total</th>
        </tr></thead>
        <tbody class="divide-y divide-slate-100">
          <?php if ($reservations === []): ?>
            <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500"><?= $query === '' ? ($range === 'all' ? 'Belum ada pembayaran lunas atau refund.' : 'Belum ada pembayaran lunas atau refund pada tanggal ini.') : 'Pembayaran lunas atau refund yang cocok tidak ditemukan.' ?></td></tr>
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
              <td class="px-4 py-4"><p><?= $escape((new DateTimeImmutable((string) $reservation['slot_date'], $timezone))->format('d/m/Y')) ?></p><p class="text-xs text-slate-500"><?= $escape(substr((string) $reservation['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $reservation['end_time'], 0, 5)) ?> WIB</p></td>
              <td class="px-4 py-4"><span class="rounded-full px-3 py-1 text-xs font-semibold <?= $paymentClass ?>"><?= $escape($paymentLabel) ?></span><?php if (!empty($reservation['payment_type'])): ?><p class="mt-1 text-xs text-slate-500"><?= $escape($reservation['payment_type']) ?></p><?php endif; ?></td>
              <td class="px-4 py-4 text-right font-semibold">Rp <?= number_format((float) $reservation['total_payment'], 0, ',', '.') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
      $paginationParams = ['range' => $range];
      if ($range === 'day') $paginationParams['date'] = $date;
      if ($range === 'custom') {
          $paginationParams['start_date'] = $customStartValue;
          $paginationParams['end_date'] = $customEndValue;
      }
      if ($query !== '') $paginationParams['q'] = $query;
      $previousPageUrl = 'reservations.php?' . http_build_query($paginationParams + ['page' => max(1, $page - 1)]);
      $nextPageUrl = 'reservations.php?' . http_build_query($paginationParams + ['page' => min($totalPages, $page + 1)]);
    ?>
    <nav class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4" aria-label="Navigasi halaman reservasi">
      <p class="text-sm text-slate-500">Halaman <?= $page ?> dari <?= $totalPages ?> · <?= $totalReservations ?> reservasi</p>
      <div class="flex items-center gap-2">
        <?php if ($page > 1): ?><a href="<?= $escape($previousPageUrl) ?>" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Sebelumnya</a><?php else: ?><span class="rounded-lg border border-slate-100 px-4 py-2 text-sm text-slate-300">Sebelumnya</span><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="<?= $escape($nextPageUrl) ?>" class="rounded-lg bg-[#0d3b36] px-4 py-2 text-sm font-semibold text-white hover:bg-[#082724]">Berikutnya</a><?php else: ?><span class="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-400">Berikutnya</span><?php endif; ?>
      </div>
    </nav>
  </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
