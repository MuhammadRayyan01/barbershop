<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';
require_once __DIR__ . '/../backend/config/booking.php';

$csrfToken = getCsrfToken();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$today = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$date = ($requestMethod === 'POST' ? ($_POST['date'] ?? null) : ($_GET['date'] ?? null)) ?? $today->format('Y-m-d');
if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = $today->format('Y-m-d');
} else {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) $date = $today->format('Y-m-d');
}
$pageError = '';
$flashMessage = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

if ($requestMethod === 'POST') {
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');
    $slotId = filter_var($_POST['slot_id'] ?? null, FILTER_VALIDATE_INT);
    $availability = $_POST['is_available'] ?? '';
    $postedDate = $_POST['date'] ?? $date;
    if (!is_string($postedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedDate)) $postedDate = $date;
    try {
        if (!$slotId || $slotId < 1 || !in_array($availability, ['available', 'not available'], true)) {
            throw new InvalidArgumentException('Perubahan slot tidak valid.');
        }
        $stmt = $pdo->prepare('UPDATE time_slot SET is_available = :status WHERE slot_id = :id AND slot_date = CAST(:date AS date) RETURNING slot_id');
        $stmt->execute(['status' => $availability, 'id' => $slotId, 'date' => $postedDate]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Slot untuk tanggal tersebut tidak ditemukan.');
        $_SESSION['admin_flash'] = 'Status slot berhasil diperbarui.';
        header('Location: time-slots.php?date=' . urlencode($postedDate));
        exit;
    } catch (InvalidArgumentException $e) {
        $pageError = $e->getMessage();
    } catch (PDOException $e) {
        error_log('Admin slot update error: ' . $e->getMessage());
        $pageError = 'Status slot gagal disimpan.';
    }
}

try {
    $stmt = $pdo->prepare("SELECT ts.slot_id, ts.slot_date, ts.start_time, ts.end_time, ts.is_available, COUNT(r.reservation_id) FILTER (WHERE r.reservation_status = 'active') AS booked_count FROM time_slot ts LEFT JOIN reservation r ON r.slot_id = ts.slot_id WHERE ts.slot_date = CAST(:date AS date) GROUP BY ts.slot_id ORDER BY ts.start_time");
    $stmt->execute(['date' => $date]);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin slot list error: ' . $e->getMessage());
    $slots = [];
    $pageError = 'Data slot gagal dimuat.';
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Time Slots | Pemon Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
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
        <h1 class="text-2xl sm:text-3xl font-extrabold">Date Time Slot</h1>
        <p class="text-sm text-slate-500 mt-1">Buka atau tutup slot waktu untuk reservasi.</p>
      </div>
      <form method="get" action="time-slots.php" class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm text-sm font-semibold">
        <span class="material-symbols-outlined text-slate-500">calendar_month</span><label for="slot-date">Tanggal</label>
        <input id="slot-date" name="date" type="date" value="<?= $escape($date) ?>" class="font-medium outline-none">
        <button type="submit" class="rounded-lg bg-indigo-50 px-3 py-2">Tampilkan</button>
      </form>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3 text-xs text-slate-600">
      <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-full bg-white border border-emerald-700"></i>Tersedia (Buka)</span>
      <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded bg-slate-300"></i>Ditutup admin</span>
      <span class="ml-auto inline-flex items-center gap-2 text-slate-500">Status berlaku untuk seluruh slot waktu.</span>
    </div>

    <?php if ($pageError !== ''): ?><p class="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>
    <?php if ($flashMessage !== ''): ?><p class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= $escape($flashMessage) ?></p><?php endif; ?>
    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[700px] text-sm">
          <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-700">
            <tr>
              <th class="border-b border-r border-slate-200 px-5 py-3 text-center">Waktu (Time)</th>
              <th class="border-b border-r border-slate-200 px-5 py-3 text-center">Reservasi / Kapasitas</th>
              <th class="border-b border-slate-200 px-5 py-3 text-center">Status &amp; Aksi</th>
            </tr>
          </thead>
          <tbody id="slot-rows" class="divide-y divide-slate-100">
            <?php if ($slots === []): ?>
              <tr><td colspan="3" class="px-5 py-10 text-center text-slate-500">Belum ada slot pada tanggal ini.</td></tr>
            <?php else: foreach ($slots as $slot):
              $booked = (int) $slot['booked_count'];
              $available = $slot['is_available'] === 'available';
              $full = $available && $booked >= BOOKING_SLOT_CAPACITY;
            ?>
              <tr>
                <th scope="row" class="border-r border-slate-200 px-5 py-3 text-center font-semibold"><span class="inline-flex min-w-[68px] justify-center rounded-lg border border-[#0d3b36] bg-slate-50 px-3 py-2"><?= $escape(substr((string) $slot['start_time'], 0, 5)) ?></span></th>
                <td class="border-r border-slate-100 px-4 py-2 text-center"><?= $booked ?> / <?= BOOKING_SLOT_CAPACITY ?></td>
                <td class="px-4 py-2 text-center">
                  <form method="post" action="time-slots.php" class="inline-flex items-center gap-3">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="slot_id" value="<?= (int) $slot['slot_id'] ?>">
                    <input type="hidden" name="date" value="<?= $escape($date) ?>">
                    <span class="text-xs font-semibold <?= !$available ? 'text-slate-500' : ($full ? 'text-red-600' : 'text-emerald-700') ?>"><?= !$available ? 'Ditutup admin' : ($full ? 'Penuh' : 'Tersedia') ?></span>
                    <button type="submit" name="is_available" value="<?= $available ? 'not available' : 'available' ?>" data-close-slot="<?= $available ? '1' : '0' ?>" class="rounded-lg px-4 py-2 text-xs font-semibold <?= $available ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100' ?>"><?= $available ? 'Tutup Slot' : 'Buka Slot' ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-xs text-slate-500">Slot penuh otomatis berdasarkan kapasitas. Menutup slot akan menyembunyikannya dari booking.</p>
    </div>
  </main>

  <script src="../assets/js/admin.js"></script>
  <script src="../assets/js/admin-slots.js"></script>
</body>
</html>
