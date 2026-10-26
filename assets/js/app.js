// service 

  function selectService(element) {
    // Class dasar kartu layanan (persegi: aspect-square). Dipakai di semua kartu.
    const kartuDasar = "service-option aspect-square overflow-hidden bg-white p-4 rounded-2xl shadow-sm cursor-pointer transition-all duration-200 flex flex-col justify-between";

    // 1. Ambil semua elemen opsi layanan
    const allServices = document.querySelectorAll('.service-option');
    
    // 2. Reset semua kartu ke tampilan biasa (border border-gray-200)
    allServices.forEach(card => {
      card.className = kartuDasar + " border border-gray-200 hover:border-[#0d3b36]";
    });

    // 3. Berikan border tebal hijau pada kartu yang baru saja diklik
    element.className = kartuDasar + " border-2 border-[#0d3b36]";

    // 4. Ambil data layanan yang dipilih
    const serviceName = element.getAttribute('data-name');
    const servicePrice = element.getAttribute('data-price');

    console.log("Layanan Terpilih:", serviceName, servicePrice);
  }


// date and time 
  // 1. Definisikan Style Tailwind untuk status Aktif dan Tidak Aktif
  const DATE_ACTIVE = "bg-[#e8f3ee] border border-[#12372a] text-[#12372a] font-bold";
  const DATE_INACTIVE = "bg-gray-50 border border-gray-200 text-gray-600 hover:bg-gray-100";

  const TIME_ACTIVE = "bg-[#12372a] text-white font-bold border border-[#12372a]";
  const TIME_INACTIVE = "bg-white border border-gray-200 text-gray-600 hover:border-[#12372a]";

  // Variabel untuk menyimpan data jadwal yang dipilih
  let selectedSchedule = {
    date: "Hari Ini, 22 Okt",
    time: "13:00"
  };

  // 2. Mengatur Event Listener untuk Tombol Tanggal
  const dateButtons = document.querySelectorAll('.date-btn');
  dateButtons.forEach(button => {
    button.addEventListener('click', function() {
      // Reset semua tombol tanggal ke status tidak aktif
      dateButtons.forEach(btn => {
        btn.className = `date-btn ${DATE_INACTIVE} px-4 py-2 rounded-full text-xs transition`;
      });

      // Aktifkan tombol yang sedang diklik
      this.className = `date-btn ${DATE_ACTIVE} px-4 py-2 rounded-full text-xs transition`;

      // Simpan data tanggal yang dipilih
      selectedSchedule.date = this.innerText.trim();

      // Perbarui teks ringkasan
      updateSummaryText();
    });
  });

  // 3. Mengatur Event Listener untuk Tombol Jam
  const timeButtons = document.querySelectorAll('.time-btn');
  timeButtons.forEach(button => {
    button.addEventListener('click', function() {
    // Reset semua tombol jam ke status tidak aktif
      timeButtons.forEach(btn => {
        btn.className = `time-btn ${TIME_INACTIVE} py-2 rounded-lg text-xs transition`;
      });

      // Aktifkan tombol jam yang sedang diklik
      this.className = `time-btn ${TIME_ACTIVE} py-2 rounded-lg text-xs transition`;

      // Simpan data jam yang dipilih
      selectedSchedule.time = this.innerText.trim();

      // Perbarui teks ringkasan
      updateSummaryText();
    });
  });

  // 4. Fungsi untuk memperbarui teks informasi secara dinamis
  function updateSummaryText() {
    const summaryElement = document.getElementById('summary-text');
    summaryElement.innerText = `${selectedSchedule.date} pukul ${selectedSchedule.time} WIB.`;
    
    // (Opsional) Log data ke console untuk melihat variabel terupdate
    console.log("Jadwal Terpilih:", selectedSchedule);
  }


  // confirmation page
  // input name and phone number
  // Fungsi Validasi Nama Lengkap
function validateName() {
  const nameInput = document.getElementById('nama-input');
  const nameCheck = document.getElementById('nama-check');
  
  // Jika kolom nama tidak kosong (setelah dikurangi spasi kosong)
  if (nameInput.value.trim().length > 0) {
    // Ubah centang menjadi warna hijau terang
    nameCheck.className = "absolute right-3.5 text-xs text-green-600 font-bold transition-all";
  } else {
    // Kembalikan ke warna abu-abu redup jika kosong
    nameCheck.className = "absolute right-3.5 text-xs text-gray-300 font-bold transition-all";
  }
}

// Fungsi Validasi Nomor WhatsApp
function validatePhone() {
  const phoneInput = document.getElementById('wa-input');
  const phoneCheck = document.getElementById('wa-check');
  
  // Contoh validasi sederhana: minimal panjang nomor 9 digit
  if (phoneInput.value.trim().length >= 9) {
    // Ubah centang menjadi warna hijau terang
    phoneCheck.className = "absolute right-3.5 text-xs text-green-600 font-bold transition-all";
  } else {
    // Kembalikan ke abu-abu jika belum memenuhi syarat panjang karakter
    phoneCheck.className = "absolute right-3.5 text-xs text-gray-300 font-bold transition-all";
  }
}
  // Fungsi untuk Memilih Metode Pembayaran di Halaman 3
