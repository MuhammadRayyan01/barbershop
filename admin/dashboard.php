<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$csrfToken = getCsrfToken();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
$date = ($requestMethod === 'POST' ? ($_POST['date'] ?? null) : ($_GET['date'] ?? null)) ?? $today->format('Y-m-d');
if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today->format('Y-m-d');
} else {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) $date = $today->format('Y-m-d');
}
$query = trim((string) ($requestMethod === 'POST' ? ($_POST['q'] ?? '') : ($_GET['q'] ?? '')));
$pageError = '';
$flashMessage = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

if ($requestMethod === 'POST') {
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');
    $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    try {
        if (!$reservationId || $reservationId < 1 || !in_array($action, ['mark_paid', 'cancel'], true)) {
            throw new InvalidArgumentException('Aksi reservasi tidak valid.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT reservation_status FROM reservation WHERE reservation_id = :id FOR UPDATE');
        $stmt->execute(['id' => $reservationId]);
        $status = $stmt->fetchColumn();
        if ($status !== 'active') throw new InvalidArgumentException('Reservasi aktif tidak ditemukan.');

        if ($action === 'cancel') {
            $stmt = $pdo->prepare("UPDATE reservation SET reservation_status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE reservation_id = :id");
            $stmt->execute(['id' => $reservationId]);
            $_SESSION['admin_flash'] = 'Reservasi dibatalkan.';
        } else {
            $stmt = $pdo->prepare('SELECT total_payment FROM invoice WHERE reservation_id = :id');
            $stmt->execute(['id' => $reservationId]);
            $amount = $stmt->fetchColumn();
            if ($amount === false) throw new InvalidArgumentException('Invoice reservasi tidak ditemukan.');
            $stmt = $pdo->prepare("INSERT INTO payment (reservation_id, payment_type, payment_status, amount) VALUES (:id, 'cash', 'paid', :amount) ON CONFLICT (reservation_id) DO UPDATE SET payment_type = 'cash', payment_status = 'paid', amount = EXCLUDED.amount, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute(['id' => $reservationId, 'amount' => $amount]);
            $_SESSION['admin_flash'] = 'Pembayaran tunai dicatat.';
        }
        $pdo->commit();
        header('Location: dashboard.php?' . http_build_query(['date' => $date, 'q' => $query]));
        exit;
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pageError = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin dashboard action error: ' . $e->getMessage());
        $pageError = 'Aksi reservasi gagal disimpan.';
    }
}

try {
    $statsStmt = $pdo->prepare("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE reservation_status = 'inactive') AS cancelled FROM reservation r JOIN time_slot ts ON ts.slot_id = r.slot_id WHERE ts.slot_date = CAST(:date AS date)");
    $statsStmt->execute(['date' => $date]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $where = ['ts.slot_date = CAST(:date AS date)'];
    $params = ['date' => $date];
    if ($query !== '') {
        $where[] = '(u.full_name ILIKE :name_query OR u.phone_number ILIKE :phone_query OR s.service_name ILIKE :service_query OR CAST(r.reservation_id AS text) = :id_query)';
        $params += ['name_query' => '%' . $query . '%', 'phone_query' => '%' . $query . '%', 'service_query' => '%' . $query . '%', 'id_query' => $query];
    }
    $stmt = $pdo->prepare("SELECT r.reservation_id, r.reservation_status, u.full_name, u.phone_number, s.service_name, r.service_price, ts.slot_date, ts.start_time, i.total_payment, p.payment_status, p.payment_type FROM reservation r JOIN users u ON u.user_id = r.user_id JOIN service s ON s.service_id = r.service_id JOIN time_slot ts ON ts.slot_id = r.slot_id LEFT JOIN invoice i ON i.reservation_id = r.reservation_id LEFT JOIN payment p ON p.reservation_id = r.reservation_id WHERE " . implode(' AND ', $where) . ' ORDER BY ts.start_time, r.reservation_id');
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin dashboard data error: ' . $e->getMessage());
    $reservations = [];
    $stats = ['total' => 0, 'cancelled' => 0];
    $pageError = 'Data reservasi gagal dimuat.';
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reservations.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Reservation ID', 'Customer', 'Phone', 'Date', 'Time', 'Service', 'Total', 'Reservation Status', 'Payment Status']);
    foreach ($reservations as $reservation) {
        fputcsv($output, [$reservation['reservation_id'], $reservation['full_name'], $reservation['phone_number'], $reservation['slot_date'], substr((string) $reservation['start_time'], 0, 5), $reservation['service_name'], $reservation['total_payment'] ?? $reservation['service_price'], $reservation['reservation_status'], $reservation['payment_status'] ?? 'pending']);
    }
    fclose($output);
    exit;
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | Pemon Admin</title>
  <link rel="stylesheet" href="../assets/css/tailwind.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
  <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-[#f8f8fd] text-slate-900">

  <div id="admin-sidebar" data-admin-name="<?= $escape($_SESSION['admin_name'] ?? 'Admin') ?>"></div>
  <div id="admin-topbar"></div>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold">Reservasi &amp; Operasional</h1>
        <p class="text-sm text-slate-500 mt-1">Daftar reservasi dan status pembayaran untuk tanggal yang dipilih.</p>
      </div>
      <a href="dashboard.php?<?= $escape(http_build_query(['date' => $date, 'q' => $query, 'export' => 'csv'])) ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 text-slate-800 text-sm font-semibold hover:bg-indigo-100">
        <span class="material-symbols-outlined text-[18px]">download</span>Export Log
      </a>
    </div>

    <section class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-6">
      <div class="bg-white rounded-2xl p-5 flex items-center justify-between shadow-sm">
        <div>
          <p class="text-xs font-semibold tracking-wide text-slate-500">TOTAL RESERVATION</p>
          <p class="text-4xl font-extrabold mt-1"><?= (int) $stats['total'] ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-emerald-100 text-[#0d3b36] flex items-center justify-center">
          <span class="material-symbols-outlined">calendar_today</span>
        </div>
      </div>
      <div class="bg-white rounded-2xl p-5 flex items-center justify-between shadow-sm">
        <div>
          <p class="text-xs font-semibold tracking-wide text-slate-500">TOTAL CANCELED</p>
          <p class="text-4xl font-extrabold mt-1"><?= (int) $stats['cancelled'] ?></p>
        </div>
        <div class="w-12 h-12 rounded-xl bg-red-100 text-red-600 flex items-center justify-center">
          <span class="material-symbols-outlined">event_busy</span>
        </div>
      </div>
    </section>

    <?php if ($pageError !== ''): ?><p class="mt-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>
    <?php if ($flashMessage !== ''): ?><p class="mt-5 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= $escape($flashMessage) ?></p><?php endif; ?>
    <section class="bg-white rounded-2xl shadow-sm mt-6 overflow-hidden">
      <form method="get" action="dashboard.php" class="flex flex-wrap items-center gap-3 p-4">
        <div class="flex items-center gap-2 bg-[#f8f8fd] rounded-xl px-4 py-2.5 flex-1 min-w-[200px] max-w-sm">
          <span class="material-symbols-outlined text-[18px] text-slate-500">search</span>
          <input id="search" name="q" type="text" value="<?= $escape($query) ?>" placeholder="Cari nama / telepon..." class="w-full bg-transparent outline-none text-sm">
        </div>
        <label class="flex items-center gap-2 bg-[#f8f8fd] rounded-xl px-4 py-2.5 text-sm">
          <span class="material-symbols-outlined text-[18px] text-slate-500">calendar_month</span>
          <span class="text-slate-500">Tanggal:</span>
          <input id="date-filter" name="date" type="date" value="<?= $escape($date) ?>" class="bg-transparent outline-none font-semibold">
        </label>
        <div class="ml-auto flex items-center gap-3 text-xs text-slate-500">
          <span><?= count($reservations) ?> reservasi</span>
          <button type="submit" class="p-2 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-slate-700" aria-label="Tampilkan">
            <span class="material-symbols-outlined text-[18px]">refresh</span>
          </button>
        </div>
      </form>

      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[860px]">
          <thead class="bg-indigo-50/60 text-[11px] tracking-wider text-slate-500 uppercase">
            <tr>
              <th class="text-left font-semibold px-4 py-3">Pelanggan / Name</th>
              <th class="text-left font-semibold px-4 py-3">Phone</th>
              <th class="text-left font-semibold px-4 py-3">Service</th>
              <th class="text-left font-semibold px-4 py-3">Price</th>
              <th class="text-left font-semibold px-4 py-3">Payment Status</th>
              <th class="text-right font-semibold px-4 py-3">Actions</th>
            </tr>
          </thead>
          <tbody id="res-body" class="divide-y divide-slate-100">
            <?php if ($reservations === []): ?>
              <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">Tidak ada reservasi pada tanggal ini.</td></tr>
            <?php else: foreach ($reservations as $reservation):
              $cancelled = $reservation['reservation_status'] === 'inactive';
              $paid = $reservation['payment_status'] === 'paid';
              $time = substr((string) $reservation['start_time'], 0, 5);
            ?>
              <tr class="reservation-row <?= $cancelled ? 'bg-red-50/60 text-slate-400' : 'hover:bg-slate-50' ?>"
                  data-id="<?= (int) $reservation['reservation_id'] ?>" data-name="<?= $escape($reservation['full_name']) ?>"
                  data-phone="<?= $escape($reservation['phone_number']) ?>" data-service="<?= $escape($reservation['service_name']) ?>"
                  data-date="<?= $escape($reservation['slot_date']) ?>" data-time="<?= $escape($time) ?>"
                  data-price="<?= $escape($reservation['total_payment'] ?? $reservation['service_price'] ?? 0) ?>"
                  data-paid="<?= $paid ? '1' : '0' ?>" data-status="<?= $cancelled ? 'Dibatalkan' : 'Aktif' ?>">
                <td class="px-4 py-4"><p class="font-semibold <?= $cancelled ? 'line-through' : 'text-slate-900' ?>"><?= $escape($reservation['full_name']) ?></p><p class="text-xs text-slate-500"><?= $escape($date) ?> • <?= $escape($time) ?> WIB</p></td>
                <td class="px-4 py-4 font-mono text-xs"><?= $escape($reservation['phone_number']) ?></td>
                <td class="px-4 py-4"><p class="font-semibold <?= $cancelled ? 'line-through' : 'text-slate-900' ?>"><?= $escape($reservation['service_name']) ?></p><p class="text-xs text-slate-500">Reservasi #<?= (int) $reservation['reservation_id'] ?></p></td>
                <td class="px-4 py-4 font-semibold">Rp <?= number_format((float) ($reservation['total_payment'] ?? $reservation['service_price'] ?? 0), 0, ',', '.') ?></td>
                <td class="px-4 py-4">
                  <?php if ($cancelled): ?><span class="rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Dibatalkan</span>
                  <?php elseif ($paid): ?><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Lunas (Tunai)</span>
                  <?php else: ?><span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">Belum Bayar</span><?php endif; ?>
                </td>
                <td class="px-4 py-4 text-right"><div class="flex justify-end gap-2">
                  <button type="button" data-act="detail" class="rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-slate-700">Detail</button>
                  <?php if (!$cancelled && !$paid): ?><button type="button" data-act="pay" class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white">Catat Tunai</button><?php endif; ?>
                  <?php if (!$cancelled): ?><button type="button" data-act="cancel" class="p-1 text-red-600" aria-label="Batalkan"><span class="material-symbols-outlined text-[18px]">close</span></button>
                  <?php elseif ($paid): ?><a href="refunds.php?q=<?= (int) $reservation['reservation_id'] ?>" class="rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">Lihat Refund</a><?php endif; ?>
                </div></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <p class="px-4 py-4 text-xs text-slate-500">Menampilkan <?= count($reservations) ?> reservasi untuk <?= $escape($date) ?>.</p>
    </section>
  </main>

  <div id="detail-modal" class="modal hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
      <div class="flex items-center justify-between px-6 py-4 bg-indigo-50/60">
        <h3 class="font-bold text-lg">Detail Reservasi</h3>
        <button data-close-modal class="p-1 rounded-lg hover:bg-white/70"><span class="material-symbols-outlined">close</span></button>
      </div>
      <dl id="detail-body" class="px-6 py-5 text-sm grid grid-cols-3 gap-y-3"></dl>
      <div class="px-6 pb-5 flex justify-end">
        <button data-close-modal class="px-4 py-2 rounded-xl bg-slate-100 text-sm font-semibold text-slate-600 hover:bg-slate-200">Tutup</button>
      </div>
    </div>
  </div>

  <form id="reservation-action-form" method="post" action="dashboard.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="reservation_id" id="action-reservation-id">
    <input type="hidden" name="action" id="reservation-action">
    <input type="hidden" name="date" value="<?= $escape($date) ?>">
    <input type="hidden" name="q" value="<?= $escape($query) ?>">
  </form>

  <script src="../assets/js/admin.js"></script>
  <script src="../assets/js/admin-dashboard.js"></script>
</body>
</html>
