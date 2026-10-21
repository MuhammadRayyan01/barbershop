
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
