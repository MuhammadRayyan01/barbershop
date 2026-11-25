initAdminShell('reservations', ['Studio Management', 'Reservations']);

document.addEventListener('DOMContentLoaded', () => {
  const dialog = document.getElementById('reschedule-dialog');
  const form = document.getElementById('reschedule-form');
  const dateInput = document.getElementById('reschedule-date');
  const slotSelect = document.getElementById('reschedule-slot');
  const reservationInput = document.getElementById('reschedule-id');
  const customerLabel = document.getElementById('reschedule-customer');
  const currentLabel = document.getElementById('reschedule-current');
  const message = document.getElementById('reschedule-message');
  const submitButton = document.getElementById('submit-reschedule');
  const csrfToken = document.getElementById('admin-reservations-csrf')?.value || '';

  if (!dialog || !form || !dateInput || !slotSelect) return;

  const setMessage = (text, isError = false) => {
    message.textContent = text;
    message.className = `rounded-xl px-4 py-3 text-sm ${isError ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-800'}`;
  };

  const loadSlots = async () => {
    slotSelect.disabled = true;
    submitButton.disabled = true;
    slotSelect.replaceChildren(new Option('Memuat slot…', ''));
    message.classList.add('hidden');
    if (!dateInput.value) return;

    try {
      const response = await fetch(`../backend/api/availability.php?date=${encodeURIComponent(dateInput.value)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Slot gagal dimuat.');

      const availableSlots = (Array.isArray(result.data) ? result.data : [])
        .filter((slot) => slot.is_available === 'available' && !slot.is_full && Number(slot.remaining_capacity) > 0);
      slotSelect.replaceChildren(new Option(
        availableSlots.length ? 'Pilih slot tujuan' : 'Tidak ada slot yang tersedia',
        '',
      ));
      for (const slot of availableSlots) {
        const label = `${slot.start_time}–${slot.end_time} · ${slot.remaining_capacity} kapasitas tersisa`;
        slotSelect.add(new Option(label, String(slot.slot_id)));
      }
      slotSelect.disabled = availableSlots.length === 0;
      setMessage(availableSlots.length
        ? 'Pilih waktu yang disetujui pelanggan. Pembayaran dan layanan tetap mengikuti reservasi ini.'
        : 'Tanggal ini tidak memiliki slot terbuka. Pilih tanggal lain.', availableSlots.length === 0);
    } catch (error) {
      slotSelect.replaceChildren(new Option('Gagal memuat slot', ''));
      setMessage(error.message || 'Slot gagal dimuat.', true);
    }
  };

  document.querySelectorAll('.open-reschedule').forEach((button) => {
    button.addEventListener('click', () => {
      reservationInput.value = button.dataset.reservationId;
      customerLabel.textContent = `Pelanggan: ${button.dataset.customer || ''}`;
      currentLabel.textContent = `Jadwal saat ini: ${button.dataset.current || ''}`;
      dateInput.value = '';
      document.getElementById('reschedule-consent').checked = false;
      slotSelect.replaceChildren(new Option('Pilih tanggal terlebih dahulu', ''));
      slotSelect.disabled = true;
      submitButton.disabled = true;
      message.classList.add('hidden');
      dialog.showModal();
    });
  });

  dateInput.addEventListener('change', loadSlots);
  const updateSubmitState = () => {
    submitButton.disabled = !(slotSelect.value && document.getElementById('reschedule-consent').checked);
  };
  slotSelect.addEventListener('change', updateSubmitState);
  document.getElementById('reschedule-consent').addEventListener('change', updateSubmitState);
  document.getElementById('close-reschedule')?.addEventListener('click', () => dialog.close());

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!reservationInput.value || !slotSelect.value) return;
    submitButton.disabled = true;
    submitButton.textContent = 'Menyimpan…';
    try {
      const response = await fetch('../backend/api/admin_reschedule_reservation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          reservation_id: Number(reservationInput.value),
          slot_id: Number(slotSelect.value),
          customer_agreed: document.getElementById('reschedule-consent').checked,
          reason: document.getElementById('reschedule-reason').value,
        }),
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Jadwal gagal dipindahkan.');
      dialog.close();
      window.location.reload();
    } catch (error) {
      setMessage(error.message || 'Jadwal gagal dipindahkan.', true);
      updateSubmitState();
    } finally {
      submitButton.textContent = 'Pindahkan reservasi';
    }
  });

  document.querySelectorAll('.cancel-affected-reservation').forEach((button) => {
    button.addEventListener('click', async () => {
      const reservationId = Number(button.dataset.reservationId);
      if (!reservationId || !window.confirm(`Batalkan reservasi #${reservationId} atas nama ${button.dataset.customer || 'pelanggan'} dan catat refund manual? Setelah transfer dana dilakukan, tandai refund selesai di halaman Refund.`)) return;
      button.disabled = true;
      const originalText = button.textContent;
      button.textContent = 'Memproses…';
      try {
        const response = await fetch('../backend/api/admin_manual_refund.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ reservation_id: reservationId }),
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Refund manual gagal dicatat.');
        window.alert(result.message || 'Pembatalan berhasil diproses.');
        window.location.reload();
      } catch (error) {
        window.alert(error.message || 'Pembatalan gagal diproses.');
        button.disabled = false;
        button.textContent = originalText;
      }
    });
  });
});