function selectPayment(element) {
  const allPaymentCards = document.querySelectorAll('.payment-card');
  
  // 1. Reset semua kartu pembayaran ke status tidak aktif (border biasa)
  allPaymentCards.forEach(card => {
    card.className = "payment-card border border-gray-200 hover:border-gray-300 bg-white p-3.5 rounded-xl flex items-start gap-3 cursor-pointer transition-all";
    
    // Matikan centang radio button pada kartu lain
    const radio = card.querySelector('input[type="radio"]');
    if (radio) {
      radio.checked = false;
    }
  });

  // 2. Aktifkan kartu pembayaran yang sedang diklik (border tebal & latar hijau muda)
  element.className = "payment-card border-2 border-[#12372a] bg-[#fcfdfe] p-3.5 rounded-xl flex items-start gap-3 cursor-pointer transition-all";
  
  // Berikan centang pada radio button di kartu yang diklik
  const activeRadio = element.querySelector('input[type="radio"]');
  if (activeRadio) {
    activeRadio.checked = true;
  }

  console.log("Metode pembayaran dipilih");
}

// ============================================================
// AKUN: tombol akun + pop-up edit profil (dipakai semua halaman user)
// ============================================================

// Data contoh. Nanti diganti data user yang sedang login dari PHP (tabel users).
let akun = { nama: 'Raffy Kurnia', hp: '0812-3456-7890', email: 'raffy.kurnia@example.com' };

const akunBtn   = document.getElementById('akun-btn');
const akunMenu  = document.getElementById('akun-menu');
const akunModal = document.getElementById('akun-modal');
const akunForm  = document.getElementById('akun-form');

// Tampilkan data akun di dropdown dan huruf awal di tombol
function tampilkanAkun() {
  document.getElementById('akun-nama-depan').innerText = akun.nama.split(' ')[0];
  document.getElementById('akun-nama').innerText = akun.nama;
  document.getElementById('akun-hp').innerText = akun.hp;
  document.getElementById('akun-email').innerText = akun.email;
  akunBtn.querySelector('span').innerText = akun.nama.charAt(0).toUpperCase();
}

function tutupModalAkun() {
  akunModal.classList.add('hidden');
  akunModal.classList.remove('flex');
}

// Semua kode di bawah hanya jalan kalau halaman punya tombol akun
if (akunBtn) {
  tampilkanAkun();

  // Klik tombol akun -> buka / tutup dropdown
  akunBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    akunMenu.classList.toggle('hidden');
  });

  // Klik di luar dropdown -> dropdown tertutup
  document.addEventListener('click', function (e) {
    if (!akunMenu.contains(e.target)) akunMenu.classList.add('hidden');
  });

  // Klik "Edit Profil" -> buka pop-up, isi dengan data sekarang
  document.getElementById('akun-edit-btn').addEventListener('click', function () {
    akunMenu.classList.add('hidden');
    document.getElementById('akun-input-nama').value = akun.nama;
    document.getElementById('akun-input-hp').value = akun.hp;
    document.getElementById('akun-input-email').value = akun.email;
    document.getElementById('akun-input-password').value = '';
    document.getElementById('akun-error').classList.add('hidden');
    akunModal.classList.remove('hidden');
    akunModal.classList.add('flex');
  });

  // Tombol Batal / klik area gelap -> pop-up tertutup
  document.getElementById('akun-batal').addEventListener('click', tutupModalAkun);
  akunModal.addEventListener('click', function (e) {
    if (e.target === akunModal) tutupModalAkun();
  });

  // Tombol Simpan Perubahan
  akunForm.addEventListener('submit', function (e) {
    e.preventDefault();
    const nama = document.getElementById('akun-input-nama').value.trim();
    const hp = document.getElementById('akun-input-hp').value.trim();
    const email = document.getElementById('akun-input-email').value.trim();
    const password = document.getElementById('akun-input-password').value;
    const error = document.getElementById('akun-error');

    // Validasi sederhana
    let pesan = '';
    if (nama.length < 3) pesan = 'Nama lengkap minimal 3 karakter.';
    else if (hp.replace(/\D/g, '').length < 9) pesan = 'Nomor handphone minimal 9 digit.';
    else if (!email.includes('@') || !email.includes('.')) pesan = 'Format email belum benar.';
    else if (password !== '' && password.length < 8) pesan = 'Password baru minimal 8 karakter, atau kosongkan.';

    if (pesan !== '') {
      error.innerText = pesan;
      error.classList.remove('hidden');
      return;
    }

    // PHP nanti: kirim data ini ke server (UPDATE users SET full_name, phone_number, email WHERE user_id)
    // Password hanya diubah kalau kolom password baru diisi.
    akun = { nama: nama, hp: hp, email: email };
    tampilkanAkun();
    tutupModalAkun();
  });
}
