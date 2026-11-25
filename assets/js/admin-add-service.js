initAdminShell('add-service', ['Studio Management', 'Administration Portal', 'Add Service']);

const addForm = document.getElementById('add-form');
const priceInput = document.getElementById('f-price');
const durationInput = document.getElementById('f-duration');

priceInput.addEventListener('input', event => {
  const value = digitsOnly(event.target.value);
  event.target.value = value ? value.toLocaleString('id-ID') : '';
});

addForm.addEventListener('submit', event => {
  const name = document.getElementById('f-name').value.trim();
  const price = digitsOnly(priceInput.value);
  const duration = Number(durationInput.value);
  const errorBox = document.getElementById('f-error');
  if (!name || !price || !Number.isInteger(duration) || duration < 15 || duration > 240) {
    event.preventDefault();
    errorBox.textContent = 'Nama, harga, dan durasi layanan harus valid (15–240 menit).';
    errorBox.classList.remove('hidden');
    return;
  }
  priceInput.value = price;
});
