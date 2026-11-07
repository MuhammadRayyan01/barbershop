<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$csrfToken = getCsrfToken();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$query = trim((string) ($requestMethod === 'POST' ? ($_POST['q'] ?? '') : ($_GET['q'] ?? '')));
$statusFilter = $requestMethod === 'POST' ? ($_POST['status'] ?? 'all') : ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'waiting', 'refunded', 'void'], true)) $statusFilter = 'all';
$pageError = '';
$flashMessage = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

if ($requestMethod === 'POST') {
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');
    $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        if (!$reservationId || $reservationId < 1) throw new InvalidArgumentException('ID reservasi tidak valid.');
        $stmt = $pdo->prepare("UPDATE payment p SET payment_status = 'refunded', updated_at = CURRENT_TIMESTAMP FROM reservation r WHERE r.reservation_id = p.reservation_id AND p.reservation_id = :id AND p.payment_status = 'paid' AND r.reservation_status = 'inactive' RETURNING p.reservation_id");
        $stmt->execute(['id' => $reservationId]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Refund hanya dapat dicatat untuk reservasi yang dibatalkan dan sudah dibayar.');
        $_SESSION['admin_flash'] = 'Status refund dicatat. Pastikan dana sudah dikembalikan secara terpisah.';
        header('Location: refunds.php?' . http_build_query(['q' => $query, 'status' => $statusFilter]));
        exit;
    } catch (InvalidArgumentException $e) {
        $pageError = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Admin refund update error: ' . $e->getMessage());
        $pageError = 'Status refund gagal disimpan.';
    }
}

