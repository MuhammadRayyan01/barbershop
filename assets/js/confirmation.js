
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
    const PAYMENT_STATUS_ENDPOINT = '../../backend/api/payment_status.php';

    let holdToken = null;
    let verifyOnly = false;

    // True after Midtrans reported a payment, until our backend has confirmed it.
    let awaitingVerification = false;
    let verifying = false;

    // Format currency

    function formatCurrency(value) {
        return 'Rp ' + Number(value).toLocaleString('id-ID');
    }

    // Format date

    function formatDate(value) {
        const date = new Date(`${value}T00:00:00+07:00`);

        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            timeZone: 'Asia/Jakarta'
        }).format(date);
    }

    // Display messages

    function showError(text) {
        loading.classList.add('hidden');
        content.classList.add('hidden');

        message.textContent = text;
        message.className =
            'mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700';
    }

    function showPaymentMessage(text, type = 'info') {
        message.textContent = text;

        const styles = {
            info: 'mt-4 rounded-xl bg-blue-50 px-4 py-3 text-sm text-blue-700',
            success: 'mt-4 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700',
            error: 'mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700'
        };

        message.className = styles[type] || styles.info;
    }

    // Load confirmation details from active hold

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
                    result.message || 'Gagal memuat detail booking.'
                );
            }

            const data = result.data;

            // The new implementation uses hold_token,
            // not reservation_id.
            holdToken = data.hold_token;

            if (!holdToken) {
                throw new Error('Token booking tidak valid.');
            }

            document.getElementById('confirm-service-name').textContent =
                data.service_name;

            const additionalService = data.additional_service_name
                ? `${data.additional_service_name} (${formatCurrency(data.additional_service_price || 0)})`
                : 'Tidak ada';
            document.getElementById('confirm-additional-service').textContent =
                additionalService;

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

    // Send JSON POST request

    async function postJson(url, data, csrfToken) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            credentials: 'same-origin',
            cache: 'no-store',
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

    // Load Midtrans Snap JavaScript

    function loadMidtransSnap(snapUrl, clientKey) {
        return new Promise((resolve, reject) => {
            try {
                const url = new URL(snapUrl);

                const allowedHosts = [
                    'app.midtrans.com',
                    'app.sandbox.midtrans.com'
                ];

                if (
                    url.protocol !== 'https:' ||
                    !allowedHosts.includes(url.hostname)
                ) {
                    throw new Error('URL Midtrans tidak valid.');
                }

                url.searchParams.set('client-key', clientKey);

                // Reuse Snap if it has already been loaded.
                if (window.snap) {
                    resolve();
                    return;
                }

                const existingScript = document.querySelector(
                    'script[data-midtrans-snap]'
                );

                if (existingScript) {
                    existingScript.addEventListener('load', resolve, {
                        once: true
                    });

                    existingScript.addEventListener(
                        'error',
                        () => reject(
                            new Error('Gagal memuat Midtrans Snap.')
                        ),
                        { once: true }
                    );

                    return;
                }

                const script = document.createElement('script');

                script.src = url.href;
                script.async = true;
                script.dataset.midtransSnap = 'true';

                script.onload = resolve;

                script.onerror = () => {
                    reject(
                        new Error('Gagal memuat Midtrans Snap.')
                    );
                };

                document.head.appendChild(script);

            } catch (error) {
                reject(error);
            }
        });
    }

    // Ask our backend to verify/finalize the transaction before redirecting.
    async function verifyPaymentAndRedirect(csrfToken) {
        verifyOnly = true;
        button.disabled = true;
        button.textContent = 'Memverifikasi pembayaran...';
        showPaymentMessage(
            'Pembayaran diterima. Sedang memverifikasi reservasi Anda...',
            'success'
        );

        for (let attempt = 0; attempt < 20; attempt += 1) {
            try {
                const response = await fetch(PAYMENT_STATUS_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    body: JSON.stringify({ hold_token: holdToken })
                });

                const result = await response.json().catch(() => null);

                if (
                    result?.booking_status === 'confirmed' &&
                    result?.data?.reservation_id
                ) {
                    window.location.replace(
                        'success.php?reservation_id=' +
                        encodeURIComponent(result.data.reservation_id)
                    );
                    return;
                }

                if (result?.booking_status === 'manual_review') {
                    verifyOnly = false;
                    showPaymentMessage(
                        result.message || 'Pembayaran diterima, tetapi reservasi perlu diperiksa admin.',
                        'info'
                    );
                    button.disabled = true;
                    button.textContent = 'Pembayaran diterima';
                    return;
                }

                if (!response.ok && response.status !== 202) {
                    throw new Error(
                        result?.message || 'Status pembayaran belum dapat diperiksa.'
                    );
                }
            } catch (error) {
                console.error('Payment verification attempt failed:', error);
            }

            await new Promise(resolve => setTimeout(resolve, 1500));
        }

        showPaymentMessage(
            'Pembayaran belum terkonfirmasi ke aplikasi. Tekan “Periksa status pembayaran” untuk mencoba lagi.',
            'info'
        );
        button.disabled = false;
        button.textContent = 'Periksa status pembayaran';
    }

    // Ask OUR backend to verify the payment with Midtrans.
    // The browser callback is never trusted as proof of payment.

    async function verifyPaymentStatus(csrfToken) {
        if (verifying) return;
        verifying = true;

        button.disabled = true;
        button.textContent = 'Memverifikasi pembayaran...';

        const maxAttempts = 20;      // about 1 minute
        const delayMs = 3000;

        try {
            for (let attempt = 1; attempt <= maxAttempts; attempt++) {
                let result = null;

                try {
                    const response = await fetch(PAYMENT_STATUS_ENDPOINT, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        body: JSON.stringify({ hold_token: holdToken })
                    });

                    result = await response.json().catch(() => null);
                } catch (networkError) {
                    result = null; // try again below
                }

                // Paid and saved in the database.
                if (
                    result?.success === true &&
                    result.payment_status === 'paid' &&
                    result.data?.reservation_id
                ) {
                    awaitingVerification = false;

                    showPaymentMessage(
                        'Pembayaran berhasil dan booking Anda terkonfirmasi.',
                        'success'
                    );

                    window.location.assign(
                        'success.php?reservation_id=' +
                        encodeURIComponent(result.data.reservation_id)
                    );
                    return;
                }

                // Paid at Midtrans, but the booking needs manual review.
                if (result?.booking_status === 'manual_review') {
                    awaitingVerification = false;
                    showPaymentMessage(result.message, 'error');
                    button.textContent = 'Hubungi barbershop';
                    return;
                }

                // Backend says: do not retry (for example gateway configuration problem).
                if (result?.retry === false) {
                    awaitingVerification = false;
                    showPaymentMessage(
                        result.message || 'Pembayaran tidak dapat diverifikasi.',
                        'error'
                    );
                    button.textContent = 'Tidak dapat diverifikasi';
                    return;
                }

                // Payment ended unsuccessfully at Midtrans.
                if (
                    result?.success === true &&
                    ['deny', 'cancel', 'expire', 'failure'].includes(result.payment_status)
                ) {
                    awaitingVerification = false;
                    showPaymentMessage(
                        'Pembayaran tidak berhasil. Silakan pilih jadwal lagi.',
                        'error'
                    );
                    button.textContent = 'Pembayaran gagal';
                    return;
                }

                // Not confirmed yet: wait and ask again.
                await new Promise(resolve => setTimeout(resolve, delayMs));
            }

            // Gave up waiting. The customer can press the button to check again.
            showPaymentMessage(
                'Pembayaran belum terkonfirmasi. Jika Anda sudah membayar, jangan bayar lagi. Tekan tombol untuk memeriksa ulang.',
                'info'
            );
            button.disabled = false;
            button.textContent = 'Periksa pembayaran';

        } finally {
            verifying = false;
        }
    }

    // Confirm and create Midtrans payment

    confirmationForm.addEventListener('submit', async event => {
        event.preventDefault();

        if (!holdToken) {
            showPaymentMessage(
                'Booking tidak ditemukan. Silakan muat ulang halaman.',
                'error'
            );
            return;
        }

        const csrfToken = confirmationForm.dataset.csrfToken;

        if (!csrfToken) {
            showPaymentMessage(
                'Sesi formulir berakhir. Muat ulang halaman.',
                'error'
            );
            return;
        }

        // Payment already made: only re-check it, never create a second payment.
        if (awaitingVerification) {
            await verifyPaymentStatus(csrfToken);
            return;
        }

        button.disabled = true;
        button.textContent = 'Menyiapkan pembayaran...';

        message.classList.add('hidden');

        try {
            // Ask our backend to create a Snap transaction.
            const checkout = await postJson(
                PAYMENT_ENDPOINT,
                {
                    hold_token: holdToken
                },
                csrfToken
            );

            const payment = checkout.data;

            if (
                !payment ||
                !payment.snap_token ||
                !payment.client_key ||
                !payment.snap_url
            ) {
                throw new Error(
                    'Informasi pembayaran Midtrans tidak lengkap.'
                );
            }

            // Load Midtrans Snap.
            await loadMidtransSnap(
                payment.snap_url,
                payment.client_key
            );

            if (!window.snap) {
                throw new Error('Midtrans Snap belum berhasil dimuat.');
            }

            button.textContent = 'Menunggu pembayaran...';

            // Open Midtrans payment popup.
            window.snap.pay(payment.snap_token, {
                onSuccess: function (result) {
                    console.log('Midtrans success callback:', result);

                    awaitingVerification = true;

                    showPaymentMessage(
                        'Pembayaran diterima. Backend sedang memverifikasi pembayaran Anda.',
                        'success'
                    );

                    verifyPaymentStatus(csrfToken);
                },

                onPending: function (result) {
                    console.log('Midtrans pending callback:', result);
                    verifyOnly = true;

                    awaitingVerification = true;

                    showPaymentMessage(
                        'Pembayaran sedang menunggu penyelesaian. Silakan ikuti instruksi Midtrans.',
                        'info'
                    );

                    button.disabled = false;
                    button.textContent = 'Periksa status pembayaran';
                },

                onError: function (result) {
                    console.error('Midtrans error callback:', result);
                    verifyOnly = false;

                    showPaymentMessage(
                        'Pembayaran belum berhasil. Silakan coba lagi.',
                        'error'
                    );

                    button.disabled = false;
                    button.textContent = 'Coba pembayaran lagi';
                },

                onClose: function () {
                    // The popup also closes after a successful payment.
                    // Do not overwrite the verification state in that case.
                    if (awaitingVerification || verifying) {
                        return;
                    }

                    showPaymentMessage(
                        'Jendela pembayaran ditutup. Booking Anda masih mengikuti waktu berlaku hold.',
                        'info'
                    );

                    button.disabled = false;
                    button.textContent = 'Lanjutkan Pembayaran';
                }
            });

        } catch (error) {
            console.error('Payment preparation error:', error);

            showPaymentMessage(
                error.message || 'Pembayaran gagal disiapkan. Coba lagi.',
                'error'
            );

            button.disabled = false;
            button.textContent = 'Coba pembayaran lagi';
        }
    });

    // Load confirmation when page opens

    loadConfirmation();

})();
