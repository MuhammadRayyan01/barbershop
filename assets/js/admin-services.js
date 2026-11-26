initAdminShell('services', ['Studio Management', 'Administration Portal', 'Services']);

const serviceForm = document.getElementById('update-form');

document.getElementById('svc-body').addEventListener('click', async event => {
  const button = event.target.closest('[data-act]');
  if (!button) return;

  if (button.dataset.act === 'edit') {
    document.getElementById('f-id').value = button.dataset.id;
    document.getElementById('f-name').value = button.dataset.name;
    document.getElementById('f-price').value = Number(button.dataset.price).toLocaleString('id-ID');
    document.getElementById('f-desc').value = button.dataset.description;
    document.getElementById('f-duration').value = button.dataset.duration || '30';
    document.getElementById('f-error').classList.add('hidden');
    openModal('update-modal');
    return;
  }

  const confirmed = await confirmDialog({
    title: 'Nonaktifkan layanan?',
    message: `"${button.dataset.name}" tidak akan tampil lagi di halaman booking.`,
    okLabel: 'Nonaktifkan',
    danger: true,
  });
  if (!confirmed) return;
  document.getElementById('deactivate-id').value = button.dataset.id;
  document.getElementById('deactivate-form').requestSubmit();
});

document.getElementById('f-price').addEventListener('input', event => {
  const value = digitsOnly(event.target.value);
  event.target.value = value ? value.toLocaleString('id-ID') : '';
});

serviceForm.addEventListener('submit', event => {
  const name = document.getElementById('f-name').value.trim();
  const price = digitsOnly(document.getElementById('f-price').value);
  const duration = Number(document.getElementById('f-duration').value);
  const errorBox = document.getElementById('f-error');
  if (!name || !price || !Number.isInteger(duration) || duration < 15 || duration > 240) {
    event.preventDefault();
    errorBox.textContent = 'Nama, harga, dan durasi layanan harus valid (15–240 menit).';
    errorBox.classList.remove('hidden');
    return;
  }
  document.getElementById('f-price').value = price;
});

if (serviceForm.dataset.reopen === '1') {
  document.getElementById('f-error').textContent = 'Periksa kembali data layanan sebelum disimpan.';
  document.getElementById('f-error').classList.remove('hidden');
  openModal('update-modal');
}
