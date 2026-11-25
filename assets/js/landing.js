(() => {
  'use strict';

  const bookingUrl = document.body.dataset.bookingUrl || '/user/login.php';
  const menuButton = document.getElementById('menu-toggle');
  const mainNav = document.getElementById('main-nav');
  menuButton?.addEventListener('click', () => {
    const isOpen = mainNav?.classList.toggle('is-open') ?? false;
    menuButton.setAttribute('aria-expanded', String(isOpen));
  });
  mainNav?.querySelectorAll('a').forEach(link => link.addEventListener('click', () => {
    mainNav.classList.remove('is-open');
    menuButton?.setAttribute('aria-expanded', 'false');
  }));

  const money = amount => new Intl.NumberFormat('id-ID', {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0
  }).format(Number(amount) || 0);

  const servicesGrid = document.getElementById('services-grid');
  if (servicesGrid) {
    fetch('/backend/api/get_services.php', {headers: {'Accept': 'application/json'}})
      .then(response => {
        if (!response.ok) throw new Error('Gagal memuat layanan');
        return response.json();
      })
      .then(result => {
        if (result.status !== 'success' || !Array.isArray(result.data)) throw new Error('Data layanan tidak valid');
        servicesGrid.replaceChildren();
        if (result.data.length === 0) {
          const empty = document.createElement('p');
          empty.className = 'loading-card';
          empty.textContent = 'Belum ada layanan aktif.';
          servicesGrid.append(empty);
          return;
        }
        result.data.forEach(service => {
          const card = document.createElement('article');
          card.className = 'service-card';
          const top = document.createElement('div');
          top.className = 'service-top';
          const duration = document.createElement('span');
          duration.className = 'duration-chip';
          duration.textContent = `${Number(service.duration_minutes) || 30} menit`;
          const price = document.createElement('span');
          price.className = 'service-price';
          price.textContent = money(service.price);
          top.append(duration, price);
          const title = document.createElement('h3');
          title.textContent = service.service_name || 'Layanan';
          const description = document.createElement('p');
          description.textContent = service.description || 'Detail layanan tersedia saat melakukan reservasi.';
          const bottom = document.createElement('div');
          bottom.className = 'service-bottom';
          const label = document.createElement('span');
          label.textContent = 'Harga sesuai katalog';
          const link = document.createElement('a');
          link.href = bookingUrl;
          link.textContent = 'Pilih layanan →';
          bottom.append(label, link);
          card.append(top, title, description, bottom);
          servicesGrid.append(card);
        });
      })
      .catch(error => {
        console.error('Landing services error:', error);
        servicesGrid.replaceChildren();
        const message = document.createElement('p');
        message.className = 'loading-card';
        message.textContent = 'Katalog layanan belum dapat dimuat. Silakan coba lagi nanti.';
        servicesGrid.append(message);
      });
  }

  const availabilityGrid = document.getElementById('slot-grid');
  const todayLabel = document.getElementById('today-label');
  if (availabilityGrid) {
    const now = new Date();
    const today = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit'
    }).format(now);
    if (todayLabel) {
      todayLabel.textContent = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta', weekday: 'long', day: 'numeric', month: 'long'
      }).format(now) + ' · WIB';
    }
    fetch(`/backend/api/availability.php?date=${encodeURIComponent(today)}`, {
      headers: {'Accept': 'application/json'}
    }).then(response => {
      if (!response.ok) throw new Error('Gagal memuat ketersediaan');
      return response.json();
    }).then(result => {
      if (!result.success || !Array.isArray(result.data)) throw new Error('Data jadwal tidak valid');
      availabilityGrid.replaceChildren();
      const availableSlots = result.data.filter(slot => !slot.is_full);
      const visibleSlots = result.data.slice(0, 12);
      if (visibleSlots.length === 0) {
        const empty = document.createElement('p');
        empty.className = 'slot-message';
        empty.textContent = 'Tidak ada slot reservasi tersisa hari ini. Coba pilih tanggal lain saat membuat reservasi.';
        availabilityGrid.append(empty);
      } else {
        visibleSlots.forEach(slot => {
          const card = document.createElement('div');
          card.className = `slot-item${slot.is_full ? ' is-full' : ''}`;
          const time = document.createElement('strong');
          time.textContent = String(slot.start_time).slice(0, 5);
          const status = document.createElement('span');
          status.textContent = slot.is_full ? 'Penuh' : `${Number(slot.remaining_capacity)} tersedia`;
          card.append(time, status);
          availabilityGrid.append(card);
        });
      }
      const note = document.getElementById('availability-note');
      if (note) note.textContent = `${availableSlots.length} slot masih memiliki kapasitas. Ketersediaan dapat berubah saat reservasi dibuat.`;
    }).catch(error => {
      console.error('Landing availability error:', error);
      availabilityGrid.replaceChildren();
      const message = document.createElement('p');
      message.className = 'slot-message';
      message.textContent = 'Jadwal hari ini belum dapat dimuat. Buka halaman reservasi untuk memilih tanggal dan mengecek ketersediaan terbaru.';
      availabilityGrid.append(message);
    });
  }
})();
