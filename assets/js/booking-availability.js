(() => {
  const dateInput = document.getElementById('booking-date');
  const timeGroup = document.getElementById('time-group');
  if (!dateInput || !timeGroup) return;

  // Align this endpoint contract with the capacity API:
  // GET time_slot.php?date=YYYY-MM-DD
  // { success: true, data: [{ start_time: "10:00", end_time: "10:30",
  //   booked_count: 1, capacity: 3 }] }
  const AVAILABILITY_ENDPOINT = '../../backend/api/time_slot.php';
  const OPEN_MINUTES = 10 * 60;
  const CLOSE_MINUTES = 22 * 60;
  const STEP_MINUTES = 30;
  const REFRESH_MS = 30_000;

  const state = { date: '', time: '', timer: null, request: null };
  const continueLink = document.getElementById('continue-booking');

  function localDateString(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function formatDate(dateString) {
    return new Intl.DateTimeFormat('id-ID', {
      weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
    }).format(new Date(`${dateString}T00:00:00`));
  }

  function timeText(totalMinutes) {
    const hours = String(Math.floor(totalMinutes / 60)).padStart(2, '0');
    const minutes = String(totalMinutes % 60).padStart(2, '0');
    return `${hours}:${minutes}`;
  }

  function buildSchedule() {
    const times = [];
    for (let minute = OPEN_MINUTES; minute < CLOSE_MINUTES; minute += STEP_MINUTES) {
      times.push({ start_time: timeText(minute), end_time: timeText(minute + STEP_MINUTES) });
    }
    return times;
  }

  function updateSummary() {
    const summary = document.getElementById('summary-text');
    if (summary) summary.textContent = state.date && state.time
      ? `${formatDate(state.date)} pukul ${state.time} WIB.`
      : 'Pilih tanggal dan waktu reservasi.';
    updateContinueState();
  }

  function updateContinueState() {
    if (!continueLink) return;
    const ready = Boolean(state.date && state.time);
    continueLink.setAttribute('aria-disabled', String(!ready));
    continueLink.classList.toggle('pointer-events-none', !ready);
    continueLink.classList.toggle('bg-gray-400', !ready);
    continueLink.classList.toggle('bg-[#0d3b36]', ready);
  }

  function readAvailability(rows) {
    const map = new Map();
    rows.forEach((row) => {
      if (!row || typeof row.start_time !== 'string') return;
      const start = row.start_time.slice(0, 5);
      const capacity = Number(row.capacity);
      const booked = Number(row.booked_count);
      map.set(start, {
        full: row.is_available === false || row.is_available === 'full' ||
          (Number.isFinite(capacity) && Number.isFinite(booked) && capacity > 0 && booked >= capacity),
        remaining: Number.isFinite(capacity) && Number.isFinite(booked)
          ? Math.max(0, capacity - booked)
          : null,
        slotId: row.slot_id ?? null
      });
    });
    return map;
  }

  function renderSlots(rows, { stale = false } = {}) {
    const availability = readAvailability(rows);
    const now = new Date();
    const today = localDateString(now);
    let selectionStillValid = false;

    timeGroup.replaceChildren();
    buildSchedule().forEach((slot) => {
      const status = availability.get(slot.start_time);
      const elapsed = state.date === today && (() => {
        const nowMinutes = now.getHours() * 60 + now.getMinutes();
        return nowMinutes >= Number(slot.start_time.slice(0, 2)) * 60 + Number(slot.start_time.slice(3)) - 1;
      })();
      const full = status?.full ?? false;
      const unavailable = full || elapsed || stale || !status;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = unavailable
        ? 'time-btn min-h-[76px] border border-transparent bg-transparent px-2 py-3 rounded-xl text-center text-xs text-gray-300 cursor-not-allowed'
        : 'time-btn min-h-[76px] border border-transparent bg-transparent px-2 py-3 rounded-xl text-center text-xs text-gray-900 hover:border-gray-200 hover:bg-white transition';
      button.dataset.time = slot.start_time;
      button.dataset.endTime = slot.end_time;
      if (status?.slotId !== null && status?.slotId !== undefined) button.dataset.slotId = status.slotId;
      button.disabled = unavailable;

      const label = document.createElement('span');
      label.className = unavailable && full
        ? 'block text-base sm:text-lg font-semibold line-through text-gray-300'
        : 'block text-base sm:text-lg font-semibold';
      label.textContent = slot.start_time;
      button.append(label);
      const note = document.createElement('span');
      note.className = unavailable
        ? 'block text-[10px] sm:text-xs mt-1 text-gray-300'
        : 'block text-[10px] sm:text-xs mt-1 text-gray-700';
      if (elapsed) note.textContent = 'Lewat';
      else if (stale) note.textContent = 'Cek koneksi';
      else if (!status) note.textContent = 'Tidak tersedia';
      else if (full) note.textContent = 'Penuh';
      else if (status?.remaining !== null && status?.remaining !== undefined) {
        note.textContent = `${status.remaining} ${status.remaining === 1 ? 'slot' : 'slot'} tersedia`;
      } else note.textContent = 'slot tersedia';
      button.append(note);

      if (!unavailable && slot.start_time === state.time) {
        button.className = 'time-btn min-h-[76px] border border-gray-200 bg-white px-2 py-3 rounded-xl text-center text-xs text-gray-900 shadow-sm transition';
        label.className = 'block text-base sm:text-lg font-semibold text-[#d7eee5]';
        note.className = 'block text-[10px] sm:text-xs mt-1 text-[#d7eee5]';
        selectionStillValid = true;
      }
      timeGroup.append(button);
    });

    if (!selectionStillValid) state.time = '';
    updateSummary();

    const hint = document.createElement('p');
    hint.className = 'col-span-full text-[11px] text-gray-500 mt-1';
    hint.textContent = stale
      ? 'Ketersediaan belum dapat dimuat. Periksa koneksi atau coba lagi.'
      : 'Status diperbarui otomatis setiap 30 detik. Ketersediaan dikonfirmasi kembali saat booking.';
    timeGroup.append(hint);
  }

  async function loadAvailability() {
    if (!state.date) return;
    if (state.request) state.request.abort();
    state.request = new AbortController();
    const message = document.getElementById('slot-message');
    if (message) message.remove();

    try {
      const url = new URL(AVAILABILITY_ENDPOINT, window.location.href);
      url.searchParams.set('date', state.date);
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        signal: state.request.signal
      });
      if (!response.ok) throw new Error(`Availability request failed: ${response.status}`);
      const payload = await response.json();
      if (!payload.success || !Array.isArray(payload.data)) throw new Error('Unexpected availability response');
      renderSlots(payload.data);
    } catch (error) {
      if (error.name === 'AbortError') return;
      // Keep the schedule usable during API development, but do not claim slots are free.
      renderSlots([], { stale: true });
    }
  }

  dateInput.min = localDateString();
  dateInput.value = dateInput.min;
  dateInput.addEventListener('change', () => {
    state.date = dateInput.value;
    state.time = '';
    updateSummary();
    loadAvailability();
  });

  timeGroup.addEventListener('click', (event) => {
    const button = event.target.closest('button.time-btn');
    if (!button || button.disabled) return;
    state.time = button.dataset.time;
    // Persist stable values for the next booking step.
    sessionStorage.setItem('booking_date', state.date);
    sessionStorage.setItem('booking_time', state.time);
    if (button.dataset.slotId) sessionStorage.setItem('booking_slot_id', button.dataset.slotId);
    else sessionStorage.removeItem('booking_slot_id');
    renderSlotsFromCurrentButtons();
  });

  function renderSlotsFromCurrentButtons() {
    timeGroup.querySelectorAll('button.time-btn').forEach((button) => {
      if (button.dataset.time === state.time) {
        button.className = 'time-btn min-h-[76px] border border-gray-200 bg-white px-2 py-3 rounded-xl text-center text-xs text-gray-900 shadow-sm transition';
        button.querySelector('span:first-child').className = 'block text-base sm:text-lg font-semibold text-[#d7eee5]';
        button.querySelector('span:last-child').className = 'block text-[10px] sm:text-xs mt-1 text-[#d7eee5]';
      } else if (!button.disabled) {
        button.className = 'time-btn min-h-[76px] border border-transparent bg-transparent px-2 py-3 rounded-xl text-center text-xs text-gray-900 hover:border-gray-200 hover:bg-white transition';
        button.querySelector('span:first-child').className = 'block text-base sm:text-lg font-semibold';
        button.querySelector('span:last-child').className = 'block text-[10px] sm:text-xs mt-1 text-gray-700';
      }
    });
    updateSummary();
  }

  if (continueLink) {
    continueLink.addEventListener('click', (event) => {
      if (!state.date || !state.time) {
        event.preventDefault();
        window.alert('Pilih tanggal dan waktu yang tersedia terlebih dahulu.');
      }
    });
  }

  state.date = dateInput.value;
  loadAvailability();
  state.timer = window.setInterval(loadAvailability, REFRESH_MS);
  window.addEventListener('pagehide', () => {
    if (state.timer) window.clearInterval(state.timer);
    if (state.request) state.request.abort();
  });
})();
