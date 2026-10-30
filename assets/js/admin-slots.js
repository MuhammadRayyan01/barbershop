(() => {
  initAdminShell('slots', ['Studio Management', 'Administration Portal', 'Date Time Slot']);

  const dateInput = document.getElementById('slot-date');
  const tbody = document.getElementById('slot-rows');
  const alertBox = document.getElementById('slot-alert');
  const saveButton = document.getElementById('save-slots');
  const modes = ['available', 'full', 'closed'];
  let currentDate = '';
  let slots = [];
  let savedSnapshot = '';

  function localDateString(date = new Date()) {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
  }

  function storageKey() { return `barbershop-slot-preview:${currentDate}`; }

  function showAlert(message, kind = 'info') {
    const styles = kind === 'success' ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800';
    alertBox.textContent = message;
    alertBox.className = `mt-5 rounded-xl px-4 py-3 text-sm ${styles}`;
    alertBox.classList.remove('hidden');
  }

  function createSampleSlots() {
    const data = [];
    for (let i = 0; i < 24; i += 1) {
      const start = 10 * 60 + i * 30;
      const end = start + 30;
      const fmt = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
      data.push({ time: fmt(start), end: fmt(end), states: ['available', 'available', 'available'] });
    }
    data[0].states = ['full', 'closed', 'available'];
    data[1].states = ['full', 'available', 'available'];
    data[2].states = ['full', 'available', 'closed'];
    return data;
  }

  function buttonMarkup(rowIndex, barberIndex, status) {
    const look = {
      available: { label: 'Buka', icon: '', css: 'border border-slate-300 bg-white text-slate-700 hover:border-[#0d3b36] hover:text-[#0d3b36]' },
      full: { label: 'Penuh', icon: 'check', css: 'bg-[#0d3b36] text-white hover:bg-[#164e46]' },
      closed: { label: 'Tutup', icon: 'close', css: 'bg-[#0d3b36] text-white hover:bg-[#164e46]' }
    }[status];
    return `<button type="button" data-row="${rowIndex}" data-barber="${barberIndex}" aria-label="${look.label}; klik untuk mengubah status" class="mx-auto flex h-12 min-w-12 flex-col items-center justify-center rounded-lg px-2 ${look.css}">
      ${look.icon ? `<span class="material-symbols-outlined text-[17px] leading-4">${look.icon}</span>` : ''}
      <span class="text-[9px] font-bold leading-3">${look.label}</span>
    </button>`;
  }

  function render() {
    tbody.innerHTML = slots.map((row, rowIndex) => `<tr>
      <th scope="row" class="border-r border-slate-200 px-5 py-3 text-center font-semibold">
        <span class="inline-flex min-w-[68px] justify-center rounded-lg border border-[#0d3b36] bg-slate-50 px-3 py-2">${row.time}</span>
      </th>
      ${row.states.map((status, barberIndex) => `<td class="border-r border-slate-100 px-4 py-2 text-center last:border-r-0">${buttonMarkup(rowIndex, barberIndex, status)}</td>`).join('')}
    </tr>`).join('');
    const snapshot = JSON.stringify(slots);
    saveButton.disabled = snapshot === savedSnapshot;
    saveButton.classList.toggle('opacity-50', saveButton.disabled);
    saveButton.classList.toggle('cursor-not-allowed', saveButton.disabled);
  }

  function loadDate() {
    currentDate = dateInput.value;
    const saved = localStorage.getItem(storageKey());
    slots = saved ? JSON.parse(saved) : createSampleSlots();
    savedSnapshot = JSON.stringify(slots);
    alertBox.classList.add('hidden');
    render();
  }

  tbody.addEventListener('click', event => {
    const button = event.target.closest('button[data-row]');
    if (!button) return;
    const row = slots[Number(button.dataset.row)];
    const barberIndex = Number(button.dataset.barber);
    const current = row.states[barberIndex];
    row.states[barberIndex] = modes[(modes.indexOf(current) + 1) % modes.length];
    render();
  });

  saveButton.addEventListener('click', () => {
    localStorage.setItem(storageKey(), JSON.stringify(slots));
    savedSnapshot = JSON.stringify(slots);
    render();
    showAlert('Perubahan contoh tersimpan di browser ini saja. Belum tersambung ke database.', 'success');
  });

  dateInput.min = localDateString();
  dateInput.value = dateInput.min;
  dateInput.addEventListener('change', loadDate);
  loadDate();
})();
