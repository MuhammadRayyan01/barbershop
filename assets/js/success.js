document.addEventListener('DOMContentLoaded', () => {
    const page = document.getElementById('booking-success');

    if (!page) {
        return;
    }

    const loading = document.getElementById('success-loading');
    const content = document.getElementById('success-content');
    const errorSection = document.getElementById('success-error');
    const errorMessage = document.getElementById('success-error-message');

    const apiUrl = page.dataset.apiUrl;

    const elements = {
        code: document.getElementById('booking-code'),
        service: document.getElementById('booking-service'),
        name: document.getElementById('booking-name'),
        date: document.getElementById('booking-date'),
        time: document.getElementById('booking-time'),
        phone: document.getElementById('booking-phone'),
        total: document.getElementById('booking-total'),
        paymentStatus: document.getElementById('booking-payment-status'),
        paymentNote: document.getElementById('booking-payment-note'),
        invoiceLink: document.getElementById('booking-invoice-link')
    };

    const escapeText = (value) => {
        return value === null || value === undefined || value === ''
            ? '-'
            : String(value);
    };

    const formatDate = (value) => {
        if (!value) {
            return '-';
        }

        const parts = String(value).split('-');

        if (parts.length !== 3) {
            return '-';
        }

        const date = new Date(
            Number(parts[0]),
            Number(parts[1]) - 1,
            Number(parts[2])
        );

        return new Intl.DateTimeFormat('id-ID', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }).format(date);
    };

    const formatTime = (value) => {
        if (!value) {
            return '-';
        }

        return String(value).substring(0, 5);
    };

    const formatCurrency = (value) => {
        const amount = Number(value);

        if (!Number.isFinite(amount)) {
            return '-';
        }

        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(amount);
    };

    const showError = (message) => {
        loading.classList.add('hidden');
        content.classList.add('hidden');
        errorMessage.textContent = message;
        errorSection.classList.remove('hidden');
    };

    const renderReservation = (reservation) => {
        elements.code.textContent =
            `PMN-${reservation.reservation_id}`;

        elements.service.textContent =
            escapeText(reservation.service_name);

        elements.name.textContent =
            escapeText(reservation.full_name);

        elements.date.textContent =
            formatDate(reservation.slot_date);

        elements.time.textContent =
            `${formatTime(reservation.start_time)}–${formatTime(reservation.end_time)} WIB`;

        elements.phone.textContent =
            escapeText(reservation.phone_number);

        elements.total.textContent =
            formatCurrency(reservation.total_payment);

        const status = reservation.payment_status || 'pending';

        const paymentLabels = {
            paid: 'Lunas',
            pending: 'Menunggu pembayaran',
            'verification pending': 'Menunggu verifikasi',
            failed: 'Gagal',
            refunded: 'Dikembalikan',
            cancelled: 'Dibatalkan'
        };

        elements.paymentStatus.textContent =
            paymentLabels[status] || 'Belum ada pembayaran';

        elements.paymentStatus.className =
            'mt-2 inline-flex rounded-full px-3 py-1 text-xs font-semibold';

        if (status === 'paid') {
            elements.paymentStatus.classList.add(
                'bg-emerald-100',
                'text-emerald-800'
            );
        } else if (status === 'failed' || status === 'cancelled') {
            elements.paymentStatus.classList.add(
                'bg-red-100',
                'text-red-800'
            );
        } else if (status === 'refunded') {
            elements.paymentStatus.classList.add(
                'bg-blue-100',
                'text-blue-800'
            );
        } else {
            elements.paymentStatus.classList.add(
                'bg-amber-100',
                'text-amber-800'
            );
        }

        if (status !== 'paid') {
            elements.paymentNote.classList.remove('hidden');
        } else {
            elements.paymentNote.classList.add('hidden');
        }

        elements.invoiceLink.href =
            `invoice.php?reservation_id=${encodeURIComponent(reservation.reservation_id)}`;

        loading.classList.add('hidden');
        errorSection.classList.add('hidden');
        content.classList.remove('hidden');
    };

    const loadReservation = async () => {
        try {
            const params = new URLSearchParams(window.location.search);

            const reservationId = params.get('reservation_id');
            const orderId = params.get('order_id');

            if (reservationId) {
                params.set('reservation_id', reservationId);
            } else if (orderId) {
                params.set('order_id', orderId);
            } else {
                showError('ID reservasi tidak ditemukan.');
                return;
            }

            const response = await fetch(
                `${apiUrl}?${params.toString()}`,
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success || !result.data) {
                throw new Error(
                    result.message || 'Gagal memuat informasi reservasi.'
                );
            }

            renderReservation(result.data);

        } catch (error) {
            console.error('Booking success error:', error);

            showError(
                error.message || 'Terjadi kesalahan saat memuat reservasi.'
            );
        }
    };

    loadReservation();
});
