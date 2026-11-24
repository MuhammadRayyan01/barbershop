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

// Page-specific settings used by includes/header.php and includes/footer.php
$userPageTitle = 'Dashboard Pelanggan | Pemon Barbershop';
$userActivePage = 'dashboard';
$userAssetPrefix = '../../';
$userBodyClass = 'bg-[#f4f7f5] text-[#0d3b36] font-sans min-h-screen flex flex-col justify-between p-6';
$userPageScripts = ['../../assets/js/dashboard.js'];

require_once __DIR__ . '/../../includes/header.php';
?>

<main class="max-w-3xl w-full mx-auto my-8 space-y-4">
    <input
    type="hidden"
    id="dashboard-csrf-token"
    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
>

    <section class="bg-white rounded-2xl p-6 sm:p-8 shadow-sm border border-gray-100 space-y-6" aria-labelledby="dashboard-title">
        <div>
            <span class="inline-flex items-center gap-2 bg-[#e2efe8] text-[#0d3b36] text-xs font-semibold px-3 py-1 rounded-full">
                <span aria-hidden="true">●</span>
                IKHTISAR AKUN
            </span>

            <h1 id="dashboard-title" class="mt-4 text-2xl sm:text-3xl font-extrabold text-[#0d3b36] leading-tight">
                Selamat datang kembali,
                <span id="dashboard-nama">Memuat...</span>
            </h1>

            <p class="mt-2 text-gray-600 text-sm leading-relaxed">
                Lihat reservasi Anda dan kelola jadwal kunjungan di Pemon Barbershop.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-4" aria-label="Ringkasan reservasi">
            <div class="space-y-1">
                <span class="material-symbols-rounded text-3xl text-green-600" aria-hidden="true">calendar_month</span>
                <p id="jumlah-reservasi" class="text-2xl font-bold">0</p>
                <p class="text-xs sm:text-sm text-gray-600">Reservasi mendatang</p>
            </div>
        </div>

        <hr class="border-gray-100">

        <section aria-labelledby="reservasi-title">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 id="reservasi-title" class="font-bold text-sm">Reservasi Mendatang</h2>
                <a href="reservations.php" class="text-xs font-semibold text-[#0d3b36] hover:underline">Lihat semua</a>
            </div>

           <div id="reservasi-container" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <p class="text-sm text-gray-500">
                    Memuat data reservasi...
                </p>
            </div>
        </section>

        <hr class="border-gray-100">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
            <div>
                <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">Master Barber</p>
                <p class="font-bold text-[#0d3b36]">Berpengalaman &amp; Tersertifikasi</p>
            </div>
            <div>
                <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">Jaminan Waktu</p>
                <p class="font-bold text-[#0d3b36]">Tepat Waktu &amp; Bebas Antre</p>
            </div>
            <div>
                <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">Higienis</p>
                <p class="font-bold text-[#0d3b36]">Peralatan Steril &amp; Eksklusif</p>
            </div>
        </div>
    </section>

    <div class="bg-[#eaf4ef] border border-[#d2e8dd] rounded-2xl p-6 flex flex-col md:flex-row justify-between items-center gap-4">
        <div>
            <h3 class="font-bold text-[#0d3b36] text-sm">◎ &nbsp;Studio Senopati, Jakarta Selatan</h3>
            <p class="text-xs text-gray-600 mt-1">◷ &nbsp;Buka Setiap Hari: 10:00 - 21:00 WIB</p>
        </div>
        <a href="../booking/booking.php" class="bg-[#0d3b36] hover:bg-[#082724] text-white font-semibold text-sm px-6 py-3 rounded-xl transition flex items-center gap-2">
            Mulai Reservasi Sekarang <span aria-hidden="true">&rarr;</span>
        </a>
    </div>
</main>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
