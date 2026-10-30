<?php require_once __DIR__ . '/../backend/middleware/require_admin_page.php'; ?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Refunds &amp; Invoices | Pemon Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
  <style>
    body { font-family: 'Plus Jakarta Sans', sans-serif; }
    @media print { #admin-sidebar, #admin-topbar, .no-print { display: none !important; } main { margin: 0 !important; } }
  </style>
</head>
<body class="bg-[#f8f8fd] text-slate-900">

  <div id="admin-sidebar"></div>
  <div id="admin-topbar"></div>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold">Kelola Refund &amp; Invoices</h1>
        <p class="text-sm text-slate-500 mt-1">Daftar pembatalan reservasi, permohonan pengembalian dana klien, dan status riwayat faktur invoice.</p>
      </div>
      <div class="flex gap-2 no-print">
        <button id="btn-csv" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 text-sm font-semibold hover:bg-indigo-100"><span class="material-symbols-outlined text-[18px]">download</span>Ekspor CSV</button>
        <button id="btn-print" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-50 text-sm font-semibold hover:bg-indigo-100"><span class="material-symbols-outlined text-[18px]">print</span>Cetak Rekap</button>
      </div>
    </div>

    <!-- Kartu ringkasan -->
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
      <div class="bg-white rounded-2xl p-5 shadow-sm">
        <p class="text-[11px] font-semibold tracking-wider text-slate-500">TOTAL PEMBATALAN</p>
        <p id="stat-total" class="text-3xl font-extrabold mt-1">0</p>
      </div>
      <div class="bg-white rounded-2xl p-5 shadow-sm">
        <p class="text-[11px] font-semibold tracking-wider text-slate-500">REFUND TELAH DIPROSES</p>
        <p id="stat-done" class="text-3xl font-extrabold mt-1 text-[#0d3b36]">Rp 0</p>
      </div>
      <div class="bg-white rounded-2xl p-5 shadow-sm">
        <p class="text-[11px] font-semibold tracking-wider text-slate-500">MENUNGGU KONFIRMASI</p>
        <p id="stat-wait" class="text-3xl font-extrabold mt-1">0 Antrean</p>
      </div>
    </section>

    <!-- Tabel -->
    <section class="bg-white rounded-2xl shadow-sm mt-6 overflow-hidden">
      <div class="flex flex-wrap items-center gap-3 p-4 no-print">
        <div class="flex items-center gap-2 bg-[#f8f8fd] rounded-xl px-4 py-2.5 flex-1 min-w-[240px]">
          <span class="material-symbols-outlined text-[18px] text-slate-500">search</span>
          <input id="search" type="text" placeholder="Cari nama klien, nomor transaksi, atau layanan..." class="w-full bg-transparent outline-none text-sm">
        </div>
        <select id="status-filter" class="bg-[#f8f8fd] rounded-xl px-4 py-2.5 text-sm font-semibold outline-none">
          <option value="all">Semua Status</option>
          <option value="waiting">Menunggu Refund</option>
          <option value="refunded">Selesai Refund</option>
          <option value="void">Invoice Dibatalkan</option>
        </select>
        <button class="inline-flex items-center gap-2 bg-[#f8f8fd] rounded-xl px-4 py-2.5 text-sm font-semibold"><span class="material-symbols-outlined text-[18px] text-slate-500">calendar_month</span>Bulan Ini</button>
      </div>

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
          <tbody id="ref-body" class="divide-y divide-slate-100"></tbody>
        </table>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-4 text-xs text-slate-500 no-print">
        <span id="range-label"></span>
        <!-- PHP nanti: pagination pakai ?page=N -->
        <div class="flex items-center gap-1">
          <button class="w-8 h-8 rounded-lg bg-[#0d3b36] text-white font-semibold">1</button>
          <button class="w-8 h-8 rounded-lg hover:bg-slate-100">2</button>
          <button class="px-3 py-1.5 rounded-lg hover:bg-slate-100">Berikutnya</button>
        </div>
      </div>
    </section>
  </main>

  <!-- Modal invoice -->
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

  <script src="../assets/js/admin.js"></script>
  <script src="../assets/js/refunds.js"></script>
</body>
</html>
