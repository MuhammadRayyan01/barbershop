<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';

$query = trim((string) ($_GET['q'] ?? ''));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$perPage = 25;
$pageError = '';
$reservations = [];
$total = 0;
$totalPages = 1;

try {
    $total = (int) $pdo->query("SELECT COUNT(*) FROM reservation r JOIN payment p ON p.reservation_id = r.reservation_id WHERE r.reservation_status = 'active' AND p.payment_status = 'paid'")->fetchColumn();

    $where = ["r.reservation_status = 'active'", "p.payment_status = 'paid'"];
    $params = [];
    if ($query !== '') {
        $where[] = '(u.full_name ILIKE :name_query OR u.phone_number ILIKE :phone_query OR s.service_name ILIKE :service_query OR CAST(r.reservation_id AS text) = :id_query)';
        $params = [
            'name_query' => '%' . $query . '%',
            'phone_query' => '%' . $query . '%',
            'service_query' => '%' . $query . '%',
            'id_query' => $query,
        ];
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reservation r JOIN users u ON u.user_id = r.user_id JOIN service s ON s.service_id = r.service_id JOIN payment p ON p.reservation_id = r.reservation_id $whereSql");
    $countStmt->execute($params);
    $filteredTotal = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($filteredTotal / $perPage));
    $page = min($page, $totalPages);

    $stmt = $pdo->prepare("SELECT r.reservation_id, u.full_name, u.phone_number,
            s.service_name, ts.slot_date, ts.start_time, ts.end_time,
            COALESCE(i.total_payment, r.service_price) AS total_payment, p.payment_type
        FROM reservation r
        JOIN users u ON u.user_id = r.user_id
        JOIN service s ON s.service_id = r.service_id
        JOIN time_slot ts ON ts.slot_id = r.slot_id
        LEFT JOIN invoice i ON i.reservation_id = r.reservation_id
        JOIN payment p ON p.reservation_id = r.reservation_id
        $whereSql
        ORDER BY ts.slot_date DESC, ts.start_time DESC, r.reservation_id DESC
        LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin paid reservations page error: ' . $e->getMessage());
    $pageError = 'Riwayat reservasi gagal dimuat.';
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$timezone = new DateTimeZone('Asia/Jakarta');
$adminPageTitle = 'Reservations | Pemon Admin';
$adminPageScript = '../assets/js/admin-reservations.js';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <main class="lg:ml-72 px-4 py-8 sm:px-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Riwayat</p>
        <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">Reservasi Lunas</h1>
        <p class="mt-1 text-sm text-slate-500">Daftar reservasi aktif dengan status pembayaran lunas.</p>
      </div>
      <a href="dashboard.php" class="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Reservasi Hari Ini</a>
    </div>

    <section class="mt-6 rounded-2xl bg-white p-5 shadow-sm">
      <p class="text-xs font-semibold tracking-wide text-slate-500">TOTAL RESERVASI LUNAS</p>
      <p class="mt-1 text-3xl font-extrabold"><?= $total ?></p>
    </section>

    <?php if ($pageError !== ''): ?><p role="alert" class="mt-5 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>

    <section class="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm">
      <form method="get" action="reservations.php" class="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
        <label for="reservation-search" class="sr-only">Cari reservasi</label>
        <input id="reservation-search" name="q" type="search" value="<?= $escape($query) ?>" placeholder="Cari nama, telepon, layanan, atau ID..." class="min-w-[220px] flex-1 rounded-xl bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/20">
        <button type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Cari</button>
      </form>

      <div class="overflow-x-auto">
        <table class="w-full min-w-[900px] text-sm">
          <thead class="bg-indigo-50/60 text-[11px] uppercase tracking-wider text-slate-500">
            <tr>
              <th class="px-4 py-3 text-left font-semibold">Pelanggan</th>
              <th class="px-4 py-3 text-left font-semibold">Telepon</th>
              <th class="px-4 py-3 text-left font-semibold">Layanan</th>
              <th class="px-4 py-3 text-left font-semibold">Jadwal Reservasi</th>
              <th class="px-4 py-3 text-left font-semibold">Pembayaran</th>
              <th class="px-4 py-3 text-right font-semibold">Total</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php if ($reservations === []): ?>
              <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500"><?= $query === '' ? 'Belum ada reservasi lunas.' : 'Reservasi yang cocok tidak ditemukan.' ?></td></tr>
            <?php else: foreach ($reservations as $reservation): ?>
              <tr class="hover:bg-slate-50">
                <td class="px-4 py-4">
                  <p class="font-semibold text-slate-900"><?= $escape($reservation['full_name']) ?></p>
                  <p class="text-xs text-slate-500">Reservasi #<?= (int) $reservation['reservation_id'] ?></p>
                </td>
                <td class="px-4 py-4 font-mono text-xs text-slate-600"><?= $escape($reservation['phone_number']) ?></td>
                <td class="px-4 py-4 font-medium"><?= $escape($reservation['service_name']) ?></td>
                <td class="px-4 py-4">
                  <p><?= $escape((new DateTimeImmutable((string) $reservation['slot_date'], $timezone))->format('d/m/Y')) ?></p>
                  <p class="text-xs text-slate-500"><?= $escape(substr((string) $reservation['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $reservation['end_time'], 0, 5)) ?> WIB</p>
                </td>
                <td class="px-4 py-4">
                  <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-800">Lunas</span>
                  <p class="mt-1 text-xs text-slate-500"><?= $escape($reservation['payment_type']) ?></p>
                </td>
                <td class="px-4 py-4 text-right font-semibold">Rp <?= number_format((float) $reservation['total_payment'], 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-4 py-4 text-sm text-slate-500">
        <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
        <div class="flex gap-2">
          <?php if ($page > 1): ?><a href="?<?= $escape(http_build_query(['q' => $query, 'page' => $page - 1])) ?>" class="rounded-lg bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200">Sebelumnya</a><?php endif; ?>
          <?php if ($page < $totalPages): ?><a href="?<?= $escape(http_build_query(['q' => $query, 'page' => $page + 1])) ?>" class="rounded-lg bg-slate-100 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-200">Berikutnya</a><?php endif; ?>
        </div>
      </div>
    </section>
  </main>

<?php require __DIR__ . '/includes/footer.php'; ?>
