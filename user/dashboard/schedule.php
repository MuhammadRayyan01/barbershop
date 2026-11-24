<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/config/booking.php';

$timezone = new DateTimeZone('Asia/Jakarta');
$today = new DateTimeImmutable('today', $timezone);
$now = new DateTimeImmutable('now', $timezone);
$monthInput = $_GET['month'] ?? $today->format('Y-m');
$monthStart = DateTimeImmutable::createFromFormat('!Y-m', is_string($monthInput) ? $monthInput : '', $timezone);

if (!$monthStart || $monthStart->format('Y-m') !== $monthInput) {
    $monthStart = $today->modify('first day of this month');
}

// Public schedule should never navigate into a month that has already passed.
$currentMonthStart = $today->modify('first day of this month');
if ($monthStart < $currentMonthStart) {
    $monthStart = $currentMonthStart;
}

$monthEnd = $monthStart->modify('first day of next month');
$selectedInput = $_GET['date'] ?? '';
$selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', is_string($selectedInput) ? $selectedInput : '', $timezone);

if (!$selectedDate || $selectedDate->format('Y-m-d') !== $selectedInput || $selectedDate < $today || $selectedDate < $monthStart || $selectedDate >= $monthEnd) {
    $selectedDate = $today >= $monthStart && $today < $monthEnd ? $today : $monthStart;
}

$slotsByDate = [];
$pageError = '';

