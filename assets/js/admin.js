/* =========================================================
   assets/js/admin.js
   Helper bersama untuk semua halaman di /admin
   - sidebar + topbar (nanti dipindah ke includes/admin_sidebar.php)
   - modal, konfirmasi, toast
   - format rupiah, escape HTML, export CSV
   ========================================================= */

const ADMIN_NAV = [
  { key: 'dashboard',   label: 'Dashboard',   icon: 'dashboard',       href: 'dashboard.php' },
  { key: 'reservations', label: 'Reservations', icon: 'calendar_month', href: 'dashboard.php' },
  { key: 'services',    label: 'Services',    icon: 'content_cut',     href: 'services.php' },
  { key: 'slots',       label: 'Time Slots',  icon: 'schedule',        href: 'time-slots.php' },
  { key: 'add-service', label: 'Add Service', icon: 'add_circle',      href: 'add-service.php' },
  { key: 'refunds',     label: 'Refunds',     icon: 'currency_exchange', href: 'refunds.php' },
];

/* ---------- helper kecil ---------- */
function rupiah(n) {
  return 'Rp ' + Number(n).toLocaleString('id-ID');
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function digitsOnly(s) {
  return parseInt(String(s).replace(/\D/g, ''), 10) || 0;
}

function downloadCSV(filename, rows) {
  const csv = rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
  a.download = filename;
  a.click();
  URL.revokeObjectURL(a.href);
}

/* ---------- modal ---------- */
function openModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.classList.remove('hidden');
  m.classList.add('flex');
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (!m) return;
  m.classList.add('hidden');
  m.classList.remove('flex');
}

document.addEventListener('click', e => {
  // tombol dengan data-close-modal menutup modal terdekat
  const closer = e.target.closest('[data-close-modal]');
  if (closer) {
    const m = closer.closest('.modal');
    if (m) closeModal(m.id);
    return;
  }
  // klik area gelap (backdrop) juga menutup
  if (e.target.classList && e.target.classList.contains('modal')) closeModal(e.target.id);
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal.flex').forEach(m => closeModal(m.id));
});

/* ---------- konfirmasi (pengganti confirm()) ---------- */
function confirmDialog({ title, message, okLabel = 'Ya, Lanjutkan', danger = false }) {
  return new Promise(resolve => {
    let box = document.getElementById('confirm-modal');
    if (!box) {
      box = document.createElement('div');
      box.id = 'confirm-modal';
      box.className = 'modal hidden fixed inset-0 z-[60] items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4';
      document.body.appendChild(box);
    }
    box.innerHTML = `
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
        <h3 class="text-lg font-bold text-slate-900">${esc(title)}</h3>
        <p class="text-sm text-slate-500 mt-2">${esc(message)}</p>
        <div class="flex justify-end gap-2 mt-6">
          <button id="confirm-cancel" class="px-4 py-2 rounded-xl text-sm font-semibold bg-slate-100 text-slate-600 hover:bg-slate-200">Batal</button>
          <button id="confirm-ok" class="px-4 py-2 rounded-xl text-sm font-semibold text-white ${danger ? 'bg-red-600 hover:bg-red-700' : 'bg-[#0d3b36] hover:bg-[#082724]'}">${esc(okLabel)}</button>
        </div>
      </div>`;
    openModal('confirm-modal');
    const done = v => { closeModal('confirm-modal'); resolve(v); };
    box.querySelector('#confirm-ok').onclick = () => done(true);
    box.querySelector('#confirm-cancel').onclick = () => done(false);
  });
}

/* ---------- toast ---------- */
function toast(message) {
  let t = document.getElementById('toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'toast';
    t.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-[70] bg-[#0d3b36] text-white text-sm font-medium px-5 py-3 rounded-xl shadow-lg transition-opacity duration-300 opacity-0 pointer-events-none';
    document.body.appendChild(t);
  }
  t.textContent = message;
  t.classList.remove('opacity-0');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.add('opacity-0'), 2200);
}

/* ---------- chip status ---------- */
const CHIP_STYLE = {
  green:  'bg-emerald-100 text-emerald-800',
  blue:   'bg-blue-100 text-blue-800',
  red:    'bg-red-100 text-red-700',
  amber:  'bg-amber-100 text-amber-700',
  indigo: 'bg-indigo-100 text-indigo-700',
  slate:  'bg-slate-100 text-slate-600',  
};

function chip(label, color, icon) {
  return `<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-semibold ${CHIP_STYLE[color]}">
    ${icon ? `<span class="material-symbols-outlined text-[14px]">${icon}</span>` : ''}${esc(label)}</span>`;
}

