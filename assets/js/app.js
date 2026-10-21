// service 

  function selectService(element) {
    // 1. Ambil semua elemen opsi layanan
    const allServices = document.querySelectorAll('.service-option');
    
    // 2. Reset semua kartu ke tampilan biasa (border border-gray-200)
    allServices.forEach(card => {
      card.className = "service-option bg-white p-6 rounded-2xl border border-gray-200 hover:border-[#0d3b36] shadow-sm cursor-pointer transition-all duration-200";
    });

    // 3. Berikan border tebal hijau pada kartu yang baru saja diklik
    element.className = "service-option bg-white p-6 rounded-2xl border-2 border-[#0d3b36] shadow-sm cursor-pointer transition-all duration-200";

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


  // choosing payment method
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