try {
    $generationDate = $monthStart < $today ? $today : $monthStart;
    while ($generationDate < $monthEnd) {
        ensureTimeSlotsForDate($pdo, $generationDate->format('Y-m-d'));
        $generationDate = $generationDate->modify('+1 day');
    }

    $stmt = $pdo->prepare("WITH hourly_slots AS (
            SELECT slot_date, MIN(slot_id)::integer AS slot_id,
                   MIN(start_time) AS start_time, MAX(end_time) AS end_time
            FROM time_slot
            WHERE slot_date >= CAST(:month_start AS date)
              AND slot_date < CAST(:month_end AS date)
              AND EXTRACT(MINUTE FROM start_time) = 0
              AND end_time = (start_time + INTERVAL '60 minutes')::time
            GROUP BY slot_date, start_time
        ), hour_settings AS (
            SELECT slot_date, EXTRACT(HOUR FROM start_time)::integer AS hour_num,
                   MIN(booking_capacity)::integer AS capacity,
                   BOOL_AND(is_available::text = 'available') AS is_available
            FROM time_slot
            WHERE slot_date >= CAST(:settings_start AS date)
              AND slot_date < CAST(:settings_end AS date)
            GROUP BY slot_date, EXTRACT(HOUR FROM start_time)
        ), reservation_counts AS (
            SELECT ts.slot_date, EXTRACT(HOUR FROM ts.start_time)::integer AS hour_num,
                   COUNT(*)::integer AS booked_count
            FROM reservation r
            INNER JOIN time_slot ts ON ts.slot_id = r.slot_id
            WHERE r.reservation_status = 'active'
              AND ts.slot_date >= CAST(:reservation_start AS date)
              AND ts.slot_date < CAST(:reservation_end AS date)
            GROUP BY ts.slot_date, EXTRACT(HOUR FROM ts.start_time)
        ), hold_counts AS (
            SELECT ts.slot_date, EXTRACT(HOUR FROM ts.start_time)::integer AS hour_num,
                   COUNT(*)::integer AS held_count
            FROM booking_hold bh
            INNER JOIN time_slot ts ON ts.slot_id = bh.slot_id
            WHERE bh.hold_status = 'active'
              AND bh.expires_at > CURRENT_TIMESTAMP
              AND ts.slot_date >= CAST(:hold_start AS date)
              AND ts.slot_date < CAST(:hold_end AS date)
            GROUP BY ts.slot_date, EXTRACT(HOUR FROM ts.start_time)
        )
        SELECT hs.slot_id, hs.slot_date, hs.start_time, hs.end_time,
               CASE WHEN cfg.is_available THEN 'available' ELSE 'not available' END AS is_available,
               cfg.capacity,
               (COALESCE(rc.booked_count, 0) + COALESCE(hc.held_count, 0))::integer AS booked_count
        FROM hourly_slots hs
        INNER JOIN hour_settings cfg
            ON cfg.slot_date = hs.slot_date
           AND cfg.hour_num = EXTRACT(HOUR FROM hs.start_time)::integer
        LEFT JOIN reservation_counts rc
            ON rc.slot_date = hs.slot_date
           AND rc.hour_num = cfg.hour_num
        LEFT JOIN hold_counts hc
            ON hc.slot_date = hs.slot_date
           AND hc.hour_num = cfg.hour_num
        ORDER BY hs.slot_date, hs.start_time");
    $stmt->execute([
        'month_start' => $monthStart->format('Y-m-d'),
        'month_end' => $monthEnd->format('Y-m-d'),
        'settings_start' => $monthStart->format('Y-m-d'),
        'settings_end' => $monthEnd->format('Y-m-d'),
        'reservation_start' => $monthStart->format('Y-m-d'),
        'reservation_end' => $monthEnd->format('Y-m-d'),
        'hold_start' => $monthStart->format('Y-m-d'),
        'hold_end' => $monthEnd->format('Y-m-d'),
    ]);

    $now = new DateTimeImmutable('now', $timezone);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $date = (new DateTimeImmutable((string) $slot['slot_date'], $timezone))->format('Y-m-d');
        $slot['capacity'] = (int) $slot['capacity'];
        $slot['booked_count'] = (int) $slot['booked_count'];
        $slot['is_future'] = new DateTimeImmutable(
            $date . ' ' . $slot['start_time'],
            $timezone
        ) > $now;
        $slotsByDate[$date][] = $slot;
    }
} catch (PDOException $e) {
    error_log('Public schedule page error: ' . $e->getMessage());
    $pageError = 'Ketersediaan jadwal gagal dimuat. Silakan coba lagi.';
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$slots = $slotsByDate[$selectedDate->format('Y-m-d')] ?? [];
$monthLabel = $monthStart->format('F Y');
$monthLabel = strtr($monthLabel, [
    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
    'April' => 'April', 'May' => 'Mei', 'June' => 'Juni',
    'July' => 'Juli', 'August' => 'Agustus', 'September' => 'September',
    'October' => 'Oktober', 'November' => 'November', 'December' => 'Desember',
]);
$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$weekdayLabels = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
$weekdayNames = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$selectedDateLabel = $weekdayNames[(int) $selectedDate->format('N') - 1]
    . ', ' . $selectedDate->format('d') . ' ' . $monthNames[(int) $selectedDate->format('n')]
    . ' ' . $selectedDate->format('Y');
$availableTimeCount = static function (array $daySlots): int {
    return count(array_filter($daySlots, static fn(array $slot): bool =>
        $slot['is_future']
        &&
        $slot['is_available'] === 'available'
        && (int) $slot['booked_count'] < (int) $slot['capacity']
    ));
};

// Page-specific settings used by includes/header.php and includes/footer.php
$userPageTitle = 'Jadwal Reservasi | Pemon Barbershop';
$userActivePage = 'schedule';
$userAssetPrefix = '../../';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';

require_once __DIR__ . '/../../includes/header.php';
?>
  <main class="mx-auto w-full max-w-6xl py-6">
    <div class="mb-6">
      <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Pemon Barbershop</p>
      <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">Ketersediaan Reservasi</h1>
      <p class="mt-2 text-sm text-gray-600">Lihat jam yang masih memiliki kapasitas barber. Jumlah mencakup reservasi aktif dan pembayaran yang sedang diproses.</p>
    </div>

    <?php if ($pageError !== ''): ?>
      <p role="alert" class="mb-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p>
    <?php endif; ?>

    <section class="grid overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm lg:grid-cols-[1.15fr_0.85fr]">
      <div class="bg-[#fafafa] p-5 sm:p-8">
        <div class="mb-6 flex items-center justify-between gap-3">
          <?php if ($monthStart > $currentMonthStart): ?>
            <a href="?month=<?= $escape($previousMonth) ?>" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:bg-gray-50" aria-label="Bulan sebelumnya">&larr;</a>
          <?php else: ?>
            <span class="cursor-not-allowed rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-sm text-gray-300" aria-disabled="true" aria-label="Tidak dapat melihat bulan sebelumnya">&larr;</span>
          <?php endif; ?>
          <h2 class="text-lg font-bold"><?= $escape($monthLabel) ?></h2>
          <a href="?month=<?= $escape($nextMonth) ?>" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:bg-gray-50" aria-label="Bulan berikutnya">&rarr;</a>
          <a href="?month=<?= $today->format('Y-m') ?>&amp;date=<?= $today->format('Y-m-d') ?>" class="ml-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold hover:bg-gray-50">Hari ini</a>
        </div>

        <div class="mb-3 grid grid-cols-7 gap-2 text-center text-xs font-semibold text-gray-500">
          <?php foreach ($weekdayLabels as $label): ?><span><?= $label ?></span><?php endforeach; ?>
        </div>
        <div class="grid grid-cols-7 gap-2">
          <?php for ($blank = 1; $blank < (int) $monthStart->format('N'); $blank++): ?>
            <span aria-hidden="true" class="min-h-16 rounded-xl"></span>
          <?php endfor; ?>
          <?php for ($day = 1; $day <= (int) $monthStart->format('t'); $day++):
              $cellDate = $monthStart->setDate((int) $monthStart->format('Y'), (int) $monthStart->format('m'), $day);
              $dateKey = $cellDate->format('Y-m-d');
              $daySlots = $slotsByDate[$dateKey] ?? [];
              $availableTimes = $availableTimeCount($daySlots);
              $dayStatus = $pageError !== ''
                  ? 'Gagal memuat data'
                  : ($daySlots === []
                      ? ($dateKey === $today->format('Y-m-d') ? 'Tidak ada waktu tersisa' : 'Belum tersedia')
                      : ($availableTimes > 0 ? $availableTimes . ' waktu tersedia' : 'Tidak tersedia'));
              $isSelected = $dateKey === $selectedDate->format('Y-m-d');
              $isToday = $dateKey === $today->format('Y-m-d');
              $isPast = $cellDate < $today;
              if ($isPast) {
                  $dayStatus = 'Sudah lewat';
              }
          ?>
            <?php if ($isPast): ?>
              <div aria-label="<?= $day ?> <?= $escape($monthLabel) ?>, tanggal sudah lewat" aria-disabled="true"
                   class="flex min-h-16 cursor-not-allowed flex-col rounded-xl border border-gray-100 bg-gray-100 p-2 text-left text-gray-400 opacity-60">
                <span class="text-sm font-medium"><?= $day ?></span>
                <span class="mt-auto text-[10px] font-semibold">Sudah lewat</span>
              </div>
            <?php else: ?>
              <a href="?month=<?= $escape($monthStart->format('Y-m')) ?>&amp;date=<?= $escape($dateKey) ?>"
                 aria-label="<?= $day ?> <?= $escape($monthLabel) ?>, <?= $escape($dayStatus) ?>"
                 aria-current="<?= $isSelected ? 'date' : 'false' ?>"
                 class="flex min-h-16 flex-col rounded-xl border p-2 text-left transition hover:border-[#0d3b36] <?= $isSelected ? 'border-[#0d3b36] bg-[#e8f3ee]' : 'border-gray-100 bg-white' ?>">
              <span class="text-sm <?= $isToday ? 'font-extrabold text-[#0d3b36]' : 'font-medium text-gray-800' ?>"><?= $day ?></span>
              <span class="mt-auto text-[10px] font-semibold <?= $availableTimes > 0 ? 'text-[#0d3b36]' : 'text-gray-400' ?>"><?= $escape($dayStatus) ?></span>
              </a>
            <?php endif; ?>
          <?php endfor; ?>
        </div>
        <p class="mt-5 text-xs text-gray-500">Jadwal tersedia setiap satu jam. Kapasitas mengikuti jumlah barber yang bertugas.</p>
      </div>

      <div class="p-5 sm:p-8">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Pilihan waktu</p>
        <h2 class="mt-1 text-lg font-bold"><?= $escape($selectedDateLabel) ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?= $pageError === '' ? $availableTimeCount($slots) . ' pilihan waktu masih tersedia' : 'Ketersediaan belum dapat dipastikan' ?></p>

        <?php if ($pageError !== ''): ?>
          <div class="mt-6 rounded-xl border border-red-100 bg-red-50 p-5 text-sm text-red-700">
            Jadwal tidak dapat ditampilkan saat ini. Silakan muat ulang halaman beberapa saat lagi.
          </div>
        <?php elseif ($slots === []): ?>
          <div class="mt-6 rounded-xl border border-dashed border-gray-200 bg-gray-50 p-5 text-sm text-gray-600">
            Tidak ada slot reservasi yang akan datang pada tanggal ini.
          </div>
        <?php else: ?>
          <div class="mt-5 space-y-3">
            <?php foreach ($slots as $slot):
                $bookedCount = (int) $slot['booked_count'];
                $slotCapacity = (int) $slot['capacity'];
                $remainingCapacity = max(0, $slotCapacity - $bookedCount);
                $slotOpen = $slot['is_available'] === 'available';
                $slotHasSpace = $slot['is_future'] && $slotOpen && $remainingCapacity > 0;
                $slotStatus = !$slot['is_future'] ? 'Waktu lewat' : (!$slotOpen ? 'Ditutup admin' : ($remainingCapacity === 0 ? 'Penuh' : $remainingCapacity . ' dari ' . $slotCapacity . ' barber tersedia'));
                $statusClass = !$slotOpen
                    ? 'bg-rose-50 text-rose-700'
                    : (!$slot['is_future']
                        ? 'bg-gray-100 text-gray-600'
                        : ($remainingCapacity === 0
                            ? 'bg-amber-50 text-amber-800'
                            : 'bg-emerald-50 text-emerald-800'));
            ?>
              <article class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <h3 class="font-bold"><?= $escape(substr((string) $slot['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $slot['end_time'], 0, 5)) ?></h3>
                    <p class="mt-1 text-sm text-gray-600">
                      <?= $bookedCount ?> / <?= $slotCapacity ?> kapasitas terpakai
                    </p>
                  </div>
                  <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $statusClass ?>">
                    <?= $escape($slotStatus) ?>
                  </span>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($availableTimeCount($slots) > 0): ?>
            <a href="../booking/booking.php?date=<?= $escape($selectedDate->format('Y-m-d')) ?>" class="mt-5 inline-flex w-full justify-center rounded-xl bg-[#0d3b36] px-4 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Buat Reservasi</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
