(() => {
  const form = document.getElementById('reservation-search-form');
  const input = document.getElementById('reservation-search');
  const reset = document.getElementById('reservation-search-reset');
  const loading = document.getElementById('reservation-loading');
  const sections = document.getElementById('reservation-sections');
  const error = document.getElementById('reservation-page-error');
  const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  const dateLabel = value => {
    const [year, month, day] = String(value).slice(0, 10).split('-').map(Number);
    return `${day} ${monthNames[month - 1]} ${year}`;
  };

  const money = value => `Rp ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(value) || 0)}`;

  function renderReservation(reservation) {
    const article = element('article', 'flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between');
    const details = element('div');
    const heading = element('div', 'flex flex-wrap items-center gap-2');
    heading.append(element('h3', 'font-bold', reservation.service_name || 'Layanan'));

    const slotDate = String(reservation.slot_date).slice(0, 10);
    const appointmentAt = new Date(`${slotDate}T${reservation.start_time}+07:00`);
    const isUpcoming = reservation.reservation_status === 'active' && appointmentAt >= new Date();
    const cancelled = reservation.reservation_status !== 'active';
    heading.append(element('span', `rounded-full px-2.5 py-1 text-[11px] font-semibold ${cancelled ? 'bg-rose-50 text-rose-700' : (isUpcoming ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-700')}`, cancelled ? 'Dibatalkan' : (isUpcoming ? 'Mendatang' : 'Selesai')));

    const paymentStatus = String(reservation.payment_status || 'pending').toLowerCase();
    const paymentLabels = {
      paid: ['Lunas', 'bg-emerald-50 text-emerald-800'],
      pending: ['Menunggu pembayaran', 'bg-amber-50 text-amber-800'],
      'verification pending': ['Menunggu verifikasi', 'bg-amber-50 text-amber-800'],
      failed: ['Gagal', 'bg-rose-50 text-rose-700'],
      refunded: ['Refund selesai', 'bg-gray-100 text-gray-700'],
      cancelled: ['Dibatalkan', 'bg-gray-100 text-gray-700'],
    };
    const [paymentLabel, paymentClass] = paymentLabels[paymentStatus] || ['Belum dibayar', 'bg-gray-100 text-gray-700'];
    heading.append(element('span', `rounded-full px-2.5 py-1 text-[11px] font-semibold ${paymentClass}`, paymentLabel));
    details.append(heading);
    details.append(element('p', 'mt-2 text-sm text-gray-600', `${dateLabel(slotDate)} · ${String(reservation.start_time).slice(0, 5)}–${String(reservation.end_time).slice(0, 5)} WIB`));
    if (reservation.additional_service_name) {
      details.append(element('p', 'mt-1 text-xs text-gray-500', `Layanan tambahan: ${reservation.additional_service_name}`));
    }
    if (reservation.customer_message) {
      details.append(element('p', 'mt-2 max-w-xl whitespace-pre-wrap rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600', 'Pesan Anda: ' + reservation.customer_message));
    }
    if (reservation.old_slot_date && reservation.old_start_time) {
      let scheduleMessage = `Jadwal diubah oleh barbershop dari ${dateLabel(reservation.old_slot_date)} pukul ${String(reservation.old_start_time).slice(0, 5)}. Gunakan jadwal terbaru di atas.`;
      if (reservation.schedule_change_reason) scheduleMessage += ` Alasan: ${reservation.schedule_change_reason}.`;
      details.append(element('p', 'mt-2 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-xs text-amber-900', scheduleMessage));
    }

    if (reservation.admin_cancelled_at && reservation.refund_status !== 'completed' && paymentStatus !== 'refunded') {
      let message = 'Reservasi ini dibatalkan oleh admin.';
      if (reservation.refund_status === 'pending') message = 'Reservasi dibatalkan oleh admin. Pembayaran akan dikembalikan ke bank atau e-wallet yang Anda gunakan.';
      details.append(element('p', 'mt-2 rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-xs text-rose-800', message));
    }
    details.append(element('p', 'mt-1 text-xs text-gray-500', `Kode booking: PMN-${reservation.reservation_id}`));

    const actions = element('div', 'flex items-center justify-between gap-4 sm:justify-end');
    actions.append(element('p', 'text-sm font-bold', money(reservation.total_payment ?? (Number(reservation.service_price) + Number(reservation.additional_service_price || 0)))));
    const detail = element('a', 'rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50', 'Detail');
    detail.href = `../booking/success.php?reservation_id=${encodeURIComponent(reservation.reservation_id)}`;
    actions.append(detail);
    article.append(details, actions);
    return { article, isUpcoming };
  }

  function renderList(container, reservations, emptyText) {
    container.replaceChildren();
    if (!reservations.length) {
      container.append(element('p', 'px-5 py-8 text-center text-sm text-gray-500', emptyText));
      return;
    }
    reservations.forEach(reservation => container.append(renderReservation(reservation).article));
  }

  async function loadReservations(query = '') {
    loading.classList.remove('hidden');
    sections.classList.add('hidden');
    error.classList.add('hidden');
    const params = new URLSearchParams();
    if (query) params.set('q', query);
    try {
      const response = await fetch(`../../backend/api/reservation.php${params.size ? `?${params}` : ''}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!response.ok || !result.success || !Array.isArray(result.data)) throw new Error(result.message || 'Riwayat reservasi gagal dimuat. Silakan coba lagi.');
      const upcoming = [];
      const history = [];
      result.data.forEach(reservation => (renderReservation(reservation).isUpcoming ? upcoming : history).push(reservation));
      document.getElementById('upcoming-count').textContent = `${upcoming.length} reservasi`;
      document.getElementById('history-count').textContent = `${history.length} reservasi`;
      renderList(document.getElementById('upcoming-list'), upcoming, query ? 'Tidak ada reservasi mendatang yang cocok.' : 'Belum ada reservasi mendatang.');
      renderList(document.getElementById('history-list'), history, query ? 'Tidak ada riwayat yang cocok.' : 'Belum ada reservasi yang selesai atau dibatalkan.');
      sections.classList.remove('hidden');
    } catch (loadError) {
      error.textContent = loadError.message || 'Riwayat reservasi gagal dimuat. Silakan coba lagi.';
      error.classList.remove('hidden');
    } finally {
      loading.classList.add('hidden');
    }
  }

  const initialQuery = new URLSearchParams(window.location.search).get('q') || '';
  input.value = initialQuery;
  reset.classList.toggle('hidden', !initialQuery);
  form.addEventListener('submit', event => {
    event.preventDefault();
    const query = input.value.trim();
    const url = new URL(window.location.href);
    query ? url.searchParams.set('q', query) : url.searchParams.delete('q');
    window.history.replaceState({}, '', url);
    reset.classList.toggle('hidden', !query);
    loadReservations(query);
  });
  reset.addEventListener('click', () => {
    input.value = '';
    const url = new URL(window.location.href);
    url.searchParams.delete('q');
    window.history.replaceState({}, '', url);
    reset.classList.add('hidden');
    loadReservations();
  });
  loadReservations(initialQuery);
})();
