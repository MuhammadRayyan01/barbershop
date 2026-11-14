<?php
// frontend/booking.php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/csrf.php';
$csrfToken = getCsrfToken();

$userPageTitle = 'Booking | Pemon Barbershop';
$userActivePage = 'booking';
$userBodyClass = 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';
$userShowProfileEdit = false;
$userPageScripts = ['../../assets/js/booking-availability.js']; 
require __DIR__ . '/../includes/header.php';
?>
<main class="max-w-6xl w-full mx-auto my-6 space-y-6">

    <!-- Stepper / Indikator Langkah -->
    <div class="flex items-center gap-2 text-xs md:text-sm text-gray-400 font-medium px-2">
      <!-- (Sama seperti aslinya) -->
      <span class="text-gray-400"><strong class="text-gray-700">1</strong> Lokasi</span> <span>/</span>
      <span class="text-[#0d3b36] font-bold"><strong class="text-[#0d3b36]">2</strong> Layanan & Jadwal</span> <span>/</span>
      <span><strong class="text-gray-400">3</strong> Data Diri</span> <span>/</span>
      <span><strong class="text-gray-400">4</strong> Selesai</span>
    </div>

    <div class="space-y-4">
        <div class="flex justify-between items-center px-1">
          <h2 class="text-xs font-bold uppercase tracking-wider text-gray-500">Detail Reservasi</h2>
          <span class="text-xs font-bold text-gray-400 tracking-wider">LANGKAH 2/4</span>
        </div>

        <!-- Alert Error UI (Awalnya disembunyikan) -->
        <p id="error-alert" role="alert" class="hidden rounded-xl bg-red-50 px-4 py-3 text-xs text-red-700"></p>

        <form id="booking-form" class="space-y-4">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="service_id" id="selected-service-id" value="">
          <input type="hidden" name="slot_id" id="selected-slot-id" value="">

        <!-- Container Layanan (Akan dirender JS dari API) -->
        <div id="services-container" class="max-w-4xl mx-auto grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <p class="col-span-full text-sm text-gray-500">Memuat layanan...</p>
        </div>

        <!-- Tanggal Reservasi -->
        <div>
          <label class="block text-xs font-bold text-[#12372a] mb-2">Tanggal Reservasi</label>
          <input id="booking-date" name="booking_date" type="date" class="w-full sm:w-auto bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-xl text-sm focus:outline-none focus:border-[#12372a]">
          <p class="text-[11px] text-gray-500 mt-2">Pilih tanggal untuk melihat ketersediaan. Jam operasional 10.00–22.00, dengan interval 30 menit.</p>
        </div>

        <!-- Bagian Slot Waktu -->
        <div>
          <div class="flex justify-between items-center mb-2">
            <label class="text-xs font-bold text-[#12372a]">Slot Waktu Tersedia</label>
            <span class="text-[11px] text-gray-400">Zona Waktu WIB</span>
          </div>
          <div id="time-group" class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2" aria-live="polite">
            <p id="slot-message" class="col-span-full text-xs text-gray-500">Pilih layanan dan tanggal terlebih dahulu...</p>
          </div>
        </div>

        <!-- Tombol Aksi Bawah -->
        <div class="flex flex-col sm:flex-row justify-between items-center gap-4 pt-2">
          <span class="text-xs text-gray-500 flex items-center gap-1">Konfirmasi instan tanpa biaya admin</span>
          <div class="flex gap-3 w-full sm:w-auto">
            <a href="../dashboard/index.php" class="px-4 py-2 border border-gray-300 rounded-xl text-xs font-semibold text-gray-700 hover:bg-gray-50 text-center">&larr; Kembali</a>
            <button id="continue-booking" type="submit" disabled class="px-5 py-2 bg-gray-400 text-white rounded-xl text-xs font-bold text-center disabled:cursor-not-allowed">Lanjut ke Data Diri &rarr;</button>
          </div>
        </div>
        </form>
    </div>
</main>

<!-- JAVASCRIPT UNTUK INTEGRASI API -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Ambil Data Layanan dari API saat halaman dimuat
    fetch('../../backend/api/get_services.php')
        .then(response => response.json())
        .then(res => {
            const container = document.getElementById('services-container');
            container.innerHTML = ''; // Hapus tulisan "Memuat layanan..."

            if (res.status === 'success' && res.data.length > 0) {
                res.data.forEach(service => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    // Styling menyesuaikan kondisi aktif/tidak via event listener (bisa ditambahkan logika css di event click-nya nanti)
                    btn.className = "service-option aspect-square overflow-hidden rounded-2xl bg-white p-4 text-left shadow-sm transition-all duration-200 flex flex-col justify-between border border-gray-200 hover:border-[#0d3b36]";
                    btn.dataset.serviceId = service.service_id;
                    
                    // Format mata uang IDR
                    const priceFormatted = new Intl.NumberFormat('id-ID').format(service.price);

                    btn.innerHTML = `
                      <span>
                        <span class="block text-sm font-bold leading-tight text-[#0d3b36]">${service.service_name}</span>
                        <span class="mt-2 block text-[11px] text-gray-600">${service.description || 'Layanan barber'}</span>
                      </span>
                      <span>
                        <span class="block text-[10px] text-gray-400">Biaya layanan</span>
                        <span class="text-sm font-bold text-[#0d3b36]">Rp ${priceFormatted}</span>
                      </span>
                    `;
                    
                    // Event ketika layanan diklik
                    btn.addEventListener('click', function() {
                        // Reset semua border layanan
                        document.querySelectorAll('.service-option').forEach(b => {
                            b.classList.remove('border-2', 'border-[#0d3b36]');
                            b.classList.add('border', 'border-gray-200');
                        });
                        // Set layanan aktif
                        this.classList.remove('border', 'border-gray-200');
                        this.classList.add('border-2', 'border-[#0d3b36]');
                        
                        document.getElementById('selected-service-id').value = service.service_id;
                    });
                    
                    container.appendChild(btn);
                });
            } else {
                container.innerHTML = '<p class="col-span-full rounded-xl bg-white p-5 text-sm text-gray-500">Belum ada layanan aktif.</p>';
            }
        });

    // 2. Submit Form via API
    document.getElementById('booking-form').addEventListener('submit', function(e) {
        e.preventDefault(); // Mencegah form reload bawaan browser
        const errorAlert = document.getElementById('error-alert');
        errorAlert.classList.add('hidden'); // Sembunyikan error lama
        
        const formData = new FormData(this);

        fetch('../../backend/api/submit_booking.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(res => {
            if (res.status === 'success') {
                // Backend menyetujui, alihkan pengguna!
                window.location.href = res.redirect_url;
            } else {
                // Tampilkan pesan error dari backend
                errorAlert.textContent = res.message;
                errorAlert.classList.remove('hidden');
            }
        })
        .catch(err => {
            errorAlert.textContent = "Terjadi kesalahan jaringan.";
            errorAlert.classList.remove('hidden');
        });
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>