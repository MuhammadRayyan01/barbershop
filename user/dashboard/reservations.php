<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/middleware/require_customer.php';

$userPageTitle = 'Reservasi Saya | Pemon Barbershop';
$userActivePage = 'reservations';
$userAssetPrefix = '../../';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';
$userPageScripts = ['../../assets/js/customer-reservations.js'];
require_once __DIR__ . '/../../includes/header.php';
?>
<main class="mx-auto w-full max-w-5xl flex-1 py-6">
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-widest text-gray-500">Akun Saya</p>
      <h1 class="mt-1 text-2xl font-extrabold sm:text-3xl">Reservasi Saya</h1>
      <p class="mt-2 text-sm text-gray-600">Lihat semua jadwal mendatang dan riwayat reservasi Anda.</p>
    </div>
    <a href="../booking/booking.php" class="inline-flex rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Buat reservasi</a>
  </div>

  <form id="reservation-search-form" class="mb-6 flex flex-wrap gap-2 rounded-2xl border border-gray-100 bg-white p-3 shadow-sm">
    <label class="sr-only" for="reservation-search">Cari layanan atau kode reservasi</label>
    <input id="reservation-search" name="q" type="search" value="" placeholder="Cari layanan atau kode reservasi..." class="min-w-0 flex-1 rounded-xl bg-[#f8fbf9] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/20">
    <button type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-3 text-sm font-semibold text-white hover:bg-[#082724]">Cari</button>
    <button id="reservation-search-reset" type="button" class="hidden rounded-xl border border-gray-200 px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50">Reset</button>
  </form>

  <div id="reservation-page-error" class="hidden mb-5 rounded-xl border border-red-100 bg-red-50 p-4 text-sm text-red-700" role="alert"></div>
  <p id="reservation-loading" class="rounded-2xl border border-gray-100 bg-white px-5 py-8 text-center text-sm text-gray-500">Memuat data reservasi...</p>
  <div id="reservation-sections" class="hidden">
    <section class="mb-6 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" aria-label="Reservasi mendatang">
      <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
        <h2 class="font-bold">Mendatang</h2>
        <span id="upcoming-count" class="rounded-full bg-[#e8f3ee] px-3 py-1 text-xs font-semibold text-[#0d3b36]">0 reservasi</span>
      </div>
      <div id="upcoming-list" class="divide-y divide-gray-100"></div>
    </section>
    <section class="mb-6 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm" aria-label="Riwayat reservasi">
      <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
        <h2 class="font-bold">Riwayat</h2>
        <span id="history-count" class="rounded-full bg-[#e8f3ee] px-3 py-1 text-xs font-semibold text-[#0d3b36]">0 reservasi</span>
      </div>
      <div id="history-list" class="divide-y divide-gray-100"></div>
    </section>
  </div>
</main>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
