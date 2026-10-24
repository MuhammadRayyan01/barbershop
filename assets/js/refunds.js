    initAdminShell('refunds', ['Studio Management', 'Administration Portal', 'Refunds & Invoices']);

    /* ------------------------------------------------------------
       DATA CONTOH
       Sumber nanti: reservation (batal) + payment + invoice
       Nanti ganti dengan: fetch('../includes/get_refunds.php')
       state: waiting  -> payment_status 'paid', reservation 'inactive' (belum dikembalikan)
              refunded -> payment_status 'refunded'
              void     -> invoice dibatalkan
       ------------------------------------------------------------ */
    const meta = { total: 6, doneAmount: 245000, rows: 12 };
    const refunds = [
      { id: 1, name: 'Kevin Adrian',    phone: '0878-5544-3322', ref: 'REF-2023-0891', service: 'Paket Ayah & Anak',        staff: 'Master Barber: Aris Thorne',  price: 90000,  state: 'waiting' },
      { id: 2, name: 'Rian Pratama',    phone: '0812-3344-9988', ref: 'REF-2023-0889', service: 'Haircut & Deluxe Wash',    staff: 'Stylist: Kenzo Tanaka',       price: 65000,  state: 'waiting' },
      { id: 3, name: 'Dimas Setiawan',  phone: '0857-1122-3344', ref: 'INV-2023-0880', service: 'Classic Haircut',          staff: 'Master Barber: Aris Thorne',  price: 50000,  state: 'refunded' },
      { id: 4, name: 'Farhan Ramadhan', phone: '0819-7788-9900', ref: 'INV-2023-0875', service: 'Shave & Beard Trim',       staff: 'Groomer: Tatsuya Mori',       price: 35000,  state: 'refunded' },
      { id: 5, name: 'Bayu Nugroho',    phone: '0821-4455-6677', ref: 'INV-2023-0862', service: 'Hair Coloring / Bleaching', staff: 'Colorist: Kenzo Tanaka',     price: 120000, state: 'refunded' },
      { id: 6, name: 'Satria Wijaya',   phone: '0813-8899-1122', ref: 'INV-2023-0850', service: 'Kids Haircut & Styling',   staff: 'Stylist: Takeshi Vance',      price: 45000,  state: 'void' },
    ];

    const STATE = {
      waiting:  { label: 'Menunggu Refund',     color: 'amber',  action: 'refund' },
      refunded: { label: 'Selesai Refund',      color: 'green',  action: 'invoice' },
      void:     { label: 'Invoice Dibatalkan',  color: 'indigo', action: 'invoice' },
    };

    const $body = document.getElementById('ref-body');
    const $search = document.getElementById('search');
    const $filter = document.getElementById('status-filter');

    // dari dashboard: refunds.html?q=Nama
    const preset = new URLSearchParams(location.search).get('q');
    if (preset) $search.value = preset;

    function visibleRows() {
      const q = $search.value.trim().toLowerCase();
      const f = $filter.value;
      return refunds.filter(r =>
        (f === 'all' || r.state === f) &&
        (!q || [r.name, r.ref, r.service].some(v => v.toLowerCase().includes(q)))
      );
    }

    function render() {
      const rows = visibleRows();
      $body.innerHTML = rows.length ? rows.map((r, i) => {
        const st = STATE[r.state];
        const btn = st.action === 'refund'
          ? `<button data-act="refund" data-id="${r.id}" class="px-4 py-2.5 rounded-lg bg-[#0d3b36] hover:bg-[#082724] text-white text-xs font-bold no-print">Proses Refund</button>`
          : `<button data-act="invoice" data-id="${r.id}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-xs font-semibold no-print"><span class="material-symbols-outlined text-[16px]">receipt_long</span>Lihat Invoice</button>`;
        return `
        <tr class="hover:bg-slate-50">
          <td class="px-4 py-4 text-slate-500">${i + 1}</td>
          <td class="px-4 py-4">
            <p class="font-bold">${esc(r.name)}</p>
            <p class="text-xs text-slate-500">${esc(r.phone)} <span class="ml-2">&bull; ${esc(r.ref)}</span></p>
          </td>
          <td class="px-4 py-4"><p class="font-bold">${esc(r.service)}</p><p class="text-xs text-slate-500">${esc(r.staff)}</p></td>
          <td class="px-4 py-4 font-bold whitespace-nowrap">${rupiah(r.price)}</td>
          <td class="px-4 py-4">${chip(st.label, st.color, 'circle')}</td>
          <td class="px-4 py-4 text-right">${btn}</td>
        </tr>`;
      }).join('') : `<tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">Data tidak ditemukan.</td></tr>`;

      document.getElementById('stat-total').textContent = refunds.length;
      document.getElementById('stat-done').textContent = rupiah(meta.doneAmount);
      document.getElementById('stat-wait').textContent = `${refunds.filter(r => r.state === 'waiting').length} Antrean`;
      document.getElementById('range-label').textContent = `Baris 1 - ${rows.length} dari ${meta.rows} riwayat refund & invoice`;
    }

    $body.addEventListener('click', async e => {
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const r = refunds.find(x => x.id === Number(btn.dataset.id));

      if (btn.dataset.act === 'refund') {
        const ok = await confirmDialog({ title: 'Proses refund?', message: `Kembalikan ${rupiah(r.price)} kepada ${r.name}. Tindakan ini mengubah status pembayaran menjadi refunded.`, okLabel: 'Proses Refund' });
        if (!ok) return;
        // PHP nanti: POST includes/process_refund.php -> UPDATE payment SET payment_status='refunded'
        r.state = 'refunded';
        meta.doneAmount += r.price;
        toast('Refund diproses');
        render();
      }
      if (btn.dataset.act === 'invoice') {
        // kolom ini = tabel `invoice` (customer_name, phone_number, total_payment, cancellation_deadline)
        document.getElementById('invoice-body').innerHTML = [
          ['No. Invoice', r.ref], ['Pelanggan', r.name], ['Telepon', r.phone], ['Layanan', r.service],
          ['Total Bayar', rupiah(r.price)], ['Status', STATE[r.state].label],
        ].map(([k, v]) => `<dt class="text-slate-500">${k}</dt><dd class="col-span-2 font-semibold">${esc(v)}</dd>`).join('');
        openModal('invoice-modal');
      }
    });

    $search.addEventListener('input', render);
    $filter.addEventListener('change', render);
    document.getElementById('btn-print').addEventListener('click', () => window.print());
    document.getElementById('btn-csv').addEventListener('click', () => {
      downloadCSV('refund-invoice.csv', [
        ['No. Ref', 'Nama', 'Telepon', 'Layanan', 'Harga', 'Status'],
        ...visibleRows().map(r => [r.ref, r.name, r.phone, r.service, r.price, STATE[r.state].label]),
      ]);
    });

    render();

