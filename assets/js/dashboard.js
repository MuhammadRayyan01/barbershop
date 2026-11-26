
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

        const dateObject = new Date(`${date}T${time}+07:00`);

        if (Number.isNaN(dateObject.getTime())) {
            return `${date} ${time}`;
        }

        return new Intl.DateTimeFormat('id-ID', {
            dateStyle: 'long',

            timeStyle: 'short',

            timeZone: 'Asia/Jakarta'

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
            not_requested: 'Belum ada permintaan pengembalian dana',
            pending: 'Pengembalian dana sedang diproses',
            completed: 'Dana berhasil dikembalikan',
            failed: 'Pengembalian dana gagal, silakan hubungi barbershop'
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

    // Show the custom cancellation confirmation modal.
    function showCancellationConfirmation() {

        return new Promise((resolve) => {

            const overlay = document.createElement('div');

            overlay.className =
                'fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4';

            const modal = document.createElement('div');

            modal.className =
                'w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl';

            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', 'cancel-modal-title');

            const title = document.createElement('h2');

            title.id = 'cancel-modal-title';
            title.className = 'text-lg font-bold text-[#123f3a]';
            title.textContent = 'Batalkan Reservasi?';

            const description = document.createElement('p');

            description.className =
                'mt-4 text-sm leading-relaxed text-gray-600';

            description.textContent =
                'Apakah Anda yakin ingin membatalkan reservasi ini dan mengajukan refund?';

            const actions = document.createElement('div');

            actions.className =
                'mt-4 flex items-center justify-end gap-3';

            const cancelButton = document.createElement('button');

            cancelButton.type = 'button';

            cancelButton.className =
                'rounded-lg px-4 py-2 text-sm font-semibold text-[#123f3a] hover:bg-gray-100 transition';

            cancelButton.textContent = 'Batal';

            const confirmButton = document.createElement('button');

            confirmButton.type = 'button';

            confirmButton.className =
                'rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 transition';

            confirmButton.textContent = 'Ya, Batalkan';

            actions.append(cancelButton, confirmButton);

            modal.append(title, description, actions);

            overlay.appendChild(modal);

            document.body.appendChild(overlay);

            function closeModal(confirmed) {

                document.removeEventListener('keydown', handleKeydown);

                overlay.remove();

                resolve(confirmed);
            }

            function handleKeydown(event) {

                if (event.key === 'Escape') {
                    closeModal(false);
                }
            }

            cancelButton.addEventListener('click', () => {
                closeModal(false);
            });

            confirmButton.addEventListener('click', () => {
                closeModal(true);
            });

            overlay.addEventListener('click', (event) => {

                if (event.target === overlay) {
                    closeModal(false);
                }
            });

            document.addEventListener('keydown', handleKeydown);

            cancelButton.focus();
        });
    }

    // Show a custom success or error notification modal.
    function showCancellationMessage(
        message,
        titleText = 'Pemberitahuan'
    ) {

        return new Promise((resolve) => {

            const overlay = document.createElement('div');

            overlay.className =
                'fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4';

            const modal = document.createElement('div');

            modal.className =
                'w-full max-w-sm rounded-2xl bg-white p-6 shadow-xl';

            modal.setAttribute('role', 'alertdialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', 'cancel-message-title');

            const title = document.createElement('h2');

            title.id = 'cancel-message-title';
            title.className = 'text-lg font-bold text-[#123f3a]';
            title.textContent = titleText;

            const description = document.createElement('p');

            description.className =
                'mt-4 text-sm leading-relaxed text-gray-600';

            description.textContent = message;

            const actions = document.createElement('div');

            actions.className =
                'mt-4 flex items-center justify-end';

            const okButton = document.createElement('button');

            okButton.type = 'button';

            okButton.className =
                'rounded-lg bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700 transition';

            okButton.textContent = 'OK';

            actions.appendChild(okButton);

            modal.append(title, description, actions);

            overlay.appendChild(modal);

            document.body.appendChild(overlay);

            function closeModal() {

                document.removeEventListener('keydown', handleKeydown);

                overlay.remove();

                resolve();
            }

            function handleKeydown(event) {

                if (event.key === 'Escape' || event.key === 'Enter') {
                    closeModal();
                }
            }

            okButton.addEventListener('click', closeModal);

            overlay.addEventListener('click', (event) => {

                if (event.target === overlay) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', handleKeydown);

            okButton.focus();
        });
    }

    // Describe the outcome of a cancellation in the customer's own language.
    function getCancellationOutcome(result) {

        if (
            result.payment_status === 'refunded' ||
            result.refund_status === 'completed'
        ) {
            return {
                title: 'Pembatalan Berhasil',
                message: 'Dana berhasil dikembalikan.'
            };
        }

        if (result.refund_method === 'manual') {
            return {
                title: 'Pembatalan Berhasil',
                message: 'Reservasi dibatalkan. Pengembalian dana akan dikirim manual oleh barbershop.'
            };
        }

        if (result.refund_status === 'pending') {
            return {
                title: 'Permintaan Diproses',
                message: 'Permintaan pembatalan diproses. Pengembalian dana sedang diproses.'
            };
        }

        if (result.payment_status === 'cancelled') {
            return {
                title: 'Pembatalan Berhasil',
                message: 'Pembayaran dibatalkan. Bank Anda mungkin memerlukan hingga satu hari kerja untuk mengembalikan limit kartu.'
            };
        }

        return {
            title: 'Pembatalan Berhasil',
            message: result.message ||
                'Permintaan pembatalan berhasil dikirim.'
        };
    }

    // Send the cancellation request to the backend.
    // isRetry re-checks a refund that is already pending; the backend reuses
    // the stored refund key, so it never creates a second refund.
    async function cancelReservation(reservationId, button, isRetry = false) {

        if (!csrfToken) {
            alert('Token keamanan tidak ditemukan. Silakan muat ulang halaman.');
            return;
        }

        if (!isRetry) {

            const confirmed = await showCancellationConfirmation();

            if (!confirmed) {
                return;
            }
        }

        const originalLabel = button.textContent;

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

            const outcome = getCancellationOutcome(result);

            await showCancellationMessage(
                outcome.message,
                outcome.title
            );

            await loadReservations();

        } catch (error) {

            console.error('Cancellation error:', error);

            await showCancellationMessage(
                error.message ||
                'Terjadi kesalahan saat membatalkan reservasi.',
                'Pembatalan Gagal'
            );

            button.disabled = false;

            button.textContent = originalLabel;
        }
    }

    // Load the customer's reservation data.
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

            const upcomingReservations = reservations
                .filter((reservation) => {
                    const appointment = new Date(
                        `${reservation.slot_date}T${reservation.start_time}+07:00`
                    );

                    return (
                        reservation.reservation_status === 'active' &&
                        !reservation.admin_cancelled_at &&
                        !Number.isNaN(appointment.getTime()) &&
                        appointment >= now
                    );
                })
                .sort((a, b) => {
                    const timeA = new Date(`${a.slot_date}T${a.start_time}+07:00`);
                    const timeB = new Date(`${b.slot_date}T${b.start_time}+07:00`);
                    return timeA - timeB;
                });

            const adminCancelledReservations = reservations
                .filter((reservation) => Boolean(reservation.admin_cancelled_at))
                .sort((a, b) => String(b.admin_cancelled_at).localeCompare(String(a.admin_cancelled_at)));

            // Keep the dashboard concise while showing recent admin cancellations
            // alongside the next few active appointments.
            const dashboardReservations = [
                ...adminCancelledReservations.slice(0, 3),
                ...upcomingReservations.slice(0, 3)
            ];

            const pendingPayments = reservations.filter((reservation) => (
                reservation.reservation_status === 'active' &&
                reservation.payment_status === 'pending'
            ));

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

            if (dashboardReservations.length === 0) {
                const emptyState = document.createElement('div');
                emptyState.className = 'rounded-2xl bg-[#f8fbf9] px-5 py-8 text-center';

                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'text-sm text-gray-600';
                emptyMessage.textContent = 'Belum ada jadwal mendatang. Saat siap, pilih layanan dan waktu yang cocok untukmu.';

                emptyState.appendChild(emptyMessage);
                reservationContainer.appendChild(emptyState);

                return;
            }

            if (upcomingReservations.length > 0) {
                const nextReservation = upcomingReservations[0];
                const nextAppointment = new Date(
                    `${nextReservation.slot_date}T${nextReservation.start_time}+07:00`
                );
                const hoursUntilNextAppointment = (nextAppointment - now) / (1000 * 60 * 60);

                if (hoursUntilNextAppointment <= 24) {
                    const reminder = document.createElement('div');
                    reminder.setAttribute('role', 'status');
                    reminder.className = 'mb-3 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900';

                    const reminderTitle = document.createElement('p');
                    reminderTitle.className = 'font-bold';
                    reminderTitle.textContent = 'Pengingat reservasi';

                    const reminderText = document.createElement('p');
                    reminderText.className = 'mt-1';
                    reminderText.textContent = `${nextReservation.service_name || 'Reservasi Anda'} dijadwalkan ${formatReservationDate(nextReservation.slot_date, nextReservation.start_time)}. Silakan datang 10 menit lebih awal.`;

                    reminder.append(reminderTitle, reminderText);
                    reservationContainer.appendChild(reminder);
                }
            }

            dashboardReservations.forEach((reservation) => {
                const adminCancelled = Boolean(reservation.admin_cancelled_at);

                const card = document.createElement('article');

                card.className = adminCancelled
                    ? 'rounded-xl border border-amber-200 bg-amber-50/50 p-4 sm:p-5'
                    : 'rounded-xl border border-gray-100 p-4 sm:p-5';

                const layout = document.createElement('div');

                layout.className =
                    'flex items-start justify-between gap-4';

                const details = document.createElement('div');

                details.className = 'min-w-0 flex-1 space-y-1';

                const service = document.createElement('p');

                service.className = 'font-bold text-[#0d3b36]';

                service.textContent =
                    reservation.service_name || 'Layanan';

                const date = document.createElement('p');

                date.className = 'text-sm text-gray-600';

                date.textContent = formatReservationDate(
                    reservation.slot_date,
                    reservation.start_time
                );

                const additional = document.createElement('p');

                additional.className = 'mt-1 text-xs text-gray-500';

                additional.textContent =
                    reservation.additional_service_name
                        ? `Layanan tambahan: ${reservation.additional_service_name}`
                        : 'Tanpa layanan tambahan';

                const total = Number(reservation.total_payment) ||
                    (
                        Number(reservation.service_price || 0) +
                        Number(reservation.additional_service_price || 0)
                    );


                const actions = document.createElement('div');
                actions.className = 'flex items-center justify-between gap-4 sm:justify-end';

                const amount = document.createElement('p');
                amount.className = 'text-sm font-bold text-[#0d3b36]';
                amount.textContent = formatRupiah(total);

                    const paymentStatuses = {
                    pending: 'Menunggu pembayaran',
                    paid: 'Pembayaran berhasil',
                    cancelled: 'Pembayaran dibatalkan',
                    refunded: 'Pembayaran telah dikembalikan',
                    failed: 'Pembayaran gagal',
                    'verification pending': 'Pembayaran sedang diverifikasi'
                };

                const status = document.createElement('p');

                status.className = 'text-xs text-gray-500';

                status.textContent =
                    `Status pembayaran: ${
                        paymentStatuses[reservation.payment_status] ||
                        'Status pembayaran belum tersedia'
                    }`;

                details.append(
                    service,
                    date,
                    additional,
                    status
                );

                const refundComplete = reservation.refund_status === 'completed' ||
                    reservation.payment_status === 'refunded';

                if (adminCancelled && !refundComplete) {
                    const notice = document.createElement('p');
                    notice.className = 'mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900';
                    if (reservation.refund_status === 'pending' && reservation.refund_method === 'manual') {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Pengembalian dana sedang diproses oleh barbershop.';
                    } else if (reservation.refund_status === 'pending') {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Pembayaran akan dikembalikan ke bank atau e-wallet yang Anda gunakan.';
                    } else if (reservation.admin_cancel_outcome === 'capture_voided') {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Pembayaran akan dikembalikan ke bank atau e-wallet yang Anda gunakan.';
                    } else if (reservation.admin_cancel_outcome === 'authorization_voided') {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Pembayaran akan dikembalikan ke bank atau e-wallet yang Anda gunakan.';
                    } else if (reservation.admin_cancel_outcome === 'pending_cancelled') {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Jika Anda sudah membayar, dana akan dikembalikan ke bank atau e-wallet yang Anda gunakan.';
                    } else if (reservation.admin_cancel_outcome === 'no_payment') {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Tidak ada pembayaran yang perlu dikembalikan.';
                    } else if (reservation.payment_status === 'cancelled' && reservation.transaction_reference) {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Pembayaran akan dikembalikan ke bank atau e-wallet yang Anda gunakan.';
                    } else if (reservation.payment_status === 'cancelled' || reservation.payment_status === 'failed' || reservation.payment_status === 'pending') {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Tidak ada pembayaran yang perlu dikembalikan.';
                    } else {
                        notice.textContent = 'Reservasi Anda dibatalkan oleh admin. Pengembalian dana akan diproses.';
                    }
                    details.appendChild(notice);
                }

                if (!adminCancelled && reservation.schedule_changed_at) {
                    const scheduleNotice = document.createElement('p');
                    scheduleNotice.className = 'mt-3 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-sm text-amber-900';
                    const oldDate = reservation.old_slot_date && reservation.old_start_time
                        ? ` dari ${formatReservationDate(reservation.old_slot_date, reservation.old_start_time)}`
                        : '';
                    const reason = reservation.schedule_change_reason
                        ? ` Alasan: ${reservation.schedule_change_reason}.`
                        : '';
                    scheduleNotice.textContent = `Jadwal reservasi ini diubah oleh barbershop${oldDate}. Gunakan jadwal terbaru di atas.${reason}`;
                    details.appendChild(scheduleNotice);
                }

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

                const hasPaidDeadline = !adminCancelled &&
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

                if (adminCancelled) {
                    layout.append(details);
                } else {
                    layout.append(details, timerPanel);
                }

                card.appendChild(layout);

                const detailLink = document.createElement('a');
                detailLink.href = `../booking/success.php?reservation_id=${encodeURIComponent(reservation.reservation_id)}`;
                detailLink.className = 'rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50';
                detailLink.textContent = 'Detail';
                actions.append(amount, detailLink);
                card.appendChild(actions);

                if (
                    reservation.refund_status &&
                    reservation.refund_status !== 'not_requested'
                ) {

                    const refundStatus = document.createElement('p');

                    refundStatus.className =
                                'text-xs font-semibold mt-3';

                            if (reservation.refund_status === 'completed') {
                                refundStatus.classList.add('text-green-700');
                            } else if (reservation.refund_status === 'failed') {
                                refundStatus.classList.add('text-red-600');
                            } else {
                                refundStatus.classList.add('text-amber-700');
}

                    refundStatus.textContent =
                        reservation.refund_method === 'manual'
                            ? 'Refund manual menunggu pengiriman oleh barbershop'
                            : getRefundStatusText(reservation.refund_status);

                    card.appendChild(refundStatus);
                }

                if (!adminCancelled && canCancelReservation(reservation.can_cancel)) {

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

                } else if (!adminCancelled && canCancelReservation(reservation.can_retry_refund)) {

                    // The refund was requested but is not confirmed yet. Checking
                    // again is safe: the backend reuses the stored refund key.
                    const retryButton = document.createElement('button');

                    retryButton.type = 'button';

                    retryButton.className =
                        'mt-3 rounded-lg border border-amber-200 bg-amber-50 text-amber-800 disabled:opacity-50 text-sm font-semibold px-4 py-2 transition';

                    retryButton.textContent = 'Periksa Status Pengembalian Dana';

                    retryButton.addEventListener('click', () => {

                        cancelReservation(
                            reservation.reservation_id,
                            retryButton,
                            true
                        );
                    });

                    card.appendChild(retryButton);

                } else if (
                    hasPaidDeadline &&
                    reservation.refund_status === 'not_requested'
                ) {

                    const expiredMessage = document.createElement('p');

                    expiredMessage.className =
                        'mt-3 text-xs text-red-600 font-medium';

                    expiredMessage.textContent =
                        'Periode pembatalan telah berakhir.';

                    card.appendChild(expiredMessage);
                }

                reservationContainer.appendChild(card);
            });

            if (upcomingReservations.length > 3 || adminCancelledReservations.length > 3) {
                const allLink = document.createElement('a');
                allLink.href = 'reservations.php';
                allLink.className = 'inline-flex pt-1 text-sm font-semibold text-[#0d3b36] hover:underline';
                allLink.textContent = 'Lihat semua reservasi';
                reservationContainer.appendChild(allLink);
            }


        } catch (error) {

            console.error('Reservation loading error:', error);

            clearCountdowns();

            reservationContainer.replaceChildren();
            const errorMessage = document.createElement('p');
            errorMessage.className = 'rounded-xl bg-red-50 p-4 text-sm text-red-700';
            errorMessage.textContent = 'Jadwal reservasi gagal dimuat. Coba muat ulang halaman.';
            reservationContainer.appendChild(errorMessage);
        }
    }

    loadReservations();

});
