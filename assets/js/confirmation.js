'use strict';

(() => {
    const confirmationForm = document.getElementById('confirmation-form');
    const loading = document.getElementById('confirmation-loading');
    const content = document.getElementById('confirmation-content');
    const message = document.getElementById('confirmation-message');
    const button = document.getElementById('confirm-submit');

    if (!confirmationForm) return;

    const CONFIRMATION_ENDPOINT = '../../backend/api/confirmation.php';
    const PAYMENT_ENDPOINT = '../../backend/api/create_payment.php';

    let reservationId = null;

    // -----------------------------------------------
    // Format currency
    // -----------------------------------------------

    function formatCurrency(value) {
        return 'Rp ' + Number(value).toLocaleString('id-ID');
    }

    // -----------------------------------------------
    // Format date
    // -----------------------------------------------

    function formatDate(value) {
        const date = new Date(`${value}T00:00:00+07:00`);

        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            timeZone: 'Asia/Jakarta'
        }).format(date);
    }

    // -----------------------------------------------
    // Display error
    // -----------------------------------------------

    function showError(text) {
        loading.classList.add('hidden');
        content.classList.add('hidden');

        message.textContent = text;
        message.className =
            'mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700';
    }

    // -----------------------------------------------
    // Load reservation details
    // -----------------------------------------------

    async function loadConfirmation() {
        try {
            const response = await fetch(CONFIRMATION_ENDPOINT, {
                method: 'GET',
                headers: {
                    Accept: 'application/json'
                },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Gagal memuat detail reservasi.'
                );
            }

            const data = result.data;

            reservationId = Number(data.reservation_id);

            if (!reservationId) {
                throw new Error('ID reservasi tidak valid.');
            }

            document.getElementById('confirm-service-name').textContent =
                data.service_name;

            document.getElementById('confirm-customer-name').textContent =
                data.customer_name;

            document.getElementById('confirm-phone').textContent =
                data.phone_number;

            document.getElementById('confirm-date').textContent =
                formatDate(data.slot_date);

            document.getElementById('confirm-time').textContent =
                `${String(data.start_time).slice(0, 5)}–${String(data.end_time).slice(0, 5)} WIB`;

            document.getElementById('confirm-total').textContent =
                formatCurrency(data.total_payment);

            loading.classList.add('hidden');
            content.classList.remove('hidden');

        } catch (error) {
            console.error('Confirmation loading error:', error);
            showError(error.message);
        }
    }

    // -----------------------------------------------
    // Send JSON POST request
    // -----------------------------------------------

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
            throw new Error(
                result?.message || 'Server gagal memproses permintaan.'
            );
        }

        return result;
    }

    // -----------------------------------------------
    // Validate Midtrans redirect URL
    // -----------------------------------------------

    function getMidtransRedirect(value) {
        if (typeof value !== 'string') {
            throw new Error('URL pembayaran Midtrans tidak diterima.');
        }

        const url = new URL(value);

        const allowedHosts = [
            'app.midtrans.com',
            'app.sandbox.midtrans.com'
        ];

        if (
            url.protocol !== 'https:' ||
            !allowedHosts.includes(url.hostname)
        ) {
            throw new Error('URL pembayaran Midtrans tidak valid.');
        }

        return url.href;
    }

    // -----------------------------------------------
    // Confirm and create payment
    // -----------------------------------------------

    confirmationForm.addEventListener('submit', async event => {
        event.preventDefault();

        if (!reservationId) {
            showError('Detail reservasi belum tersedia. Muat ulang halaman.');
            return;
        }

        const csrfToken = confirmationForm.dataset.csrfToken;

        if (!csrfToken) {
            showError('Sesi formulir berakhir. Muat ulang halaman.');
            return;
        }

        button.disabled = true;
        button.textContent = 'Menghubungkan ke Midtrans...';

        message.classList.add('hidden');

        try {
            const checkout = await postJson(
                PAYMENT_ENDPOINT,
                {
                    reservation_id: reservationId
                },
                csrfToken
            );

            const redirectUrl = getMidtransRedirect(
                checkout.redirect_url
            );

            window.location.assign(redirectUrl);

        } catch (error) {
            console.error('Payment preparation error:', error);

            message.textContent =
                error.message || 'Pembayaran gagal disiapkan. Coba lagi.';

            message.className =
                'mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700';

            button.disabled = false;
            button.textContent = 'Coba pembayaran lagi';
        }
    });

    // Load confirmation when page opens.
    loadConfirmation();

})();