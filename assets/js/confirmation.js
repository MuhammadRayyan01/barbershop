'use strict';

const confirmationForm = document.getElementById('confirmation-form');

if (confirmationForm) {
  confirmationForm.addEventListener('submit', async event => {
    event.preventDefault();

    const button = document.getElementById('confirm-submit');
    const message = document.getElementById('confirmation-message');
    const reservationField = document.getElementById('confirm-reservation-id');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const serviceId = Number(document.getElementById('confirm-service-id').value);
    const slotId = Number(document.getElementById('confirm-slot-id').value);

    button.disabled = true;
    button.textContent = 'Menyiapkan pembayaran…';
    message.classList.add('hidden');

    try {
      if (!csrfToken) throw new Error('Sesi formulir berakhir. Muat ulang halaman.');

      let reservationId = Number(reservationField.value) || 0;

      if (!reservationId) {
        const reservation = await postJson('../../backend/api/reservation.php', {
          service_id: serviceId,
          slot_id: slotId,
          additional_service_id: null
        }, csrfToken);

        reservationId = Number(reservation.data?.reservation?.reservation_id);
        if (!reservationId) throw new Error('ID reservasi tidak diterima dari server.');
        reservationField.value = String(reservationId);
      }

      button.textContent = 'Menghubungkan ke Midtrans…';
      const checkout = await postJson('../../backend/api/create_payment.php', {
        reservation_id: reservationId
      }, csrfToken);

      const redirectUrl = getMidtransRedirect(checkout.redirect_url);
      window.location.assign(redirectUrl);
    } catch (error) {
      message.textContent = error.message || 'Pembayaran gagal disiapkan. Coba lagi.';
      message.className = 'mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700';
      button.disabled = false;
      button.textContent = reservationField.value
        ? 'Coba pembayaran lagi'
        : 'Konfirmasi & Lanjut ke Pembayaran';
    }
  });
}

async function postJson(url, data, csrfToken) {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': csrfToken
    },
    credentials: 'same-origin',
    body: JSON.stringify(data)
  });

  const result = await response.json().catch(() => null);
  if (!response.ok || !result?.success) {
    throw new Error(result?.message || 'Server gagal memproses permintaan.');
  }

  return result;
}

function getMidtransRedirect(value) {
  if (typeof value !== 'string') throw new Error('URL pembayaran Midtrans tidak diterima.');

  const url = new URL(value);
  const allowedHosts = ['app.midtrans.com', 'app.sandbox.midtrans.com'];
  if (url.protocol !== 'https:' || !allowedHosts.includes(url.hostname)) {
    throw new Error('URL pembayaran Midtrans tidak valid.');
  }

  return url.href;
}
