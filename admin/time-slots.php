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
    $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
    $postedDate = $_POST['date'] ?? $date;
    if (!is_string($postedDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $postedDate)) $postedDate = $date;
    try {
        if (!$slotId || $slotId < 1 || $capacity === false || $capacity < 0 || $capacity > BOOKING_SLOT_CAPACITY) {
            throw new InvalidArgumentException('Kapasitas slot tidak valid.');
        }
        $pdo->beginTransaction();
        $slotInfoStmt = $pdo->prepare("SELECT slot_date::text AS slot_date, start_time::text AS start_time
            FROM time_slot WHERE slot_id = :id AND slot_date = CAST(:date AS date)");
        $slotInfoStmt->execute(['id' => $slotId, 'date' => $postedDate]);
        $slotInfo = $slotInfoStmt->fetch(PDO::FETCH_ASSOC);
        if (!$slotInfo) throw new InvalidArgumentException('Slot untuk tanggal tersebut tidak ditemukan.');

        lockBookingHour($pdo, $slotInfo['slot_date'], $slotInfo['start_time']);
        expireBookingHolds($pdo);
        $usage = getBookingHourCapacity($pdo, $slotInfo['slot_date'], $slotInfo['start_time']);
        if ($capacity < $usage['used_count']) {
            throw new InvalidArgumentException('Kapasitas tidak bisa lebih rendah dari reservasi dan pembayaran yang sedang diproses (' . $usage['used_count'] . ').');
        }

        $stmt = $pdo->prepare("UPDATE time_slot
            SET booking_capacity = :capacity,
                is_available = CASE
                    WHEN :capacity_status > 0 THEN 'available'::slot_status
                    ELSE 'not available'::slot_status
                END
            WHERE slot_date = CAST(:date_update AS date)
              AND date_trunc('hour', DATE '2000-01-01' + start_time) = (
                  SELECT date_trunc('hour', DATE '2000-01-01' + start_time)
                  FROM time_slot
                  WHERE slot_id = :id AND slot_date = CAST(:date_lookup AS date)
              )
            RETURNING slot_id");
        $stmt->execute([
            'capacity' => $capacity,
            'capacity_status' => $capacity,
            'id' => $slotId,
            'date_update' => $postedDate,
            'date_lookup' => $postedDate,
        ]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Slot untuk tanggal tersebut tidak ditemukan.');
        $pdo->commit();
        $_SESSION['admin_flash'] = 'Kapasitas barber pada jam tersebut berhasil diperbarui.';
        header('Location: time-slots.php?date=' . urlencode($postedDate));
        exit;
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pageError = $e->getMessage();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Admin slot update error: ' . $e->getMessage());
        $pageError = 'Kapasitas slot gagal disimpan.';
    }
}

try {
    if ($date >= $today->format('Y-m-d')) {
        ensureTimeSlotsForDate($pdo, $date);
    }

<<<<<<< HEAD
    $stmt = $pdo->prepare("WITH slot_hours AS (
        SELECT
            ts.slot_date,
            EXTRACT(HOUR FROM ts.start_time)::integer AS hour_num,
            MIN(ts.slot_id)::integer AS slot_id,
            MIN(ts.booking_capacity)::integer AS booking_capacity,
            BOOL_AND(ts.is_available::text = 'available') AS is_available
        FROM time_slot ts
        WHERE ts.slot_date = CAST(:date_hours AS date)
        GROUP BY ts.slot_date, EXTRACT(HOUR FROM ts.start_time)
    ), reservation_counts AS (
        SELECT ts.slot_date, EXTRACT(HOUR FROM ts.start_time)::integer AS hour_num,
               COUNT(*)::integer AS booked_count
        FROM reservation r
        INNER JOIN time_slot ts ON ts.slot_id = r.slot_id
        WHERE r.reservation_status = 'active'
          AND ts.slot_date = CAST(:date_reservations AS date)
        GROUP BY ts.slot_date, EXTRACT(HOUR FROM ts.start_time)
    ), hold_counts AS (
        SELECT ts.slot_date, EXTRACT(HOUR FROM ts.start_time)::integer AS hour_num,
               COUNT(*)::integer AS held_count
        FROM booking_hold bh
        INNER JOIN time_slot ts ON ts.slot_id = bh.slot_id
        WHERE bh.hold_status = 'active'
          AND bh.expires_at > CURRENT_TIMESTAMP
          AND ts.slot_date = CAST(:date_holds AS date)
        GROUP BY ts.slot_date, EXTRACT(HOUR FROM ts.start_time)
    )
    SELECT
        sh.slot_id,
        sh.slot_date,
        make_time(sh.hour_num, 0, 0) AS start_time,
        make_time(sh.hour_num + 1, 0, 0) AS end_time,
        sh.booking_capacity,
        sh.is_available,
        (COALESCE(rc.booked_count, 0) + COALESCE(hc.held_count, 0))::integer AS booked_count
    FROM slot_hours sh
    LEFT JOIN reservation_counts rc ON rc.slot_date = sh.slot_date AND rc.hour_num = sh.hour_num
    LEFT JOIN hold_counts hc ON hc.slot_date = sh.slot_date AND hc.hour_num = sh.hour_num
    ORDER BY sh.hour_num");
    $stmt->execute([
        'date_hours' => $date,
        'date_reservations' => $date,
        'date_holds' => $date,
    ]);
=======
    $stmt = $pdo->prepare("SELECT ts.slot_id, ts.slot_date, ts.start_time, ts.end_time, ts.is_available, (SELECT COUNT(*) FROM reservation r JOIN time_slot booked_slot ON booked_slot.slot_id = r.slot_id WHERE booked_slot.slot_date = ts.slot_date AND EXTRACT(HOUR FROM booked_slot.start_time) = EXTRACT(HOUR FROM ts.start_time) AND r.reservation_status = 'active') AS booked_count FROM time_slot ts WHERE ts.slot_date = CAST(:date AS date) AND EXTRACT(MINUTE FROM ts.start_time) = 0 AND ts.end_time = (ts.start_time + INTERVAL '1 hour')::time ORDER BY ts.start_time");
    $stmt->execute(['date' => $date]);
>>>>>>> ed543f9 (feat: switch booking to hourly time slots)
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin slot list error: ' . $e->getMessage());
    $slots = [];
    $pageError = 'Data slot gagal dimuat.';
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$adminPageTitle = 'Time Slots | Pemon Admin';
$adminPageScript = '../assets/js/admin-slots.js';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold">Date Time Slot</h1>
<<<<<<< HEAD
        <p class="text-sm text-slate-500 mt-1">Sesuaikan kapasitas per jam dengan jumlah barber yang bertugas.</p>
=======
        <p class="text-sm text-slate-500 mt-1">Slot 1 jam dari pukul 10.00 hingga 22.00 dibuat otomatis untuk tanggal yang dipilih.</p>
>>>>>>> ed543f9 (feat: switch booking to hourly time slots)
      </div>
      <form method="get" action="time-slots.php" class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm text-sm font-semibold">
        <span class="material-symbols-outlined text-slate-500">calendar_month</span><label for="slot-date">Tanggal</label>
        <input id="slot-date" name="date" type="date" value="<?= $escape($date) ?>" class="font-medium outline-none">
        <button type="submit" class="rounded-lg bg-indigo-50 px-3 py-2">Tampilkan</button>
      </form>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3 text-xs text-slate-600">
      <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-full bg-white border border-emerald-700"></i>Kapasitas tersedia</span>
      <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded bg-slate-300"></i>Ditutup (0 barber)</span>
      <span class="ml-auto inline-flex items-center gap-2 text-slate-500">Kapasitas dapat diatur per jam, maksimal <?= BOOKING_SLOT_CAPACITY ?> reservasi.</span>
    </div>

    <?php if ($pageError !== ''): ?><p class="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>
    <?php if ($flashMessage !== ''): ?><p class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= $escape($flashMessage) ?></p><?php endif; ?>
    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[700px] text-sm">
          <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-700">
            <tr>
              <th class="border-b border-r border-slate-200 px-5 py-3 text-center">Jam</th>
              <th class="border-b border-r border-slate-200 px-5 py-3 text-center">Terpakai / Kapasitas</th>
              <th class="border-b border-slate-200 px-5 py-3 text-center">Kapasitas Barber</th>
            </tr>
          </thead>
          <tbody id="slot-rows" class="divide-y divide-slate-100">
            <?php if ($slots === []): ?>
              <tr><td colspan="3" class="px-5 py-10 text-center text-slate-500">Belum ada slot pada tanggal ini.</td></tr>
            <?php else: foreach ($slots as $slot):
              $booked = (int) $slot['booked_count'];
              $capacity = (int) $slot['booking_capacity'];
              $available = $slot['is_available'] === true || $slot['is_available'] === 't' || $slot['is_available'] === '1';
              $full = $capacity > 0 && $booked >= $capacity;
            ?>
              <tr>
                <th scope="row" class="border-r border-slate-200 px-5 py-3 text-center font-semibold"><span class="inline-flex min-w-[110px] justify-center rounded-lg border border-[#0d3b36] bg-slate-50 px-3 py-2"><?= $escape(substr((string) $slot['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $slot['end_time'], 0, 5)) ?></span></th>
                <td class="border-r border-slate-100 px-4 py-2 text-center"><?= $booked ?> / <?= $capacity ?></td>
                <td class="px-4 py-2 text-center">
                  <form method="post" action="time-slots.php" class="inline-flex items-center justify-center gap-2">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="slot_id" value="<?= (int) $slot['slot_id'] ?>">
                    <input type="hidden" name="date" value="<?= $escape($date) ?>">
                    <label class="sr-only" for="capacity-<?= (int) $slot['slot_id'] ?>">Kapasitas barber pukul <?= $escape(substr((string) $slot['start_time'], 0, 5)) ?></label>
                    <select id="capacity-<?= (int) $slot['slot_id'] ?>" name="capacity" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold">
                      <?php for ($capacityOption = 0; $capacityOption <= BOOKING_SLOT_CAPACITY; $capacityOption++): ?>
                        <option value="<?= $capacityOption ?>" <?= $capacityOption === $capacity ? 'selected' : '' ?>><?= $capacityOption === 0 ? 'Tutup (0)' : $capacityOption . ' tersedia' ?></option>
                      <?php endfor; ?>
                    </select>
                    <button type="submit" class="rounded-lg bg-[#0d3b36] px-4 py-2 text-xs font-semibold text-white hover:bg-[#092d29]">Simpan</button>
                  </form>
                  <p class="mt-1 text-xs font-semibold <?= $capacity === 0 || !$available ? 'text-slate-500' : ($full ? 'text-amber-600' : 'text-emerald-700') ?>"><?= $capacity === 0 || !$available ? 'Ditutup' : ($full ? 'Penuh' : 'Aktif') ?></p>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-xs text-slate-500">Nilai 1–3 membatasi reservasi sesuai barber yang bertugas. Pilih 0 untuk menutup jam. Kapasitas tidak dapat dikurangi di bawah reservasi atau pembayaran yang sedang diproses.</p>
    </div>
  </main>

<?php require __DIR__ . '/includes/footer.php'; ?>
