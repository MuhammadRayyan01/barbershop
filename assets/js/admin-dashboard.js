initAdminShell('dashboard', ['Studio Management', 'Administration Portal']);

const reservationBody = document.getElementById('res-body');
const actionForm = document.getElementById('reservation-action-form');

reservationBody.addEventListener('click', async event => {
  const button = event.target.closest('[data-act]');
  if (!button) return;
  const row = button.closest('.reservation-row');
  if (!row) return;

  if (button.dataset.act === 'detail') {
    const details = [
      ['Pelanggan', row.dataset.name], ['Telepon', row.dataset.phone], ['Layanan', row.dataset.service],
      ['Tanggal', row.dataset.date], ['Jam', `${row.dataset.time} WIB`],
      ['Total', rupiah(row.dataset.price)], ['Pembayaran', row.dataset.paid === '1' ? 'Lunas (Tunai)' : 'Belum dibayar'],
      ['Status reservasi', row.dataset.status],
    ];
    document.getElementById('detail-body').innerHTML = details
      .map(([label, value]) => `<dt class="text-slate-500">${esc(label)}</dt><dd class="col-span-2 font-semibold">${esc(value)}</dd>`)
      .join('');
    openModal('detail-modal');
    return;
  }

  const action = button.dataset.act === 'pay' ? 'mark_paid' : 'cancel';
  if (action === 'cancel') {
    const confirmed = await confirmDialog({
      title: 'Batalkan reservasi?',
      message: `Reservasi ${row.dataset.name} akan dibatalkan. Jika sudah dibayar, refund ditangani terpisah.`,
      okLabel: 'Batalkan',
      danger: true,
    });
    if (!confirmed) return;
  }

  document.getElementById('action-reservation-id').value = row.dataset.id;
  document.getElementById('reservation-action').value = action;
  actionForm.requestSubmit();
});

