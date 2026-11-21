document.addEventListener('DOMContentLoaded', () => {

    const dashboardName = document.getElementById('dashboard-nama');

    const reservationContainer = document.getElementById(
        'reservasi-container'
    );

    const reservationCount = document.getElementById(
        'jumlah-reservasi'
    );

    const pendingPaymentCount = document.getElementById(
        'jumlah-pembayaran'
    );

    const csrfToken = reservationContainer?.dataset.csrfToken || '';

    const countdownIntervals = new Set();

    if (window.PemonAccount) {

        window.PemonAccount.onChange((profile) => {

            if (dashboardName) {
                dashboardName.textContent =
                    profile.full_name || profile.username || 'Pelanggan';
            }
        });

        window.PemonAccount.onError(() => {

            if (dashboardName) {
                dashboardName.textContent = 'Pelanggan';
            }
        });
    }

    function formatRupiah(value) {

        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(Number(value) || 0);
    }

    function formatReservationDate(date, time) {

        const dateObject = new Date(`${date}T${time}`);

        if (Number.isNaN(dateObject.getTime())) {
            return `${date} ${time}`;
        }

        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'long',
            timeStyle: 'short'
        }).format(dateObject);
    }

    // Parse PostgreSQL timestamps without timezone as Jakarta time.
    function parseJakartaDateTime(value) {

        if (!value) {
            return null;
        }

        const timestamp = String(value).trim();

        const hasTimezone = /(?:Z|[+-]\d{2}:\d{2})$/i.test(timestamp);

        const normalized = timestamp.replace(' ', 'T');

        const date = new Date(
            hasTimezone ? normalized : `${normalized}+07:00`
        );

        return Number.isNaN(date.getTime()) ? null : date;
    }

    function canCancelReservation(value) {

        return value === true ||
            value === 'true' ||
            value === 't' ||
            value === 1 ||
            value === '1';
    }

    function getRefundStatusText(status) {

        const refundStatuses = {
            not_requested: 'Belum ada permintaan refund',
            pending: 'Refund sedang diproses',
            completed: 'Refund berhasil',
            failed: 'Refund gagal'
        };

        return refundStatuses[status] ||
            'Status refund belum tersedia';
    }

    // Clear countdown timers before rebuilding reservation cards.
    function clearCountdowns() {

        countdownIntervals.forEach((interval) => {
            clearInterval(interval);
        });

        countdownIntervals.clear();
    }

    // Start a countdown using the deadline saved by the backend.
    function startCountdown(deadline, timerElement, labelElement) {

        const deadlineDate = parseJakartaDateTime(deadline);

        if (!deadlineDate) {
            timerElement.textContent = '--:--';
            labelElement.textContent = 'Deadline tidak tersedia';
            return;
        }

        function updateCountdown() {

            const remainingMilliseconds =
                deadlineDate.getTime() - Date.now();

            const remainingSeconds = Math.max(
                0,
                Math.floor(remainingMilliseconds / 1000)
            );

            const minutes = Math.floor(remainingSeconds / 60);

            const seconds = remainingSeconds % 60;

            timerElement.textContent =
                `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

            if (remainingSeconds <= 0) {

                labelElement.textContent = 'Waktu pembatalan habis';

                timerElement.classList.remove('text-green-700');

                timerElement.classList.add('text-red-600');

                return false;
            }

            labelElement.textContent = 'Waktu tersisa';

            return true;
        }

        if (updateCountdown()) {

            const interval = setInterval(() => {

                if (!updateCountdown()) {

                    clearInterval(interval);

                    countdownIntervals.delete(interval);
                }

            }, 1000);

            countdownIntervals.add(interval);
        }
    }

    async function cancelReservation(reservationId, button) {

        if (!csrfToken) {
            alert('Token keamanan tidak ditemukan. Silakan muat ulang halaman.');
            return;
        }

        const confirmed = window.confirm(
            'Apakah Anda yakin ingin membatalkan reservasi ini dan mengajukan refund?'
        );

        if (!confirmed) {
            return;
        }

        button.disabled = true;
        button.textContent = 'Memproses...';

        try {

            const response = await fetch(
                '../../backend/api/cancel_reservation.php',
                {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        reservation_id: Number(reservationId)
                    })
                }
            );

            const result = await response.json();

            if (response.status === 401) {
                window.location.href = '../login.php';
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message ||
                    'Gagal memproses pembatalan reservasi.'
                );
            }

            alert(
                result.message ||
                'Permintaan pembatalan berhasil dikirim. Refund sedang diproses.'
            );

            await loadReservations();

        } catch (error) {

            console.error('Cancellation error:', error);

            alert(
                error.message ||
                'Terjadi kesalahan saat membatalkan reservasi.'
            );

            button.disabled = false;
            button.textContent = 'Batalkan Reservasi';
        }
    }

    async function loadReservations() {

        if (!reservationContainer) {
            return;
        }

        try {

            const response = await fetch(
                '../../backend/api/reservation.php',
                {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                }
            );

            const result = await response.json();

            if (response.status === 401) {
                window.location.href = '../login.php';
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message ||
                    'Unable to load reservation data.'
                );
            }

            const reservations = Array.isArray(result.data)
                ? result.data
                : [];

            const now = new Date();

            const upcomingReservations = reservations.filter(
                (reservation) => {

                    const appointment = new Date(
                        `${reservation.slot_date}T${reservation.start_time}`
                    );

                    return (
                        reservation.reservation_status === 'active' &&
                        !Number.isNaN(appointment.getTime()) &&
                        appointment >= now
                    );
                }
            );

            const pendingPayments = reservations.filter(
                (reservation) => {
                    return reservation.payment_status === 'pending';
                }
            );

            if (reservationCount) {
                reservationCount.textContent =
                    upcomingReservations.length;
            }

            if (pendingPaymentCount) {
                pendingPaymentCount.textContent =
                    pendingPayments.length;
            }

            clearCountdowns();

            reservationContainer.replaceChildren();

            if (upcomingReservations.length === 0) {

                const emptyMessage = document.createElement('p');

                emptyMessage.className = 'text-sm text-gray-500';

                emptyMessage.textContent =
                    'Belum ada reservasi mendatang.';

                reservationContainer.appendChild(emptyMessage);

                return;
            }

            upcomingReservations.forEach((reservation) => {

                const card = document.createElement('article');

                card.className =
                    'rounded-xl border border-gray-100 p-4 sm:p-5';

                const layout = document.createElement('div');

                layout.className =
                    'flex items-start justify-between gap-4';

                const details = document.createElement('div');

                details.className = 'min-w-0 flex-1 space-y-1';

                const service = document.createElement('p');

                service.className = 'font-semibold text-sm';

                service.textContent =
                    reservation.service_name || 'Layanan';

                const date = document.createElement('p');

                date.className = 'text-sm text-gray-600';

                date.textContent = formatReservationDate(
                    reservation.slot_date,
                    reservation.start_time
                );

                const additional = document.createElement('p');

                additional.className = 'text-xs text-gray-500';

                additional.textContent =
                    reservation.additional_service_name
                        ? `Layanan tambahan: ${reservation.additional_service_name}`
                        : 'Tanpa layanan tambahan';

                const total = Number(reservation.total_payment) ||
                    (
                        Number(reservation.service_price || 0) +
                        Number(reservation.additional_service_price || 0)
                    );

                const payment = document.createElement('p');

                payment.className = 'text-sm font-semibold';

                payment.textContent =
                    `Total: ${formatRupiah(total)}`;

                const status = document.createElement('p');

                status.className = 'text-xs text-gray-500';

                status.textContent =
                    `Status pembayaran: ${
                        reservation.payment_status ||
                        'Belum ada data pembayaran'
                    }`;

                details.append(
                    service,
                    date,
                    additional,
                    payment,
                    status
                );

                const timerPanel = document.createElement('div');

                timerPanel.className =
                    'w-[105px] shrink-0 rounded-lg bg-green-50 border border-green-100 px-2 py-2 text-center';

                const clockIcon = document.createElement('span');

                clockIcon.className =
                    'material-symbols-rounded text-green-700 text-base';

                clockIcon.textContent = 'timer';

                const timer = document.createElement('p');

                timer.className =
                    'text-xl font-bold text-green-700 tabular-nums tracking-tight';

                const timerLabel = document.createElement('p');

                timerLabel.className =
                    'text-[10px] text-gray-500 mt-0.5';

                timerPanel.append(
                    clockIcon,
                    timer,
                    timerLabel
                );

                const hasPaidDeadline =
                    reservation.payment_status === 'paid' &&
                    reservation.cancellation_deadline;

                if (hasPaidDeadline) {

                    startCountdown(
                        reservation.cancellation_deadline,
                        timer,
                        timerLabel
                    );

                } else {

                    timer.textContent = '--:--';

                    timerLabel.textContent = 'Tidak tersedia';
                }

                layout.append(details, timerPanel);

                card.appendChild(layout);

                if (
                    reservation.refund_status &&
                    reservation.refund_status !== 'not_requested'
                ) {

                    const refundStatus = document.createElement('p');

                    refundStatus.className =
                        'text-xs font-semibold text-amber-700 mt-3';

                    refundStatus.textContent =
                        getRefundStatusText(reservation.refund_status);

                    card.appendChild(refundStatus);
                }

                if (canCancelReservation(reservation.can_cancel)) {

                    const cancelButton = document.createElement('button');

                    cancelButton.type = 'button';

                    cancelButton.className =
                        'mt-4 rounded-lg bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-semibold px-4 py-2 transition';

                    cancelButton.textContent = 'Batalkan Reservasi';

                    cancelButton.addEventListener('click', () => {

                        cancelReservation(
                            reservation.reservation_id,
                            cancelButton
                        );
                    });

                    card.appendChild(cancelButton);

                } else if (hasPaidDeadline) {

                    const expiredMessage = document.createElement('p');

                    expiredMessage.className =
                        'mt-3 text-xs text-red-600 font-medium';

                    expiredMessage.textContent =
                        'Periode pembatalan telah berakhir.';

                    card.appendChild(expiredMessage);
                }

                reservationContainer.appendChild(card);
            });

        } catch (error) {

            console.error('Reservation loading error:', error);

            clearCountdowns();

            reservationContainer.textContent =
                'Gagal memuat data reservasi.';
        }
    }

    loadReservations();

});
