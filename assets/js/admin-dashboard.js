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
      message: `Reservasi ${row.dataset.name} akan dibatalkan. Pengembalian dana diproses sesuai metode pembayaran, dan pelanggan akan melihat statusnya di dashboard.`,
      okLabel: 'Batalkan',
      danger: true,
    });
    if (!confirmed) return;

    const cancelButton = button;
    cancelButton.disabled = true;
    try {
      const response = await fetch('../backend/api/cancel_reservation.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': actionForm.querySelector('[name="csrf_token"]').value,
        },
        body: JSON.stringify({ reservation_id: Number(row.dataset.id) }),
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        throw new Error(result.message || 'Reservasi gagal dibatalkan.');
      }

      let notice = document.getElementById('admin-action-notice');
      if (!notice) {
        notice = document.createElement('div');
        notice.id = 'admin-action-notice';
        notice.className = 'fixed right-5 top-20 z-[70] max-w-md rounded-xl bg-emerald-700 px-5 py-3 text-sm font-semibold text-white shadow-lg';
        document.body.appendChild(notice);
      }
      notice.textContent = result.message || 'Reservasi dibatalkan dan status refund diperbarui.';
      window.setTimeout(() => window.location.reload(), 1100);
    } catch (error) {
      cancelButton.disabled = false;
      let notice = document.getElementById('admin-action-notice');
      if (!notice) {
        notice = document.createElement('div');
        notice.id = 'admin-action-notice';
        notice.className = 'fixed right-5 top-20 z-[70] max-w-md rounded-xl bg-red-700 px-5 py-3 text-sm font-semibold text-white shadow-lg';
        document.body.appendChild(notice);
      }
      notice.textContent = error.message || 'Reservasi gagal dibatalkan.';
    }
    return;
  }

  document.getElementById('action-reservation-id').value = row.dataset.id;
  document.getElementById('reservation-action').value = action;
  actionForm.requestSubmit();
});

