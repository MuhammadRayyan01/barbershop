'use strict';

const confirmationForm = document.getElementById('confirmation-form');

if (confirmationForm) {
  confirmationForm.addEventListener('submit', async event => {
    event.preventDefault();

    const button = document.getElementById('confirm-submit');
    const message = document.getElementById('confirmation-message');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    button.disabled = true;
    button.textContent = 'Memproses reservasi…';
    message.classList.add('hidden');

    try {
      const response = await fetch('../../backend/api/reservation.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          service_id: Number(document.getElementById('confirm-service-id').value),
          slot_id: Number(document.getElementById('confirm-slot-id').value),
          additional_service_id: null
        })
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Reservasi gagal dikonfirmasi.');

      const reservationId = result.data?.reservation?.reservation_id;
      if (!reservationId) throw new Error('ID reservasi tidak diterima dari server.');
      window.location.href = `payment.html?reservation_id=${encodeURIComponent(reservationId)}`;
    } catch (error) {
      message.textContent = error.message || 'Reservasi gagal diproses. Coba lagi.';
      message.className = 'mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700';
      button.disabled = false;
      button.textContent = 'Konfirmasi & Lanjut ke Pembayaran';
    }
  });
}
