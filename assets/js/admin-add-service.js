initAdminShell('add-service', ['Studio Management', 'Administration Portal', 'Add Service']);

const addForm = document.getElementById('add-form');
const priceInput = document.getElementById('f-price');

priceInput.addEventListener('input', event => {
  const value = digitsOnly(event.target.value);
  event.target.value = value ? value.toLocaleString('id-ID') : '';
});

addForm.addEventListener('submit', event => {
  const name = document.getElementById('f-name').value.trim();
  const price = digitsOnly(priceInput.value);
  const errorBox = document.getElementById('f-error');
  if (!name || !price) {
    event.preventDefault();
    errorBox.textContent = 'Nama layanan dan harga wajib diisi.';
    errorBox.classList.remove('hidden');
    return;
  }
  priceInput.value = price;
});
