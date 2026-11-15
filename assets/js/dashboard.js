// Dashboard-only JavaScript.
// The account dropdown, profile popup and logout are handled by assets/js/app.js
// (loaded by includes/header.php), so they are NOT repeated here.
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

    // Show the customer's name in the welcome message (profile is loaded by app.js).
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
                    'rounded-xl border border-gray-100 p-4 space-y-2';

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

                card.append(

                    service,

                    date,

                    additional,

                    payment,

                    status
                );
                reservationContainer.appendChild(card);

            });


        } catch (error) {

            console.error(
                'Reservation loading error:',
                error
            );

            reservationContainer.textContent =
                'Gagal memuat data reservasi.';
        }
    }

    loadReservations();

});