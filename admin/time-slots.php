<?php require_once __DIR__ . '/../backend/middleware/require_admin_page.php'; ?>
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
  <div id="admin-sidebar"></div>
  <div id="admin-topbar"></div>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold">Date Time Slot</h1>
        <p class="text-sm text-slate-500 mt-1">Atur ketersediaan kursi barber untuk setiap waktu.</p>
      </div>
      <label class="flex items-center gap-3 rounded-xl bg-white px-4 py-3 shadow-sm text-sm font-semibold">
        <span class="material-symbols-outlined text-slate-500">calendar_month</span>
        <span>Tanggal</span>
        <input id="slot-date" type="date" class="font-medium outline-none">
      </label>
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-3 text-xs text-slate-600">
      <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded-full bg-white border border-emerald-700"></i>Tersedia (Buka)</span>
      <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded bg-[#0d3b36]"></i>Penuh</span>
      <span class="inline-flex items-center gap-2"><i class="h-3 w-3 rounded bg-slate-300"></i>Ditutup</span>
      <span class="ml-auto inline-flex items-center gap-2 text-slate-500"><span class="material-symbols-outlined text-[16px]">touch_app</span>Klik slot untuk mengubah status</span>
    </div>

    <div id="slot-alert" class="hidden mt-5 rounded-xl px-4 py-3 text-sm"></div>
    <section class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[700px] text-sm">
          <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-700">
            <tr>
              <th class="border-b border-r border-slate-200 px-5 py-3 text-center">Waktu (Time)</th>
              <th class="border-b border-r border-slate-200 px-5 py-3 text-center">✂ Slot 1</th>
              <th class="border-b border-r border-slate-200 px-5 py-3 text-center">✂ Slot 2</th>
              <th class="border-b border-slate-200 px-5 py-3 text-center">✂ Slot 3</th>
            </tr>
          </thead>
          <tbody id="slot-rows" class="divide-y divide-slate-100">
            <tr><td colspan="4" class="px-5 py-10 text-center text-slate-500">Memuat data contoh…</td></tr>
          </tbody>
        </table>
      </div>
    </section>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
      <p class="text-xs text-slate-500">Slot penuh atau ditutup tidak tersedia untuk booking pelanggan.</p>
      <button id="save-slots" class="inline-flex items-center gap-2 rounded-xl bg-[#0d3b36] px-5 py-3 text-xs font-bold text-white hover:bg-[#082724]">
        <span class="material-symbols-outlined text-[16px]">save</span>Simpan Perubahan
      </button>
    </div>
  </main>

  <script src="../assets/js/admin.js"></script>
  <script src="../assets/js/admin-slots.js"></script>
</body>
</html>
