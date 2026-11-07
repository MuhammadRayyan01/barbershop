initAdminShell('refunds', ['Studio Management', 'Administration Portal', 'Refunds & Invoices']);

const refundBody = document.getElementById('ref-body');
const refundForm = document.getElementById('refund-action-form');

refundBody.addEventListener('click', async event => {
  const button = event.target.closest('[data-act]');
  if (!button) return;
  const row = button.closest('.refund-row');
  if (!row) return;

  if (button.dataset.act === 'invoice') {
    const details = [
      ['No. Invoice', row.dataset.invoice], ['Pelanggan', row.dataset.name],
      ['Telepon', row.dataset.phone], ['Layanan', row.dataset.service],
      ['Total Bayar', rupiah(row.dataset.price)], ['Status', row.dataset.state],
    ];
    document.getElementById('invoice-body').innerHTML = details
      .map(([label, value]) => `<dt class="text-slate-500">${esc(label)}</dt><dd class="col-span-2 font-semibold">${esc(value)}</dd>`)
      .join('');
    openModal('invoice-modal');
    return;
  }

  const confirmed = await confirmDialog({
    title: 'Catat refund selesai?',
    message: `Pastikan dana ${rupiah(row.dataset.price)} sudah dikembalikan kepada ${row.dataset.name}.`,
    okLabel: 'Konfirmasi',
  });
  if (!confirmed) return;
  document.getElementById('refund-reservation-id').value = row.dataset.id;
  refundForm.requestSubmit();
});

document.getElementById('btn-print').addEventListener('click', () => window.print());
