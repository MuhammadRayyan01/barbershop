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
// AKUN: dipakai SEMUA halaman pelanggan lewat includes/header.php
// - dropdown akun
// - data profil dari backend/api/profile.php
// - pop-up Edit Profil (nama, HP, email, ganti password)
// - pop-up konfirmasi Keluar (logout memakai <form method="post"> biasa)
// Dibungkus IIFE supaya tidak bentrok dengan variabel di file JS lain.
// ============================================================
(() => {
  'use strict';

  const byId = (id) => document.getElementById(id);

  const btn = byId('akun-btn');
  const menu = byId('akun-menu');
  if (!btn || !menu) return; // halaman tanpa menu akun (belum login)

  const profileUrl = btn.dataset.profileUrl || '../../backend/api/profile.php';
  const loginUrl = btn.dataset.loginUrl || '/user/login.php';
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  let profile = null;
  const changeListeners = [];
  const errorListeners = [];

  // API kecil untuk skrip halaman (mis. dashboard.js) tanpa menduplikasi kode akun.
  window.PemonAccount = {
    getProfile: () => profile,
    onChange(callback) {
      changeListeners.push(callback);
      if (profile) callback(profile);
    },
    onError(callback) {
      errorListeners.push(callback);
    },
  };

  function setText(id, value) {
    const el = byId(id);
    if (el) el.textContent = value;
  }

  function showProfile(data) {
    profile = data;
    const fullName = String(data.full_name || data.username || '').trim() || 'Pelanggan';
    const firstName = fullName.split(/\s+/)[0];

    setText('akun-initial', firstName.charAt(0).toUpperCase());
    setText('akun-nama-depan', firstName);
    setText('akun-nama', fullName);
    setText('akun-username', data.username ? '@' + data.username : '');
    setText('akun-email', data.email || '');
    setText('akun-hp', data.phone_number || '');

    changeListeners.forEach((callback) => callback(profile));
  }

  async function loadProfile() {
    try {
      const response = await fetch(profileUrl, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (response.status === 401) {
        window.location.href = loginUrl;
        return;
      }
      const result = await response.json();
      if (!response.ok || !result.success || !result.data) {
        throw new Error(result.message || 'Gagal memuat profil.');
      }
      showProfile(result.data);
    } catch (error) {
      console.error('Profile loading error:', error);
      setText('akun-nama', 'Profil gagal dimuat');
      errorListeners.forEach((callback) => callback(error));
    }
  }

  // ---------- Dropdown ----------
  function setMenu(open) {
    menu.classList.toggle('hidden', !open);
    btn.setAttribute('aria-expanded', String(open));
  }

  btn.addEventListener('click', (event) => {
    event.stopPropagation();
    setMenu(menu.classList.contains('hidden'));
  });

  document.addEventListener('click', (event) => {
    if (!menu.contains(event.target) && !btn.contains(event.target)) setMenu(false);
  });

  // ---------- Helper untuk semua pop-up ----------
  function openPopup(popup) {
    popup.classList.remove('hidden');
    popup.classList.add('flex');
  }

  function closePopup(popup) {
    popup.classList.add('hidden');
    popup.classList.remove('flex');
  }

  const editModal = byId('akun-modal');
  const logoutModal = byId('akun-logout-modal');

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    setMenu(false);
    if (editModal) closePopup(editModal);
    if (logoutModal) closePopup(logoutModal);
  });

  // ---------- Pop-up Keluar ----------
  const logoutForm = byId('akun-logout-form');
  const logoutCancel = byId('akun-logout-batal');
  const logoutOk = byId('akun-logout-ok');

  if (logoutForm && logoutModal && logoutOk) {
    // Klik "Keluar" di dropdown -> tampilkan konfirmasi dulu.
    logoutForm.addEventListener('submit', (event) => {
      event.preventDefault();
      setMenu(false);
      openPopup(logoutModal);
      logoutOk.focus();
    });

    // "Ya, Keluar" mengirim form POST biasa (ada token CSRF) -> server mengarahkan ke halaman login.
    logoutOk.addEventListener('click', () => {
      logoutOk.disabled = true;
      logoutForm.submit(); // submit() bawaan tidak memicu event 'submit' di atas
    });

    logoutCancel?.addEventListener('click', () => closePopup(logoutModal));
    logoutModal.addEventListener('click', (event) => {
      if (event.target === logoutModal) closePopup(logoutModal);
    });
  }

  // ---------- Pop-up Edit Profil ----------
  const form = byId('akun-form');
  const editBtn = byId('akun-edit-btn');
  const cancelBtn = byId('akun-batal');
  const errorBox = byId('akun-error');

  const input = {
    name: byId('akun-input-nama'),
    phone: byId('akun-input-hp'),
    email: byId('akun-input-email'),
    current: byId('akun-input-current-password'),
    password: byId('akun-input-password'),
    confirm: byId('akun-input-confirm-password'),
  };

  const editReady = editModal && form && errorBox &&
    Object.values(input).every(Boolean);

  if (!editReady) {
    loadProfile();
    return; // halaman lama tanpa pop-up edit: dropdown + data profil saja
  }

  function showError(message) {
    errorBox.textContent = message;
    errorBox.classList.remove('hidden');
  }

  function openEditModal() {
    if (!profile) return;
    input.name.value = profile.full_name || '';
    input.phone.value = profile.phone_number || '';
    input.email.value = profile.email || '';
    input.current.value = '';
    input.password.value = '';
    input.confirm.value = '';
    errorBox.textContent = '';
    errorBox.classList.add('hidden');
    setMenu(false);
    openPopup(editModal);
    input.name.focus();
  }

  function toast(message) {
    const note = document.createElement('div');
    note.setAttribute('role', 'status');
    note.className = 'fixed bottom-4 right-4 z-50 rounded-xl bg-[#0d3b36] px-4 py-3 text-sm font-semibold text-white shadow-lg';
    note.textContent = message;
    document.body.append(note);
    window.setTimeout(() => note.remove(), 3500);
  }

  editBtn?.addEventListener('click', openEditModal);
  cancelBtn?.addEventListener('click', () => closePopup(editModal));
  editModal.addEventListener('click', (event) => {
    if (event.target === editModal) closePopup(editModal);
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    errorBox.classList.add('hidden');

    const fullName = input.name.value.trim();
    const phone = input.phone.value.trim();
    const email = input.email.value.trim();
    const current = input.current.value;
    const next = input.password.value;
    const confirm = input.confirm.value;

    if (fullName.length < 3) return showError('Nama lengkap minimal 3 karakter.');
    if (phone.replace(/\D/g, '').length < 9) return showError('Nomor handphone minimal 9 digit.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showError('Format email belum benar.');

    // Password hanya diubah kalau salah satu kolom password diisi.
    const changingPassword = current !== '' || next !== '' || confirm !== '';
    if (changingPassword) {
      if (current === '') return showError('Masukkan password saat ini.');
      if (next === '') return showError('Masukkan password baru.');
      if (next.length < 13) return showError('Password baru minimal 13 karakter.');
      if (next.length > 72) return showError('Password baru maksimal 72 karakter.');
      if (confirm === '') return showError('Konfirmasi password baru.');
      if (next !== confirm) return showError('Konfirmasi password tidak sama.');
    }

    if (!csrfToken) return showError('Token keamanan hilang. Muat ulang halaman.');

    const payload = { full_name: fullName, phone_number: phone, email };
    if (changingPassword) {
      payload.current_password = current;
      payload.new_password = next;
    }

    const submit = form.querySelector('button[type="submit"]');
    const submitLabel = submit ? submit.textContent : '';
    if (submit) {
      submit.disabled = true;
      submit.textContent = 'Menyimpan…';
    }

    try {
      const response = await fetch(profileUrl, {
        method: 'PATCH',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      if (response.status === 401) {
        window.location.href = loginUrl;
        return;
      }
      const result = await response.json().catch(() => null);
      if (!response.ok || !result?.success) {
        throw new Error(result?.message || 'Profil gagal diperbarui.');
      }

      showProfile(result.data);
      closePopup(editModal);
      toast('Profil berhasil diperbarui.');
    } catch (error) {
      showError(error.message);
    } finally {
      if (submit) {
        submit.disabled = false;
        submit.textContent = submitLabel;
      }
    }
  });

  loadProfile();
})();