
document.addEventListener('DOMContentLoaded', () => {
    // Dashboard elements
    const dashboardName = document.getElementById('dashboard-nama');

    // Account dropdown
    const accountBtn = document.getElementById('akun-btn');
    const accountMenu = document.getElementById('akun-menu');
    const accountFirstName = document.getElementById('akun-nama-depan');
    const accountName = document.getElementById('akun-nama');
    const accountPhone = document.getElementById('akun-hp');
    const accountEmail = document.getElementById('akun-email');

    // Profile modal
    const accountEditBtn = document.getElementById('akun-edit-btn');
    const accountModal = document.getElementById('akun-modal');
    const accountForm = document.getElementById('akun-form');
    const accountCancelBtn = document.getElementById('akun-batal');

    const inputName = document.getElementById('akun-input-nama');
    const inputPhone = document.getElementById('akun-input-hp');
    const inputEmail = document.getElementById('akun-input-email');
    const accountError = document.getElementById('akun-error');

    // Reservation elements
    const reservationContainer = document.getElementById('reservasi-container');
    const reservationCount = document.getElementById('jumlah-reservasi');
    const pendingPaymentCount = document.getElementById('jumlah-pembayaran');

    // Logout
    const logoutButton = document.getElementById('logout-btn');

    // CSRF token
    const csrfToken = document.querySelector(
        'meta[name="csrf-token"]'
    )?.content;

    // Check required account elements
    if (!accountBtn || !accountMenu || !accountModal || !accountForm) {
        console.error('Dashboard account elements are missing.');
        return;
    }

    let customer = null;

    // Display customer profile
    function displayCustomer(profile) {
        customer = profile;

        const fullName = profile.full_name || 'Customer';
        const firstName = fullName.trim().split(/\s+/)[0];

        if (dashboardName) {
            dashboardName.textContent = fullName;
        }

        if (accountFirstName) {
            accountFirstName.textContent = firstName;
        }

        if (accountName) {
            accountName.textContent = fullName;
        }

        if (accountPhone) {
            accountPhone.textContent = profile.phone_number || '';
        }

        if (accountEmail) {
            accountEmail.textContent = profile.email || '';
        }

        const initial = accountBtn.querySelector('span');

        if (initial) {
            initial.textContent = firstName.charAt(0).toUpperCase();
        }
    }

    // Load profile
    async function loadProfile() {
        try {
            const response = await fetch('../../backend/api/profile.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            const result = await response.json();

            if (response.status === 401) {
                window.location.href = '../login.php';
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to load profile.');
            }

            displayCustomer(result.data);

        } catch (error) {
            console.error('Profile loading error:', error);

            if (dashboardName) {
                dashboardName.textContent = 'Unable to load profile.';
            }
        }
    }

    // Account dropdown - only one click handler
    accountBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        accountMenu.classList.toggle('hidden');
    });

    document.addEventListener('click', (event) => {
        if (
            !accountMenu.contains(event.target) &&
            !accountBtn.contains(event.target)
        ) {
            accountMenu.classList.add('hidden');
        }
    });

    // Open profile modal
    function openProfileModal() {
        if (!customer) {
            return;
        }

        inputName.value = customer.full_name || '';
        inputPhone.value = customer.phone_number || '';
        inputEmail.value = customer.email || '';

        accountError.textContent = '';
        accountError.classList.add('hidden');

        accountMenu.classList.add('hidden');
        accountModal.classList.remove('hidden');
        accountModal.classList.add('flex');
    }

    // Close profile modal
    function closeProfileModal() {
        accountModal.classList.add('hidden');
        accountModal.classList.remove('flex');
    }

    accountEditBtn?.addEventListener('click', openProfileModal);
    accountCancelBtn?.addEventListener('click', closeProfileModal);

    accountModal.addEventListener('click', (event) => {
        if (event.target === accountModal) {
            closeProfileModal();
        }
    });

    // Display profile errors
    function showProfileError(message) {
        accountError.textContent = message;
        accountError.classList.remove('hidden');
    }

    // Update profile
    accountForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        const fullName = inputName.value.trim();
        const phoneNumber = inputPhone.value.trim();
        const email = inputEmail.value.trim();

        accountError.classList.add('hidden');

        if (fullName.length < 3) {
            showProfileError('Full name must contain at least 3 characters.');
            return;
        }

        if (phoneNumber.replace(/\D/g, '').length < 9) {
            showProfileError('Please enter a valid phone number.');
            return;
        }

        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showProfileError('Please enter a valid email address.');
            return;
        }

        if (!csrfToken) {
            showProfileError('Please reload the page.');
            return;
        }

        const submitButton = accountForm.querySelector(
            'button[type="submit"]'
        );

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';
        }

        try {
            const response = await fetch('../../backend/api/profile.php', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    full_name: fullName,
                    phone_number: phoneNumber,
                    email: email
                })
            });

            const result = await response.json();

            if (response.status === 401) {
                window.location.href = '../login.php';
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Profile update failed.');
            }

            displayCustomer(result.data);
            closeProfileModal();

            alert('Your profile has been updated successfully.');

        } catch (error) {
            console.error('Profile update error:', error);
            showProfileError(error.message);

        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = 'Simpan Perubahan';
            }
        }
    });

    // Format currency
    function formatRupiah(value) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(Number(value) || 0);
    }

    // Format reservation date
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

    // Load reservations
    async function loadReservations() {
        if (!reservationContainer) {
            return;
        }

        try {
            const response = await fetch('../../backend/api/reservation.php', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            const result = await response.json();

            if (response.status === 401) {
                window.location.href = '../login.php';
                return;
            }

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Unable to load reservation data.'
                );
            }

            const reservations = Array.isArray(result.data)
                ? result.data
                : [];

            const now = new Date();

            // Upcoming active reservations
            const upcomingReservations = reservations.filter((reservation) => {
                const appointment = new Date(
                    `${reservation.slot_date}T${reservation.start_time}`
                );

                return (
                    reservation.reservation_status === 'active' &&
                    !Number.isNaN(appointment.getTime()) &&
                    appointment >= now
                );
            });

            // Pending payments
            const pendingPayments = reservations.filter((reservation) => {
                return reservation.payment_status === 'pending';
            });

            if (reservationCount) {
                reservationCount.textContent = upcomingReservations.length;
            }

            if (pendingPaymentCount) {
                pendingPaymentCount.textContent = pendingPayments.length;
            }

            reservationContainer.replaceChildren();

            if (upcomingReservations.length === 0) {
                const emptyMessage = document.createElement('p');
                emptyMessage.className = 'text-sm text-gray-500';
                emptyMessage.textContent = 'Belum ada reservasi mendatang.';

                reservationContainer.appendChild(emptyMessage);
                return;
            }

            // Reservation cards
            upcomingReservations.forEach((reservation) => {
                const card = document.createElement('article');
                card.className = 'rounded-xl border border-gray-100 p-4 space-y-2';

                const service = document.createElement('p');
                service.className = 'font-semibold text-sm';
                service.textContent = reservation.service_name || 'Layanan';

                const date = document.createElement('p');
                date.className = 'text-sm text-gray-600';
                date.textContent = formatReservationDate(
                    reservation.slot_date,
                    reservation.start_time
                );

                const additional = document.createElement('p');
                additional.className = 'text-xs text-gray-500';
                additional.textContent = reservation.additional_service_name
                    ? `Layanan tambahan: ${reservation.additional_service_name}`
                    : 'Tanpa layanan tambahan';

                const total = Number(reservation.total_payment) ||
                    (
                        Number(reservation.service_price || 0) +
                        Number(reservation.additional_service_price || 0)
                    );

                const payment = document.createElement('p');
                payment.className = 'text-sm font-semibold';
                payment.textContent = `Total: ${formatRupiah(total)}`;

                const status = document.createElement('p');
                status.className = 'text-xs text-gray-500';
                status.textContent = `Status pembayaran: ${
                    reservation.payment_status || 'Belum ada data pembayaran'
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
            console.error('Reservation loading error:', error);
            reservationContainer.textContent = 'Gagal memuat data reservasi.';
        }
    }

    // Load dashboard data
    loadProfile();
    loadReservations();

    // Logout
    logoutButton?.addEventListener('click', async () => {
        logoutButton.disabled = true;
        logoutButton.textContent = 'Keluar...';

        try {
            const response = await fetch('../../backend/auth/logout.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Logout failed.');
            }

            window.location.href = '../login.php';

        } catch (error) {
            console.error('Logout error:', error);
            alert('Gagal keluar. Silakan coba lagi.');

            logoutButton.disabled = false;
            logoutButton.textContent = 'Keluar';
        }
    });
});
