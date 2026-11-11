<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/session.php';
require_once __DIR__ . '/../../backend/config/csrf.php';

$userId = $_SESSION['user_id'] ?? null;

if(!is_int($userId) || $userId < 1){
  header('Location: /user/login.php');
  exit;
}

$csrfToken = getCsrfToken();
?>
<?php
$userPageTitle = 'Dashboard Pelanggan | Pemon Barbershop';
$userActivePage = 'dashboard';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';
$userShowProfileEdit = true;
$userPageScripts = ['../../assets/js/dashboard.js'];
require __DIR__ . '/../includes/header.php';
?>
<!-- Main Content -->
  <main class="max-w-3xl w-full mx-auto my-8 space-y-4">
    
    <!-- Hero Card -->
    <section class="bg-white rounded-2xl p-6 sm:p-8 shadow-sm border border-gray-100 space-y-6" aria-labelledby="dashboard-title">
      <div>
        <span class="inline-flex items-center gap-2 bg-[#e2efe8] text-[#0d3b36] text-xs font-semibold px-3 py-1 rounded-full">
          <span aria-hidden="true">●</span> IKHTISAR AKUN
        </span>
        <h1 id="dashboard-title" class="mt-4 text-2xl sm:text-3xl font-extrabold text-[#0d3b36] leading-tight">
          Selamat datang kembali,<span id="dashboard-nama">Memuat...</span>
        </h1>
        <p class="mt-2 text-gray-600 text-sm leading-relaxed">Lihat reservasi Anda dan kelola jadwal kunjungan di Pemon Barbershop.</p>
      </div>

      <div class="grid grid-cols-2 gap-4 sm:gap-8" aria-label="Ringkasan reservasi">
        <div class="space-y-1">
          <span aria-hidden="true" class="text-lg">▦</span> 
          <p id="jumlah-reservasi" class="text-2xl font-bold">0</p>
          <p class="text-xs sm:text-sm text-gray-600">Reservasi mendatang</p>
        </div>
        <div class="space-y-1">
          <span aria-hidden="true" class="text-lg">▤</span>
          <p id="jumlah-pembayaran" class="text-2xl font-bold">0</p>
          <p class="text-xs sm:text-sm text-gray-600">Menunggu pembayaran</p>
        </div>
      </div>

      <hr class="border-gray-100">

      <section aria-labelledby="reservasi-title">
        <h2 id="reservasi-title" class="font-bold text-sm mb-3">Reservasi Mendatang</h2>
        <div id="reservasi-container">
  <p class="text-sm text-gray-500">
    Memuat data reservasi...
  </p>
</div>
      </section>

      <hr class="border-gray-100">

      <!-- 3 Columns -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
        <div>
          <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">Master Barber</p>
          <p class="font-bold text-[#0d3b36]">Berpengalaman & Tersertifikasi</p>
        </div>
        <div>
          <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">Jaminan Waktu</p>
          <p class="font-bold text-[#0d3b36]">Tepat Waktu & Bebas Antre</p>
        </div>
        <div>
          <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">Higienis</p>
          <p class="font-bold text-[#0d3b36]">Peralatan Steril & Eksklusif</p>
        </div>
      </div>
    </section>

    <!-- Location & CTA Card -->
    <div class="bg-[#eaf4ef] border border-[#d2e8dd] rounded-2xl p-6 flex flex-col md:flex-row justify-between items-center gap-4">
      <div>
        <h3 class="font-bold text-[#0d3b36] text-sm">◎ &nbsp;Studio Senopati, Jakarta Selatan</h3>
        <p class="text-xs text-gray-600 mt-1">◷ &nbsp;Buka Setiap Hari: 10:00 - 21:00 WIB</p>
      </div>
      <a href="../booking/booking.php" class="bg-[#0d3b36] hover:bg-[#082724] text-white font-semibold text-sm px-6 py-3 rounded-xl transition flex items-center gap-2">
        Mulai Reservasi Sekarang <span>&rarr;</span>
      </a>
    </div>

  </main>
  <!-- Pop-up Edit Profil -->
  <div id="akun-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/40 p-4">
    <form id="akun-form" class="bg-white rounded-2xl w-full max-w-md p-6 space-y-4 text-[#0d3b36]">
      <h2 class="text-lg font-bold">Edit Data Profil</h2>
      <div>
        <label class="text-sm font-semibold">Nama Lengkap</label>
        <input id="akun-input-nama" type="text" class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="text-sm font-semibold">Nomor Handphone / WhatsApp</label>
        <input id="akun-input-hp" type="tel" class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="text-sm font-semibold">Email</label>
        <input id="akun-input-email" type="email" class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <div>
        <label class="text-sm font-semibold">Password Baru <span class="font-normal text-gray-400">(opsional)</span></label>
        <input id="akun-input-password" type="password" placeholder="Biarkan kosong jika tidak diubah" class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm">
      </div>
      <p id="akun-error" class="hidden text-sm text-red-600"></p>
      <div class="flex justify-end gap-2">
        <button type="button" id="akun-batal" class="px-4 py-2 text-sm font-semibold">Batal</button>
        <button type="submit" class="px-4 py-2 rounded-lg bg-[#0d3b36] text-white text-sm font-semibold">Simpan Perubahan</button>
      </div>
    </form>
  </div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
