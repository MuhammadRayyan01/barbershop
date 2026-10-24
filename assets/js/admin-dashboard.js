
    initAdminShell('dashboard', ['Studio Management', 'Administration Portal']);

    /* ------------------------------------------------------------
       DATA CONTOH
       Bentuk ini meniru hasil query PHP nanti
       (reservation JOIN users JOIN service JOIN time_slot JOIN payment).
       Nanti ganti dengan: fetch('../includes/get_reservations.php?date=today')
       ------------------------------------------------------------ */
    const meta = { total: 24, canceled: 2, page: 1, perPage: 5 };

    // status: booked | in_service | done | cancelled   (reservation_status + alur kasir)
    // paid  : true  -> payment.payment_status = 'paid'
    // method: 'QRIS' | 'Tunai' | 'Kasir'  (payment.payment_type)
    const reservations = [
      { id: 1, name: 'Raffy Kurnia',  phone: '+62 812-8940-2190', time: '10:00', service: 'Classic Haircut',        barber: 'Hendra (Chair 1)', price: 50000, paid: true,  method: 'QRIS',  status: 'booked' },
      { id: 2, name: 'Dimas Pratama', phone: '+62 857-1234-5678', time: '10:45', service: 'Haircut & Wash Deluxe',  barber: 'Kevin (Chair 2)',  price: 65000, paid: false, method: 'Kasir', status: 'booked' },
      { id: 3, name: 'Budi Santoso',  phone: '+62 813-9876-5432', time: '11:30', service: 'Shave & Beard Trim',     barber: 'Hendra (Chair 1)', price: 35000, paid: true,  method: 'Tunai', status: 'in_service' },
      { id: 4, name: 'Kevin Adrian',  phone: '+62 878-5544-3322', time: '12:15', service: 'Paket Ayah & Anak',      barber: '',                 price: 90000, paid: true,  method: 'QRIS',  status: 'cancelled' },
      { id: 5, name: 'Arya Wirawan',  phone: '+62 819-2233-4455', time: '13:00', service: 'Classic Haircut',        barber: 'Dimas (Chair 3)',  price: 50000, paid: true,  method: 'QRIS',  status: 'booked' },
    ];

    const $body = document.getElementById('res-body');
    const $search = document.getElementById('search');

    // label tanggal hari ini
    const todayLabel = new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    document.getElementById('opt-today').textContent = `Hari Ini (${todayLabel})`;

    function payBadge(r) {
      if (r.status === 'cancelled') return chip('Dibatalkan', 'red', 'cancel');
      if (!r.paid) return chip('Belum Bayar (Kasir)', 'blue', 'point_of_sale');
      return chip(`Lunas (${r.method})`, 'green', r.method === 'QRIS' ? 'qr_code_2' : 'payments');
    }

    function actionButtons(r) {
      const b = (label, cls, act, extra = '') =>
        `<button data-act="${act}" data-id="${r.id}" ${extra} class="px-3 py-1.5 rounded-lg text-xs font-semibold transition ${cls}">${label}</button>`;
      const PRIMARY = 'bg-[#0d3b36] text-white hover:bg-[#082724]';
      const SOFT = 'bg-indigo-50 text-slate-700 hover:bg-indigo-100';
      const DIM = 'bg-indigo-50 text-slate-400 cursor-not-allowed';
      const cancelX = `<button data-act="cancel" data-id="${r.id}" class="p-1 text-red-600 hover:bg-red-50 rounded-lg" aria-label="Batalkan"><span class="material-symbols-outlined text-[18px]">close</span></button>`;

      if (r.status === 'cancelled') return b('Proses Refund', PRIMARY, 'refund');
      if (r.status === 'done')      return b('Detail', SOFT, 'detail') + chip('Selesai', 'green');
      if (r.status === 'in_service') return b('Detail', SOFT, 'detail') + b('Selesai', 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200', 'finish');
      // booked
      if (!r.paid) return b('Bayar', 'bg-emerald-700 text-white hover:bg-emerald-800', 'pay') + b('Check-in', DIM, 'checkin', 'disabled title="Bayar dulu sebelum check-in"') + cancelX;
      return b('Detail', SOFT, 'detail') + b('Check-in', PRIMARY, 'checkin') + cancelX;
    }

    function render() {
      const q = $search.value.trim().toLowerCase();
      const qDigits = q.replace(/\D/g, '');
      const rows = reservations.filter(r =>
        !q || r.name.toLowerCase().includes(q) || (qDigits && r.phone.replace(/\D/g, '').includes(qDigits)));

      $body.innerHTML = rows.length ? rows.map(r => {
        const cancelled = r.status === 'cancelled';
        const sub = cancelled ? `${r.time} WIB • Dibatalkan` : `${r.time} WIB • Hari ini`;
        return `
        <tr class="${cancelled ? 'bg-red-50/60 text-slate-400' : 'hover:bg-slate-50'}">
          <td class="px-4 py-4">
            <p class="font-semibold ${cancelled ? 'line-through' : 'text-slate-900'}">${esc(r.name)}</p>
            <p class="text-xs text-slate-500">${sub}</p>
          </td>
          <td class="px-4 py-4 font-mono text-xs ${cancelled ? 'line-through' : ''}">${esc(r.phone)}</td>
          <td class="px-4 py-4">
            <p class="font-semibold ${cancelled ? 'line-through' : 'text-slate-900'}">${esc(r.service)}</p>
            <p class="text-xs text-slate-500">${cancelled ? 'Slot dibatalkan' : 'Barber: ' + esc(r.barber)}</p>
          </td>
          <td class="px-4 py-4 font-semibold ${cancelled ? '' : 'text-slate-900'}">${rupiah(r.price)}</td>
          <td class="px-4 py-4">${payBadge(r)}</td>
          <td class="px-4 py-4"><div class="flex items-center justify-end gap-2">${actionButtons(r)}</div></td>
        </tr>`;
      }).join('') : `<tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">Tidak ada reservasi yang cocok.</td></tr>`;

      document.getElementById('stat-total').textContent = meta.total;
      document.getElementById('stat-canceled').textContent = meta.canceled;
      document.getElementById('count-label').textContent = `${meta.total} reservasi`;
      document.getElementById('range-label').textContent = `Baris 1 - ${rows.length} dari ${meta.total} reservasi`;
    }

    // semua klik tombol aksi ditangani di sini (event delegation)
    $body.addEventListener('click', async e => {
      const btn = e.target.closest('[data-act]');
      if (!btn || btn.disabled) return;
      const r = reservations.find(x => x.id === Number(btn.dataset.id));
      const act = btn.dataset.act;

      if (act === 'detail') {
        document.getElementById('detail-body').innerHTML = [
          ['Pelanggan', r.name], ['Telepon', r.phone], ['Layanan', r.service],
          ['Barber', r.barber || '-'], ['Jam', r.time + ' WIB'], ['Harga', rupiah(r.price)],
          ['Pembayaran', r.paid ? 'Lunas (' + r.method + ')' : 'Belum bayar'],
        ].map(([k, v]) => `<dt class="text-slate-500">${k}</dt><dd class="col-span-2 font-semibold">${esc(v)}</dd>`).join('');
        openModal('detail-modal');
      }
      // PHP nanti: tiap aksi di bawah = fetch POST ke includes/update_reservation.php (action + id)
      if (act === 'pay')     { r.paid = true; r.method = 'Tunai'; toast('Pembayaran tunai dicatat'); }
      if (act === 'checkin') { r.status = 'in_service'; toast(`${r.name} check-in`); }
      if (act === 'finish')  { r.status = 'done'; toast('Layanan selesai'); }
      if (act === 'cancel') {
        const ok = await confirmDialog({ title: 'Batalkan reservasi?', message: `Reservasi ${r.name} akan dibatalkan dan slot dibuka kembali.`, okLabel: 'Ya, Batalkan', danger: true });
        if (!ok) return;
        r.status = 'cancelled'; meta.canceled++;
        toast('Reservasi dibatalkan');
      }
      if (act === 'refund') { window.location.href = 'refunds.html?q=' + encodeURIComponent(r.name); return; }
      render();
    });

    $search.addEventListener('input', render);
    document.getElementById('btn-refresh').addEventListener('click', () => { render(); toast('Data diperbarui'); });
    document.getElementById('date-filter').addEventListener('change', e => toast(e.target.value === 'today' ? 'Menampilkan hari ini' : 'Data besok belum tersedia pada wireframe'));

    document.getElementById('btn-export').addEventListener('click', () => {
      downloadCSV('log-reservasi.csv', [
        ['Nama', 'Telepon', 'Jam', 'Layanan', 'Harga', 'Status'],
        ...reservations.map(r => [r.name, r.phone, r.time, r.service, r.price, r.status]),
      ]);
    });

    render();
