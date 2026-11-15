
(() => {
    'use strict';

    const dateInput = document.getElementById('booking-date');
    const timeGroup = document.getElementById('time-group');
    const bookingForm = document.getElementById('booking-form');

    if (!dateInput || !timeGroup || !bookingForm) return;

    const API_ENDPOINT = '../../backend/api/booking.php';
    const HOLD_STORAGE_KEY = 'pemon_booking_hold';
    const REFRESH_INTERVAL = 30000;

    const selectedServiceInput =
        document.getElementById('selected-service-id');

    const selectedSlotInput =
        document.getElementById('selected-slot-id');

    const continueButton =
        document.getElementById('continue-booking');

    const summaryText =
        document.getElementById('summary-text');

    const serviceButtons =
        document.querySelectorAll('.service-option');

    const csrfInput =
        bookingForm.querySelector('input[name="csrf_token"]');

    const state = {
        date: '',
        time: '',
        slotId: '',
        serviceId: '',
        availability: [],
        activeHold: null,
        loading: false,
        creatingHold: false,
        releasingHold: false,
        refreshTimer: null,
        holdTimer: null
    };

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    function getLocalDate() {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Asia/Jakarta',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).formatToParts(new Date());

        const values = {};

        parts.forEach(part => {
            values[part.type] = part.value;
        });

        return `${values.year}-${values.month}-${values.day}`;
    }

    function formatDate(date) {
        return new Intl.DateTimeFormat('id-ID', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC'
        }).format(new Date(`${date}T00:00:00Z`));
    }

    function formatRupiah(amount) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(Number(amount) || 0);
    }

    function formatTime(time) {
        return String(time || '').slice(0, 5);
    }

    function showMessage(message, type = 'error') {
        let element = document.getElementById('booking-message');

        if (!element) {
            element = document.createElement('p');
            element.id = 'booking-message';
            element.className = 'rounded-xl px-4 py-3 text-sm';
            bookingForm.prepend(element);
        }

        element.textContent = message;

        element.className = type === 'success'
            ? 'rounded-xl bg-green-50 px-4 py-3 text-sm text-green-700'
            : 'rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700';
    }

    function clearMessage() {
        document.getElementById('booking-message')?.remove();
    }

    function saveHoldToSession(hold) {
        sessionStorage.setItem(
            HOLD_STORAGE_KEY,
            JSON.stringify({
                hold_token: hold.hold_token,
                hold_id: hold.hold_id,
                expires_at: hold.expires_at,
                total_amount: hold.total_amount
            })
        );
    }

    function clearHoldFromSession() {
        sessionStorage.removeItem(HOLD_STORAGE_KEY);
    }

    function updateSummary() {
        if (summaryText) {
            summaryText.textContent =
                state.date && state.time
                    ? `${formatDate(state.date)} pukul ${state.time} WIB.`
                    : 'Pilih tanggal dan waktu reservasi.';
        }

        updateContinueButton();
    }

    function updateContinueButton() {
        if (!continueButton) return;

        const ready = Boolean(
            state.serviceId &&
            state.date &&
            state.slotId
        );

        continueButton.hidden = Boolean(state.activeHold);

        continueButton.disabled =
            !ready ||
            state.loading ||
            state.creatingHold ||
            state.releasingHold;

        continueButton.textContent = state.creatingHold
            ? 'Menyiapkan reservasi...'
            : 'Lanjut ke Pembayaran →';
    }

    function setBookingControlsDisabled(disabled) {
        dateInput.disabled = disabled;

        serviceButtons.forEach(button => {
            button.disabled = disabled;
        });

        timeGroup.querySelectorAll('button.time-btn').forEach(button => {
            button.disabled = disabled || button.dataset.available !== 'true';
        });
    }

    function selectServiceButton(serviceId) {
        serviceButtons.forEach(button => {
            const selected =
                String(button.dataset.serviceId) === String(serviceId);

            button.setAttribute('aria-pressed', selected ? 'true' : 'false');

            button.classList.remove(
                'border-2',
                'border-[#0d3b36]',
                'border',
                'border-gray-200'
            );

            if (selected) {
                button.classList.add(
                    'border-2',
                    'border-[#0d3b36]'
                );
            } else {
                button.classList.add(
                    'border',
                    'border-gray-200'
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | API request helper
    |--------------------------------------------------------------------------
    */

    async function apiRequest(url, options = {}) {
        const response = await fetch(url, {
            ...options,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                ...(options.headers || {})
            }
        });

        let payload;

        try {
            payload = await response.json();
        } catch {
            throw new Error('Server returned an invalid response.');
        }

        if (!response.ok || !payload.success) {
            throw new Error(
                payload.message || 'The request could not be completed.'
            );
        }

        return payload;
    }

    async function postBookingAction(body) {
        if (!csrfInput?.value) {
            throw new Error(
                'Token keamanan tidak ditemukan. Muat ulang halaman.'
            );
        }

        return apiRequest(API_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfInput.value
            },
            body: JSON.stringify(body)
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Existing booking hold panel
    |--------------------------------------------------------------------------
    */

    function createHoldPanel() {
        let panel = document.getElementById('active-booking-panel');

        if (panel) return panel;

        panel = document.createElement('section');
        panel.id = 'active-booking-panel';
        panel.className =
            'mb-6 rounded-2xl border border-green-200 bg-white p-5 shadow-sm';

        bookingForm.prepend(panel);

        return panel;
    }

    function addDetailRow(container, label, value) {
        const row = document.createElement('div');
        row.className = 'flex items-start justify-between gap-4 py-2';

        const labelElement = document.createElement('span');
        labelElement.className = 'text-sm text-gray-500';
        labelElement.textContent = label;

        const valueElement = document.createElement('span');
        valueElement.className =
            'text-right text-sm font-semibold text-gray-900';
        valueElement.textContent = value || '-';

        row.append(labelElement, valueElement);
        container.append(row);
    }

    function renderActiveHoldPanel() {
        const hold = state.activeHold;

        if (!hold) return;

        const panel = createHoldPanel();
        panel.replaceChildren();

        const heading = document.createElement('div');
        heading.className = 'mb-4 flex items-center justify-between gap-3';

        const title = document.createElement('h2');
        title.className = 'text-lg font-bold text-gray-900';
        title.textContent = 'Booking Anda Sebelumnya';

        const badge = document.createElement('span');
        badge.className =
            'rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700';
        badge.textContent = 'Menunggu pembayaran';

        heading.append(title, badge);
        panel.append(heading);

        const details = document.createElement('div');
        details.className = 'divide-y divide-gray-100';

        addDetailRow(details, 'Layanan', hold.service_name);
        addDetailRow(
            details,
            'Layanan tambahan',
            hold.additional_service_name || 'Tidak ada'
        );
        addDetailRow(details, 'Tanggal', formatDate(hold.slot_date));
        addDetailRow(
            details,
            'Waktu',
            `${formatTime(hold.start_time)} - ${formatTime(hold.end_time)} WIB`
        );
        addDetailRow(
            details,
            'Total pembayaran',
            formatRupiah(hold.total_amount)
        );

        panel.append(details);

        const expiryContainer = document.createElement('div');
        expiryContainer.className =
            'mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800';

        const expiryLabel = document.createElement('span');
        expiryLabel.textContent = 'Waktu tersisa: ';

        const countdown = document.createElement('strong');
        countdown.id = 'booking-hold-countdown';
        countdown.textContent = '--:--';

        expiryContainer.append(expiryLabel, countdown);
        panel.append(expiryContainer);

        const actions = document.createElement('div');
        actions.className = 'mt-4 grid gap-3 sm:grid-cols-2';

        const continueExistingButton = document.createElement('button');
        continueExistingButton.type = 'button';
        continueExistingButton.className =
            'rounded-xl bg-[#0d3b36] px-4 py-3 text-sm font-semibold text-white hover:opacity-90';
        continueExistingButton.textContent = 'Lanjutkan Booking';

        continueExistingButton.addEventListener('click', () => {
            continueWithExistingHold();
        });

        const chooseAnotherButton = document.createElement('button');
        chooseAnotherButton.type = 'button';
        chooseAnotherButton.className =
            'rounded-xl border border-gray-300 bg-white px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50';
        chooseAnotherButton.textContent = 'Pilih Waktu Lain';

        chooseAnotherButton.addEventListener('click', () => {
            releaseExistingHold();
        });

        actions.append(
            continueExistingButton,
            chooseAnotherButton
        );

        panel.append(actions);
    }

    function startHoldCountdown() {
        if (state.holdTimer) {
            window.clearInterval(state.holdTimer);
        }

        function updateCountdown() {
            if (!state.activeHold) return;

            const expiry = new Date(state.activeHold.expires_at).getTime();
            const remaining = expiry - Date.now();

            const countdown =
                document.getElementById('booking-hold-countdown');

            if (remaining <= 0) {
                expireCurrentHold();
                return;
            }

            if (countdown) {
                const totalSeconds = Math.floor(remaining / 1000);
                const minutes = Math.floor(totalSeconds / 60);
                const seconds = totalSeconds % 60;

                countdown.textContent =
                    `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }
        }

        updateCountdown();

        state.holdTimer = window.setInterval(
            updateCountdown,
            1000
        );
    }

    function stopHoldCountdown() {
        if (state.holdTimer) {
            window.clearInterval(state.holdTimer);
            state.holdTimer = null;
        }
    }

    function expireCurrentHold() {
        stopHoldCountdown();

        state.activeHold = null;
        clearHoldFromSession();

        document.getElementById('active-booking-panel')?.remove();

        setBookingControlsDisabled(false);

        state.slotId = '';
        state.time = '';

        if (selectedSlotInput) {
            selectedSlotInput.value = '';
        }

        showMessage(
            'Waktu penahanan booking telah habis. Silakan pilih jadwal kembali.'
        );

        updateSummary();
        loadAvailability();
    }

    function continueWithExistingHold() {
        if (!state.activeHold) return;

        const expiry = new Date(
            state.activeHold.expires_at
        ).getTime();

        if (!Number.isFinite(expiry) || expiry <= Date.now()) {
            expireCurrentHold();
            return;
        }

        saveHoldToSession(state.activeHold);

        window.location.href = 'confirmation.php';
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve existing active hold from backend
    |--------------------------------------------------------------------------
    */

    async function retrieveActiveHold() {
        const payload = await postBookingAction({
            action: 'get_active_hold'
        });

        const hold = payload.data;

        if (!hold) {
            clearHoldFromSession();
            return false;
        }

        if (!hold.hold_token || !hold.expires_at) {
            throw new Error('Data booking aktif dari server tidak lengkap.');
        }

        const expiry = new Date(hold.expires_at).getTime();

        if (!Number.isFinite(expiry) || expiry <= Date.now()) {
            clearHoldFromSession();
            return false;
        }

        state.activeHold = hold;

        state.date = hold.slot_date;
        state.time = formatTime(hold.start_time);
        state.slotId = String(hold.slot_id);
        state.serviceId = String(hold.service_id);

        dateInput.value = state.date;

        if (selectedServiceInput) {
            selectedServiceInput.value = state.serviceId;
        }

        if (selectedSlotInput) {
            selectedSlotInput.value = state.slotId;
        }

        selectServiceButton(state.serviceId);

        saveHoldToSession(hold);

        renderActiveHoldPanel();
        setBookingControlsDisabled(true);
        startHoldCountdown();
        updateSummary();

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Service selection
    |--------------------------------------------------------------------------
    */

    serviceButtons.forEach(button => {
        button.addEventListener('click', () => {
            if (
                state.creatingHold ||
                state.activeHold ||
                state.releasingHold
            ) {
                return;
            }

            state.serviceId = String(button.dataset.serviceId);

            if (selectedServiceInput) {
                selectedServiceInput.value = state.serviceId;
            }

            selectServiceButton(state.serviceId);
            updateContinueButton();
        });
    });

    const initialServiceId = Number(
        selectedServiceInput?.value || 0
    );

    state.serviceId = initialServiceId > 0
        ? String(initialServiceId)
        : '';

    /*
    |--------------------------------------------------------------------------
    | Retrieve availability
    |--------------------------------------------------------------------------
    */

    async function loadAvailability() {
        if (!state.date || state.loading) return;

        state.loading = true;

        try {
            const url = new URL(
                API_ENDPOINT,
                window.location.href
            );

            url.searchParams.set('date', state.date);

            const payload = await apiRequest(url.toString());

            if (!Array.isArray(payload.data)) {
                throw new Error('Invalid availability data.');
            }

            state.availability = payload.data;

            renderSlots();

        } catch (error) {
            console.error('Availability error:', error);

            timeGroup.innerHTML = '';

            const message = document.createElement('p');
            message.className =
                'col-span-full rounded-xl bg-red-50 p-4 text-sm text-red-700';

            message.textContent =
                'Gagal memuat ketersediaan jadwal. Silakan coba lagi.';

            timeGroup.append(message);

        } finally {
            state.loading = false;
            updateContinueButton();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Render available time slots
    |--------------------------------------------------------------------------
    */

    function renderSlots() {
        timeGroup.replaceChildren();

        let selectedSlotStillAvailable = false;

        state.availability.forEach(slot => {
            const startTime = formatTime(slot.start_time);
            const endTime = formatTime(slot.end_time);

            const available = (
                slot.is_available === true &&
                Number(slot.available_count) > 0
            );

            const selected = (
                String(slot.slot_id) === state.slotId &&
                (available || Boolean(state.activeHold))
            );

            if (selected) {
                selectedSlotStillAvailable = true;
            }

            const button = document.createElement('button');

            button.type = 'button';
            button.className =
                'time-btn min-h-[76px] rounded-xl border px-2 py-3 text-center text-xs transition';

            button.dataset.slotId = String(slot.slot_id);
            button.dataset.time = startTime;
            button.dataset.available = available ? 'true' : 'false';

            button.disabled =
                !available ||
                Boolean(state.activeHold) ||
                state.creatingHold;

            if (selected) {
                button.classList.add(
                    'border-2',
                    'border-[#0d3b36]',
                    'bg-[#0d3b36]',
                    'text-white'
                );
            } else if (button.disabled) {
                button.classList.add(
                    'border-transparent',
                    'text-gray-300',
                    'cursor-not-allowed'
                );
            } else {
                button.classList.add(
                    'border-gray-200',
                    'bg-white',
                    'text-gray-900',
                    'hover:border-[#0d3b36]'
                );
            }

            const timeLabel = document.createElement('span');
            timeLabel.className = 'block text-base font-semibold';
            timeLabel.textContent = startTime;

            const endLabel = document.createElement('span');
            endLabel.className = 'mt-1 block text-[10px]';
            endLabel.textContent = `sampai ${endTime}`;

            const capacityLabel = document.createElement('span');
            capacityLabel.className = 'mt-1 block text-[10px]';

            capacityLabel.textContent = available
                ? `${slot.available_count} kapasitas tersisa`
                : (
                    slot.is_available
                        ? 'Penuh'
                        : 'Tidak tersedia'
                );

            button.append(timeLabel, endLabel, capacityLabel);

            button.setAttribute(
                'aria-pressed',
                selected ? 'true' : 'false'
            );

            timeGroup.append(button);
        });

        if (!selectedSlotStillAvailable && !state.activeHold) {
            state.slotId = '';
            state.time = '';

            if (selectedSlotInput) {
                selectedSlotInput.value = '';
            }
        }

        const information = document.createElement('p');

        information.className =
            'col-span-full mt-2 text-[11px] text-gray-500';

        information.textContent =
            'Kapasitas maksimal 3 pelanggan per jam. Dua slot 30 menit dalam satu jam berbagi kapasitas yang sama.';

        timeGroup.append(information);

        updateSummary();
    }

    /*
    |--------------------------------------------------------------------------
    | Time selection
    |--------------------------------------------------------------------------
    */

    timeGroup.addEventListener('click', event => {
        const button = event.target.closest('button.time-btn');

        if (
            !button ||
            button.disabled ||
            state.creatingHold ||
            state.activeHold
        ) {
            return;
        }

        state.slotId = button.dataset.slotId;
        state.time = button.dataset.time;

        if (selectedSlotInput) {
            selectedSlotInput.value = state.slotId;
        }

        renderSlots();
    });

    /*
    |--------------------------------------------------------------------------
    | Date selection
    |--------------------------------------------------------------------------
    */

    dateInput.min = getLocalDate();

    dateInput.addEventListener('change', () => {
        if (
            state.creatingHold ||
            state.activeHold ||
            state.releasingHold
        ) {
            return;
        }

        state.date = dateInput.value;
        state.slotId = '';
        state.time = '';

        if (selectedSlotInput) {
            selectedSlotInput.value = '';
        }

        clearMessage();
        updateSummary();
        loadAvailability();
    });

    /*
    |--------------------------------------------------------------------------
    | Release existing hold
    |--------------------------------------------------------------------------
    */

    async function releaseExistingHold() {
        if (!state.activeHold || state.releasingHold) return;

        const confirmed = window.confirm(
            'Booking sebelumnya akan dilepas. Apakah Anda ingin memilih waktu lain?'
        );

        if (!confirmed) return;

        state.releasingHold = true;

        const chooseAnotherButton =
            document.querySelector(
                '#active-booking-panel button:last-child'
            );

        if (chooseAnotherButton) {
            chooseAnotherButton.disabled = true;
            chooseAnotherButton.textContent = 'Melepas booking...';
        }

        try {
            await postBookingAction({
                action: 'release_hold',
                hold_token: state.activeHold.hold_token
            });

            stopHoldCountdown();

            state.activeHold = null;
            clearHoldFromSession();

            document.getElementById('active-booking-panel')?.remove();

            state.slotId = '';
            state.time = '';

            if (selectedSlotInput) {
                selectedSlotInput.value = '';
            }

            setBookingControlsDisabled(false);

            clearMessage();
            showMessage(
                'Booking sebelumnya berhasil dilepas. Silakan pilih waktu baru.',
                'success'
            );

            updateSummary();

            await loadAvailability();

        } catch (error) {
            console.error('Release hold error:', error);
            showMessage(error.message);
            renderActiveHoldPanel();

        } finally {
            state.releasingHold = false;
            updateContinueButton();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create five-minute hold
    |--------------------------------------------------------------------------
    */

    async function createBookingHold() {
        if (
            state.creatingHold ||
            state.activeHold ||
            state.releasingHold
        ) {
            return;
        }

        if (!state.serviceId || !state.slotId || !state.date) {
            showMessage(
                'Pilih layanan, tanggal, dan waktu terlebih dahulu.'
            );
            return;
        }

        state.creatingHold = true;

        clearMessage();
        updateContinueButton();

        try {
            const payload = await postBookingAction({
                action: 'create_hold',
                slot_id: Number(state.slotId),
                service_id: Number(state.serviceId),
                additional_service_id: null
            });

            const hold = payload.data;

            if (!hold || !hold.hold_token) {
                throw new Error(
                    'The server did not return a valid booking hold.'
                );
            }

            saveHoldToSession(hold);

            showMessage(
                'Jadwal berhasil ditahan selama 5 menit. Melanjutkan...',
                'success'
            );

            window.location.href = 'confirmation.php';

        } catch (error) {
            console.error('Create hold error:', error);

            showMessage(error.message);

            state.creatingHold = false;

            await loadAvailability();
            updateContinueButton();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Form submission
    |--------------------------------------------------------------------------
    */

    bookingForm.addEventListener('submit', event => {
        event.preventDefault();

        if (state.activeHold) {
            continueWithExistingHold();
            return;
        }

        createBookingHold();
    });

    /*
    |--------------------------------------------------------------------------
    | Initial page setup
    |--------------------------------------------------------------------------
    */

    async function initializeBookingPage() {
        dateInput.min = getLocalDate();

        try {
            const restored = await retrieveActiveHold();

            if (restored) {
                await loadAvailability();
                return;
            }

            dateInput.value = getLocalDate();
            state.date = dateInput.value;

            updateSummary();
            await loadAvailability();

        } catch (error) {
            console.error('Active hold retrieval error:', error);

            showMessage(
                'Gagal memeriksa booking sebelumnya. Silakan muat ulang halaman.'
            );

            dateInput.value = getLocalDate();
            state.date = dateInput.value;

            updateSummary();
            await loadAvailability();
        }
    }

    initializeBookingPage();

    state.refreshTimer = window.setInterval(() => {
        if (!state.creatingHold && !state.activeHold) {
            loadAvailability();
        }
    }, REFRESH_INTERVAL);

    /*
    |--------------------------------------------------------------------------
    | Browser navigation
    |--------------------------------------------------------------------------
    */

    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            window.location.reload();
        }
    });

    window.addEventListener('pagehide', () => {
        if (state.refreshTimer) {
            window.clearInterval(state.refreshTimer);
        }

        stopHoldCountdown();
    });

})();