/* ---------- shell: sidebar + topbar ---------- */
function initAdminShell(active, crumbs) {
  const nav = ADMIN_NAV.map(i => {
    const on = i.key === active;
    return `<a href="${i.href}" class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm transition ${on ? 'bg-[#0d3b36] text-white font-semibold' : 'text-slate-600 hover:bg-slate-100'}">
      <span class="material-symbols-outlined text-[20px]">${i.icon}</span>${i.label}</a>`;
  }).join('');

  document.getElementById('admin-sidebar').innerHTML = `
    <aside id="sidebar-panel" class="hidden lg:flex fixed inset-y-0 left-0 z-40 w-72 flex-col bg-white border-r border-slate-100 p-4 shadow-xl lg:shadow-none">
      <div class="flex items-center gap-3 px-2 py-3">
        <div class="w-10 h-10 bg-[#0d3b36] text-white flex items-center justify-center rounded-lg font-bold text-xl">P</div>
        <span class="font-bold tracking-wide text-[#0d3b36] leading-tight">PEMON<br><span class="text-[10px] font-normal text-slate-400 tracking-widest">BARBERSHOP</span></span>
      </div>
      <div class="flex items-center gap-3 bg-indigo-50/60 rounded-xl p-3 mt-4">
        <div class="w-10 h-10 rounded-full bg-[#0d3b36] text-white flex items-center justify-center">
          <span class="material-symbols-outlined text-[20px]">person</span>
        </div>
        <div>
          <p id="admin-name" class="text-sm font-semibold text-slate-900">Master Barber</p>
          <p class="text-xs text-slate-500">Operations Lead</p>
        </div>
      </div>
      <nav class="mt-6 flex flex-col gap-1">${nav}</nav>
      <a href="logout.php" class="mt-auto flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-red-600 hover:bg-red-50">
        <span class="material-symbols-outlined text-[20px]">logout</span>Log Out
      </a>
    </aside>`;

  const trail = (crumbs || []).map((c, i, a) =>
    `<span class="${i === a.length - 1 ? 'font-semibold text-slate-900' : 'text-slate-500'}">${esc(c)}</span>`
  ).join('<span class="material-symbols-outlined text-[16px] text-slate-400">chevron_right</span>');

  document.getElementById('admin-topbar').innerHTML = `
    <header class="sticky top-0 z-30 lg:ml-72 h-16 bg-white/85 backdrop-blur border-b border-slate-100 flex items-center justify-between px-4 sm:px-8">
      <div class="flex items-center gap-3 text-sm">
        <button id="sidebar-toggle" class="lg:hidden p-2 -ml-2 rounded-lg hover:bg-slate-100" aria-label="Menu">
          <span class="material-symbols-outlined">menu</span>
        </button>
        <span class="material-symbols-outlined text-[18px] text-slate-500">storefront</span>
        <div class="flex items-center gap-1.5">${trail}</div>
      </div>
      
      <!-- Modifikasi Tombol Akun & Dropdown Dimulai dari Sini -->
      <div class="relative">
        <button id="admin-account-btn" class="flex items-center gap-2 hover:bg-slate-50 p-1.5 rounded-xl transition-colors focus:outline-none">
          <div class="w-9 h-9 rounded-full bg-[#0d3b36] text-white flex items-center justify-center font-bold">
            A
          </div>
          <span class="text-sm font-semibold text-slate-700 hidden sm:block">Akun</span>
        </button>

        <div id="admin-account-menu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-100 py-2 z-50 text-sm">
          <div class="px-4 py-3 border-b border-slate-100">
            <p class="font-bold text-slate-900">Halo, Admin</p>
            <p class="text-xs text-slate-500 mt-1">Administrator</p>
            <p class="text-xs text-slate-500">admin@barbershop.com</p>
          </div>
          <div class="py-1">
          </div>
          <div class="border-t border-slate-100 py-1">
            <a href="logout.php" class="block px-4 py-2.5 text-red-600 hover:bg-red-50 font-medium">Keluar</a>
          </div>
        </div>
      </div>
      <!-- Akhir Modifikasi Tombol Akun -->

    </header>`;

  // Logika toggle sidebar
  document.getElementById('sidebar-toggle').addEventListener('click', () => {
    const p = document.getElementById('sidebar-panel');
    p.classList.toggle('hidden');
    p.classList.toggle('flex');
  });

  // Logika interaksi dropdown akun
  const accBtn = document.getElementById('admin-account-btn');
  const accMenu = document.getElementById('admin-account-menu');

  if (accBtn && accMenu) {
    accBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      accMenu.classList.toggle('hidden');
    });

    // Menutup dropdown saat area di luar diklik
    document.addEventListener('click', (e) => {
      if (!accBtn.contains(e.target) && !accMenu.contains(e.target)) {
        accMenu.classList.add('hidden');
      }
    });
  }
}
