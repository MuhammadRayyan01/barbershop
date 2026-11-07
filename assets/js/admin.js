const ADMIN_NAV = [
  { key: 'dashboard',   label: 'Dashboard',   icon: 'dashboard',       href: 'dashboard.php' },
  { key: 'reservations', label: 'Reservations', icon: 'calendar_month', href: 'dashboard.php' },
  { key: 'services',    label: 'Services',    icon: 'content_cut',     href: 'services.php' },
  { key: 'slots',       label: 'Time Slots',  icon: 'schedule',        href: 'time-slots.php' },
  { key: 'add-service', label: 'Add Service', icon: 'add_circle',      href: 'add-service.php' },
  { key: 'refunds',     label: 'Refunds',     icon: 'currency_exchange', href: 'refunds.php' },
];

function rupiah(n) {
  return 'Rp ' + Number(n).toLocaleString('id-ID');
}

function esc(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function digitsOnly(s) {
  return parseInt(String(s).replace(/\D/g, ''), 10) || 0;
}

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
  const closer = e.target.closest('[data-close-modal]');
  if (closer) {
    const m = closer.closest('.modal');
    if (m) closeModal(m.id);
    return;
  }
  if (e.target.classList && e.target.classList.contains('modal')) closeModal(e.target.id);
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal.flex').forEach(m => closeModal(m.id));
});

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

function initAdminShell(active, crumbs) {
  const adminName = document.getElementById('admin-sidebar').dataset.adminName || 'Admin';
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
          <p id="admin-name" class="text-sm font-semibold text-slate-900">${esc(adminName)}</p>
          <p class="text-xs text-slate-500">Administrator</p>
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
      
      <div class="relative">
        <button id="admin-account-btn" class="flex items-center gap-2 hover:bg-slate-50 p-1.5 rounded-xl transition-colors focus:outline-none">
          <div class="w-9 h-9 rounded-full bg-[#0d3b36] text-white flex items-center justify-center font-bold">
            ${esc(adminName.charAt(0).toUpperCase())}
          </div>
          <span class="text-sm font-semibold text-slate-700 hidden sm:block">Akun</span>
        </button>

        <div id="admin-account-menu" class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl shadow-lg border border-slate-100 py-2 z-50 text-sm">
          <div class="px-4 py-3 border-b border-slate-100">
            <p class="font-bold text-slate-900">Halo, ${esc(adminName)}</p>
            <p class="text-xs text-slate-500 mt-1">Administrator</p>
          </div>
          <div class="py-1">
          </div>
          <div class="border-t border-slate-100 py-1">
            <a href="logout.php" class="block px-4 py-2.5 text-red-600 hover:bg-red-50 font-medium">Keluar</a>
          </div>
        </div>
      </div>

    </header>`;

  document.getElementById('sidebar-toggle').addEventListener('click', () => {
    const p = document.getElementById('sidebar-panel');
    p.classList.toggle('hidden');
    p.classList.toggle('flex');
  });

  const accBtn = document.getElementById('admin-account-btn');
  const accMenu = document.getElementById('admin-account-menu');

  if (accBtn && accMenu) {
    accBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      accMenu.classList.toggle('hidden');
    });

    document.addEventListener('click', (e) => {
      if (!accBtn.contains(e.target) && !accMenu.contains(e.target)) {
        accMenu.classList.add('hidden');
      }
    });
  }
}
