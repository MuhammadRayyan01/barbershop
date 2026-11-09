<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/config/booking.php';

$timezone = new DateTimeZone('Asia/Jakarta');
$today = new DateTimeImmutable('today', $timezone);
$monthInput = $_GET['month'] ?? $today->format('Y-m');
$monthStart = DateTimeImmutable::createFromFormat('!Y-m', is_string($monthInput) ? $monthInput : '', $timezone);

if (!$monthStart || $monthStart->format('Y-m') !== $monthInput) {
    $monthStart = $today->modify('first day of this month');
}

$monthEnd = $monthStart->modify('first day of next month');
$selectedInput = $_GET['date'] ?? '';
$selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', is_string($selectedInput) ? $selectedInput : '', $timezone);

if (!$selectedDate || $selectedDate->format('Y-m-d') !== $selectedInput || $selectedDate < $monthStart || $selectedDate >= $monthEnd) {
    $selectedDate = $today >= $monthStart && $today < $monthEnd ? $today : $monthStart;
}

$slotsByDate = [];
$pageError = '';

try {
    $stmt = $pdo->prepare("SELECT ts.slot_id, ts.slot_date, ts.start_time, ts.end_time,
            ts.is_available,
            COUNT(r.reservation_id) FILTER (WHERE r.reservation_status = 'active') AS booked_count
        FROM time_slot ts
        LEFT JOIN reservation r ON r.slot_id = ts.slot_id
        WHERE ts.slot_date >= CAST(:month_start AS date)
          AND ts.slot_date < CAST(:month_end AS date)
        GROUP BY ts.slot_id
        ORDER BY ts.slot_date, ts.start_time");
    $stmt->execute([
        'month_start' => $monthStart->format('Y-m-d'),
        'month_end' => $monthEnd->format('Y-m-d'),
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $slot) {
        $date = (new DateTimeImmutable((string) $slot['slot_date'], $timezone))->format('Y-m-d');
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
        $slot['is_available'] === 'available'
        && (int) $slot['booked_count'] < BOOKING_SLOT_CAPACITY
    ));
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Jadwal Reservasi | Pemon Barbershop</title>
  <link rel="stylesheet" href="../../assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6">
  <header class="mx-auto flex w-full max-w-6xl items-center justify-between py-4">
    <a href="index.html" class="flex items-center gap-3" aria-label="Dashboard Pemon Barbershop">
      <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-[#0d3b36] text-xl font-bold text-white">P</span>
      <span class="font-bold tracking-wide">PEMON <span class="block text-xs font-normal text-gray-500">BARBERSHOP</span></span>
    </a>
    <nav class="flex gap-5 text-sm font-medium" aria-label="Navigasi utama">
      <a href="index.html" class="text-gray-600 hover:text-[#0d3b36]">Dashboard</a>
      <a href="schedule.php" aria-current="page" class="font-semibold text-[#0d3b36]">Jadwal</a>
      <a href="aboutUs.html" class="text-gray-600 hover:text-[#0d3b36]">Tentang</a>
    </nav>
    <a href="../booking/booking.html" class="rounded-xl bg-[#0d3b36] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#082724]">Buat Reservasi</a>
  </header>

  <main class="mx-auto w-full max-w-6xl py-6">
    <div class="mb-6">
      <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Pemon Barbershop</p>
      <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">Ketersediaan Reservasi</h1>
      <p class="mt-2 text-sm text-gray-600">Lihat jam yang masih memiliki kursi tersedia. Jumlah mencakup reservasi aktif semua pelanggan.</p>
    </div>

    <?php if ($pageError !== ''): ?>
      <p role="alert" class="mb-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p>
    <?php endif; ?>

    <section class="grid overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm lg:grid-cols-[1.15fr_0.85fr]">
      <div class="bg-[#fafafa] p-5 sm:p-8">
        <div class="mb-6 flex items-center justify-between gap-3">
          <a href="?month=<?= $escape($previousMonth) ?>" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm hover:bg-gray-50" aria-label="Bulan sebelumnya">&larr;</a>
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
              $dayStatus = $daySlots === []
                  ? 'Jadwal belum diatur'
                  : ($availableTimes > 0 ? $availableTimes . ' jam tersedia' : 'Penuh');
              $isSelected = $dateKey === $selectedDate->format('Y-m-d');
              $isToday = $dateKey === $today->format('Y-m-d');
          ?>
            <a href="?month=<?= $escape($monthStart->format('Y-m')) ?>&amp;date=<?= $escape($dateKey) ?>"
               aria-label="<?= $day ?> <?= $escape($monthLabel) ?>, <?= $escape($dayStatus) ?>"
               aria-current="<?= $isSelected ? 'date' : 'false' ?>"
               class="flex min-h-16 flex-col rounded-xl border p-2 text-left transition hover:border-[#0d3b36] <?= $isSelected ? 'border-[#0d3b36] bg-[#e8f3ee]' : 'border-gray-100 bg-white' ?>">
              <span class="text-sm <?= $isToday ? 'font-extrabold text-[#0d3b36]' : 'font-medium text-gray-800' ?>"><?= $day ?></span>
              <span class="mt-auto text-[10px] font-semibold <?= $availableTimes > 0 ? 'text-[#0d3b36]' : 'text-gray-400' ?>"><?= $escape($dayStatus) ?></span>
            </a>
          <?php endfor; ?>
        </div>
        <p class="mt-5 text-xs text-gray-500">Jumlah jam tersedia dihitung dari kapasitas kursi pada setiap slot.</p>
      </div>

      <div class="p-5 sm:p-8">
        <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Ketersediaan per jam</p>
        <h2 class="mt-1 text-lg font-bold"><?= $escape($selectedDateLabel) ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?= $availableTimeCount($slots) ?> jam masih tersedia</p>

        <?php if ($slots === []): ?>
          <div class="mt-6 rounded-xl border border-dashed border-gray-200 bg-gray-50 p-5 text-sm text-gray-600">
            Jadwal untuk tanggal ini belum tersedia.
          </div>
        <?php else: ?>
          <div class="mt-5 space-y-3">
            <?php foreach ($slots as $slot):
                $bookedCount = (int) $slot['booked_count'];
                $remainingCapacity = max(0, BOOKING_SLOT_CAPACITY - $bookedCount);
                $slotOpen = $slot['is_available'] === 'available';
                $slotHasSpace = $slotOpen && $remainingCapacity > 0;
                $slotStatus = !$slotOpen ? 'Ditutup admin' : ($remainingCapacity === 0 ? 'Penuh' : $remainingCapacity . ' dari ' . BOOKING_SLOT_CAPACITY . ' kursi tersedia');
            ?>
              <article class="rounded-xl border border-gray-100 bg-white p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <h3 class="font-bold"><?= $escape(substr((string) $slot['start_time'], 0, 5)) ?>–<?= $escape(substr((string) $slot['end_time'], 0, 5)) ?></h3>
                    <p class="mt-1 text-sm text-gray-600">
                      <?= $bookedCount ?> / <?= BOOKING_SLOT_CAPACITY ?> kursi terisi
                    </p>
                  </div>
                  <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $slotHasSpace ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600' ?>">
                    <?= $escape($slotStatus) ?>
                  </span>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
          <?php if ($availableTimeCount($slots) > 0): ?>
            <a href="../booking/booking.html" class="mt-5 inline-flex w-full justify-center rounded-xl bg-[#0d3b36] px-4 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Buat Reservasi</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="mx-auto flex w-full max-w-6xl justify-between py-5 text-xs text-gray-400">
    <span>© 2026 Pemon Barbershop</span>
    <span>Waktu Indonesia Barat (WIB)</span>
  </footer>
</body>
</html>