try {
    $stmt = $pdo->query("SELECT r.reservation_id, i.invoice_id, i.customer_name, i.phone_number, s.service_name, i.total_payment, i.created_at, p.payment_status, CASE WHEN p.payment_status = 'refunded' THEN 'refunded' WHEN p.payment_status = 'paid' THEN 'waiting' ELSE 'void' END AS state FROM reservation r JOIN invoice i ON i.reservation_id = r.reservation_id JOIN service s ON s.service_id = r.service_id LEFT JOIN payment p ON p.reservation_id = r.reservation_id WHERE r.reservation_status = 'inactive' ORDER BY i.created_at DESC");
    $allRefunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stats = ['total' => count($allRefunds), 'waiting' => 0, 'refunded_amount' => 0.0];
    foreach ($allRefunds as $row) {
        if ($row['state'] === 'waiting') $stats['waiting']++;
        if ($row['state'] === 'refunded') $stats['refunded_amount'] += (float) $row['total_payment'];
    }
    $refunds = array_values(array_filter($allRefunds, static function (array $row) use ($query, $statusFilter): bool {
        $matchesStatus = $statusFilter === 'all' || $row['state'] === $statusFilter;
        $searchText = strtolower(implode(' ', [$row['customer_name'], $row['phone_number'], $row['service_name'], (string) $row['invoice_id'], (string) $row['reservation_id']]));
        return $matchesStatus && ($query === '' || str_contains($searchText, strtolower($query)));
    }));
} catch (PDOException $e) {
    error_log('Admin refund list error: ' . $e->getMessage());
    $allRefunds = $refunds = [];
    $stats = ['total' => 0, 'waiting' => 0, 'refunded_amount' => 0.0];
    $pageError = 'Riwayat refund gagal dimuat.';
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="refund-history.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Invoice ID', 'Reservation ID', 'Customer', 'Phone', 'Service', 'Total', 'Refund Status']);
    foreach ($refunds as $refund) {
        fputcsv($output, [$refund['invoice_id'], $refund['reservation_id'], $refund['customer_name'], $refund['phone_number'], $refund['service_name'], $refund['total_payment'], $refund['state']]);
    }
    fclose($output);
    exit;
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$stateLabels = ['waiting' => 'Menunggu Refund', 'refunded' => 'Refund Selesai Dicatat', 'void' => 'Belum Ada Pembayaran'];
$stateClasses = ['waiting' => 'bg-amber-100 text-amber-700', 'refunded' => 'bg-emerald-100 text-emerald-800', 'void' => 'bg-indigo-100 text-indigo-700'];
$adminPageTitle = 'Refunds & Invoices | Pemon Admin';
$adminPageScript = '../assets/js/refunds.js';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold">Kelola Refund &amp; Invoices</h1>
        <p class="text-sm text-slate-500 mt-1">Daftar reservasi yang dibatalkan dan status pembayaran terkait.</p>
      </div>
      <div class="flex gap-2 no-print">
        <a href="refunds.php?<?= $escape(http_build_query(['q' => $query, 'status' => $statusFilter, 'export' => 'csv'])) ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 text-sm font-semibold hover:bg-indigo-100"><span class="material-symbols-outlined text-[18px]">download</span>Ekspor CSV</a>
        <button id="btn-print" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 text-sm font-semibold hover:bg-indigo-100"><span class="material-symbols-outlined text-[18px]">print</span>Cetak Rekap</button>
      </div>
    </div>

    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
      <div class="bg-white rounded-2xl p-5 shadow-sm">
        <p class="text-[11px] font-semibold tracking-wider text-slate-500">TOTAL PEMBATALAN</p>
        <p class="text-3xl font-extrabold mt-1"><?= (int) $stats['total'] ?></p>
      </div>
      <div class="bg-white rounded-2xl p-5 shadow-sm">
        <p class="text-[11px] font-semibold tracking-wider text-slate-500">REFUND TELAH DIPROSES</p>
        <p class="text-3xl font-extrabold mt-1 text-[#0d3b36]">Rp <?= number_format($stats['refunded_amount'], 0, ',', '.') ?></p>
      </div>
      <div class="bg-white rounded-2xl p-5 shadow-sm">
        <p class="text-[11px] font-semibold tracking-wider text-slate-500">MENUNGGU KONFIRMASI</p>
        <p class="text-3xl font-extrabold mt-1"><?= (int) $stats['waiting'] ?> Antrean</p>
      </div>
    </section>
    <?php if ($pageError !== ''): ?><p class="mt-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>
    <?php if ($flashMessage !== ''): ?><p class="mt-5 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= $escape($flashMessage) ?></p><?php endif; ?>

    <section class="bg-white rounded-2xl shadow-sm mt-6 overflow-hidden">
      <form method="get" action="refunds.php" class="flex flex-wrap items-center gap-3 p-4 no-print">
        <div class="flex items-center gap-2 bg-[#f8f8fd] rounded-xl px-4 py-2.5 flex-1 min-w-[240px]">
          <span class="material-symbols-outlined text-[18px] text-slate-500">search</span>
          <input id="search" name="q" type="text" value="<?= $escape($query) ?>" placeholder="Cari nama, nomor invoice, atau layanan..." class="w-full bg-transparent outline-none text-sm">
        </div>
        <select id="status-filter" name="status" class="bg-[#f8f8fd] rounded-xl px-4 py-2.5 text-sm font-semibold outline-none">
          <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua Status</option>
          <option value="waiting" <?= $statusFilter === 'waiting' ? 'selected' : '' ?>>Menunggu Refund</option>
          <option value="refunded" <?= $statusFilter === 'refunded' ? 'selected' : '' ?>>Refund Selesai Dicatat</option>
          <option value="void" <?= $statusFilter === 'void' ? 'selected' : '' ?>>Belum Ada Pembayaran</option>
        </select>
        <button type="submit" class="inline-flex items-center gap-2 bg-[#f8f8fd] rounded-xl px-4 py-2.5 text-sm font-semibold">Terapkan</button>
      </form>

      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[900px]">
          <thead class="bg-indigo-50/60 text-[11px] tracking-wider text-slate-500 uppercase">
            <tr>
              <th class="text-left font-semibold px-4 py-3 w-12">#</th>
              <th class="text-left font-semibold px-4 py-3">Name</th>
              <th class="text-left font-semibold px-4 py-3">Service Name</th>
              <th class="text-left font-semibold px-4 py-3">Price</th>
              <th class="text-left font-semibold px-4 py-3">Status</th>
              <th class="text-right font-semibold px-4 py-3">Action</th>
            </tr>
          </thead>
          <tbody id="ref-body" class="divide-y divide-slate-100">
            <?php if ($refunds === []): ?>
              <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">Tidak ada data yang cocok.</td></tr>
            <?php else: foreach ($refunds as $index => $refund): ?>
              <tr class="refund-row hover:bg-slate-50" data-id="<?= (int) $refund['reservation_id'] ?>" data-name="<?= $escape($refund['customer_name']) ?>" data-phone="<?= $escape($refund['phone_number']) ?>" data-service="<?= $escape($refund['service_name']) ?>" data-invoice="INV-<?= str_pad((string) $refund['invoice_id'], 6, '0', STR_PAD_LEFT) ?>" data-price="<?= $escape($refund['total_payment']) ?>" data-state="<?= $escape($stateLabels[$refund['state']]) ?>">
                <td class="px-4 py-4 text-slate-500"><?= $index + 1 ?></td>
                <td class="px-4 py-4"><p class="font-bold"><?= $escape($refund['customer_name']) ?></p><p class="text-xs text-slate-500"><?= $escape($refund['phone_number']) ?> <span class="ml-2">&bull; INV-<?= str_pad((string) $refund['invoice_id'], 6, '0', STR_PAD_LEFT) ?></span></p></td>
                <td class="px-4 py-4"><p class="font-bold"><?= $escape($refund['service_name']) ?></p><p class="text-xs text-slate-500">Reservasi #<?= (int) $refund['reservation_id'] ?></p></td>
                <td class="px-4 py-4 font-bold whitespace-nowrap">Rp <?= number_format((float) $refund['total_payment'], 0, ',', '.') ?></td>
                <td class="px-4 py-4"><span class="inline-flex rounded-full px-3 py-1 text-[11px] font-semibold <?= $stateClasses[$refund['state']] ?>"><?= $escape($stateLabels[$refund['state']]) ?></span></td>
                <td class="px-4 py-4 text-right">
                  <?php if ($refund['state'] === 'waiting'): ?><button type="button" data-act="refund" class="rounded-lg bg-[#0d3b36] px-4 py-2.5 text-xs font-bold text-white">Catat Refund Selesai</button>
                  <?php else: ?><button type="button" data-act="invoice" class="rounded-lg bg-indigo-50 px-4 py-2 text-xs font-semibold">Lihat Invoice</button><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <p class="px-4 py-4 text-xs text-slate-500 no-print">Menampilkan <?= count($refunds) ?> dari <?= count($allRefunds) ?> riwayat pembatalan.</p>
    </section>
  </main>

  <div id="invoice-modal" class="modal hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
      <div class="flex items-center justify-between px-6 py-4 bg-indigo-50/60">
        <h3 class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">receipt_long</span>Invoice</h3>
        <button data-close-modal class="p-1 rounded-lg hover:bg-white/70"><span class="material-symbols-outlined">close</span></button>
      </div>
      <dl id="invoice-body" class="px-6 py-5 text-sm grid grid-cols-3 gap-y-3"></dl>
      <div class="px-6 pb-5 flex justify-end">
        <button data-close-modal class="px-4 py-2 rounded-xl bg-slate-100 text-sm font-semibold text-slate-600 hover:bg-slate-200">Tutup</button>
      </div>
    </div>
  </div>

  <form id="refund-action-form" method="post" action="refunds.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="reservation_id" id="refund-reservation-id">
    <input type="hidden" name="q" value="<?= $escape($query) ?>">
    <input type="hidden" name="status" value="<?= $escape($statusFilter) ?>">
  </form>

<?php require __DIR__ . '/includes/footer.php'; ?>
