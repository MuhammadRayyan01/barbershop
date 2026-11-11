
document.addEventListener('DOMContentLoaded', () => {

    const dashboardName = document.getElementById('dashboard-nama');

    const accountBtn = document.getElementById('akun-btn');
    const accountMenu = document.getElementById('akun-menu');

    const accountFirstName = document.getElementById('akun-nama-depan');
    const accountName = document.getElementById('akun-nama');
    const accountPhone = document.getElementById('akun-hp');
    const accountEmail = document.getElementById('akun-email');

    const accountEditBtn = document.getElementById('akun-edit-btn');
    const accountModal = document.getElementById('akun-modal');
    const accountForm = document.getElementById('akun-form');
    const accountCancelBtn = document.getElementById('akun-batal');

    const inputName = document.getElementById('akun-input-nama');
    const inputPhone = document.getElementById('akun-input-hp');
    const inputEmail = document.getElementById('akun-input-email');

    // Password fields
    const inputCurrentPassword = document.getElementById(
        'akun-input-current-password'
    );

    const inputPassword = document.getElementById(
        'akun-input-password'
    );

    const inputConfirmPassword = document.getElementById(
        'akun-input-confirm-password'
    );

    const accountError = document.getElementById('akun-error');

    const reservationContainer = document.getElementById(
        'reservasi-container'
    );

    const reservationCount = document.getElementById(
        'jumlah-reservasi'
    );

    const pendingPaymentCount = document.getElementById(
        'jumlah-pembayaran'
    );

    const logoutButton = document.getElementById('logout-btn');

    const csrfToken = document.querySelector(
        'meta[name="csrf-token"]'
    )?.content;

    if (
        !accountBtn ||
        !accountMenu ||
        !accountModal ||
        !accountForm
    ) {
        console.error('Dashboard account elements are missing.');
        return;
    }

    if (
        !inputName ||
        !inputPhone ||
        !inputEmail ||
        !inputCurrentPassword ||
        !inputPassword ||
        !inputConfirmPassword ||
        !accountError
    ) {
        console.error('Profile form elements are missing.');
        return;
    }


    let customer = null;

    function displayCustomer(profile) {

        customer = profile;

        const fullName = profile.full_name || 'Customer';

        const firstName = fullName.trim().split(/\s+/)[0];

        if (dashboardName) {
            dashboardName.textContent = fullName;
        }

        // Account dropdown first name
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

    async function loadProfile() {

        try {

            const response = await fetch(
                '../../backend/api/profile.php',
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
                    result.message || 'Unable to load profile.'
                );
            }

            displayCustomer(result.data);

        } catch (error) {

            console.error('Profile loading error:', error);

            if (dashboardName) {
                dashboardName.textContent = 'Unable to load profile.';
            }
        }
    }

    accountBtn.addEventListener('click', (event) => {

        event.stopPropagation();

        const isHidden = accountMenu.classList.toggle('hidden');

        accountBtn.setAttribute(
            'aria-expanded',
            String(!isHidden)
        );
    });

    document.addEventListener('click', (event) => {

        if (
            !accountMenu.contains(event.target) &&
            !accountBtn.contains(event.target)
        ) {

            accountMenu.classList.add('hidden');

            accountBtn.setAttribute('aria-expanded', 'false');
        }
    });

    function openProfileModal() {

        if (!customer) {
            return;
        }

        inputName.value = customer.full_name || '';

        inputPhone.value = customer.phone_number || '';

        inputEmail.value = customer.email || '';

        inputCurrentPassword.value = '';

        inputPassword.value = '';

        inputConfirmPassword.value = '';

        accountError.textContent = '';

        accountError.classList.add('hidden');

        accountMenu.classList.add('hidden');

        accountBtn.setAttribute('aria-expanded', 'false');

        accountModal.classList.remove('hidden');

        accountModal.classList.add('flex');
    }

    function closeProfileModal() {

        accountModal.classList.add('hidden');

        accountModal.classList.remove('flex');

        accountForm.reset();

        accountError.textContent = '';

        accountError.classList.add('hidden');
    }


    accountEditBtn?.addEventListener(
        'click',
        openProfileModal
    );

    accountCancelBtn?.addEventListener(
        'click',
        closeProfileModal
    );

    accountModal.addEventListener('click', (event) => {

        if (event.target === accountModal) {
            closeProfileModal();
        }
    });

    function showProfileError(message) {

        accountError.textContent = message;

        accountError.classList.remove('hidden');
    }

    accountForm.addEventListener('submit', async (event) => {

        event.preventDefault();

        const fullName = inputName.value.trim();

        const phoneNumber = inputPhone.value.trim();

        const email = inputEmail.value.trim();

        const currentPassword = inputCurrentPassword.value;

        const newPassword = inputPassword.value;

        const confirmPassword = inputConfirmPassword.value;


        accountError.classList.add('hidden');

        accountError.textContent = '';

        if (fullName.length < 3) {

            showProfileError(
                'Full name must contain at least 3 characters.'
            );

            return;
        }

        if (phoneNumber.replace(/\D/g, '').length < 9) {

            showProfileError(
                'Please enter a valid phone number.'
            );

            return;
        }

        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if (!emailPattern.test(email)) {

            showProfileError(
                'Please enter a valid email address.'
            );

            return;
        }

        const passwordChangeRequested =
            currentPassword !== '' ||
            newPassword !== '' ||
            confirmPassword !== '';


        if (passwordChangeRequested) {

            if (currentPassword === '') {

                showProfileError(
                    'Please enter your current password.'
                );

                return;
            }

            if (newPassword === '') {

                showProfileError(
                    'Please enter your new password.'
                );

                return;
            }

            if (newPassword.length <= 12) {

                showProfileError(
                    'New password must contain at least 13 characters.'
                );

                return;
            }

            if (newPassword.length > 72) {

                showProfileError(
                    'New password must not exceed 72 characters.'
                );

                return;
            }

            if (confirmPassword === '') {

                showProfileError(
                    'Please confirm your new password.'
                );

                return;
            }

            if (newPassword !== confirmPassword) {

                showProfileError(
                    'New password and confirmation password do not match.'
                );

                return;
            }
        }

        if (!csrfToken) {

            showProfileError(
                'Security token is missing. Please reload the page.'
            );

            return;
        }

        const submitButton = accountForm.querySelector(
            'button[type="submit"]'
        );

        if (submitButton) {

            submitButton.disabled = true;

            submitButton.textContent = 'Saving...';
        }

        const requestData = {

            full_name: fullName,

            phone_number: phoneNumber,

            email: email
        };

        if (passwordChangeRequested) {

            requestData.current_password = currentPassword;

            requestData.new_password = newPassword;
        }

        try {

            const response = await fetch(
                '../../backend/api/profile.php',
                {
                    method: 'PATCH',

                    headers: {

                        'Content-Type': 'application/json',

                        'Accept': 'application/json',

                        'X-CSRF-Token': csrfToken
                    },

                    credentials: 'same-origin',

                    body: JSON.stringify(requestData)
                }
            );


            const result = await response.json();

            if (response.status === 401) {

                window.location.href = '../login.php';

                return;
            }

            if (!response.ok || !result.success) {

                throw new Error(
                    result.message || 'Profile update failed.'
                );
            }

            displayCustomer(result.data);


            // Clear password fields
            inputCurrentPassword.value = '';

            inputPassword.value = '';

            inputConfirmPassword.value = '';

            closeProfileModal();


            alert(
                'Your profile has been updated successfully.'
            );


        } catch (error) {

            console.error(
                'Profile update error:',
                error
            );

            showProfileError(error.message);


        } finally {

            if (submitButton) {

                submitButton.disabled = false;

                submitButton.textContent = 'Simpan Perubahan';
            }
        }

    });

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

    loadProfile();

    loadReservations();

    logoutButton?.addEventListener('click', async (event) => {

        // Prevent normal link navigation
        event.preventDefault();


        logoutButton.disabled = true;

        logoutButton.textContent = 'Keluar...';


        try {

            const response = await fetch(
                '../../backend/auth/logout.php',
                {
                    method: 'POST',

                    credentials: 'same-origin',

                    headers: {

                        'Accept': 'application/json',

                        ...(csrfToken
                            ? { 'X-CSRF-Token': csrfToken }
                            : {})
                    }
                }
            );


            const result = await response.json();


            if (!response.ok || !result.success) {

                throw new Error(
                    result.message || 'Logout failed.'
                );
            }


            window.location.href = '../login.php';


        } catch (error) {

            console.error(
                'Logout error:',
                error
            );

            alert(
                'Gagal keluar. Silakan coba lagi.'
            );


            logoutButton.disabled = false;

            logoutButton.textContent = 'Keluar';
        }

    });

});
