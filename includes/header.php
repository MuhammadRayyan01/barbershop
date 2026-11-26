<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/session.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$headerLoggedIn = isset($_SESSION['user_id']) && is_int($_SESSION['user_id']) && $_SESSION['user_id'] > 0;

// Every page gets a CSRF token automatically (profile API + logout form).
if ($headerLoggedIn) {
    $csrfToken = $csrfToken ?? getCsrfToken();
}

$headerEscape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$headerTitle = $userPageTitle ?? 'Pemon Barbershop';
$headerActivePage = $userActivePage ?? '';
$assetPrefix = $userAssetPrefix ?? '../../';
$headerBodyClass = $userBodyClass ?? 'min-h-screen bg-[#f4f7f5] p-4 text-[#0d3b36] sm:p-6 flex flex-col';

$dashboardUrl = $assetPrefix . 'user/dashboard/index.php';
$scheduleUrl = $assetPrefix . 'user/dashboard/schedule.php';
$reservationsUrl = $assetPrefix . 'user/dashboard/reservations.php';
$aboutUrl = $assetPrefix . 'user/dashboard/aboutUs.html';
$loginUrl = $assetPrefix . 'user/login.php';
$logoutUrl = $assetPrefix . 'backend/auth/logout.php';
$profileApiUrl = $assetPrefix . 'backend/api/profile.php';

$headerNavLink = static function (string $key, string $label, string $url) use ($headerActivePage, $headerEscape): string {
    $isActive = $headerActivePage === $key;

    return '<a href="' . $headerEscape($url) . '"' . ($isActive ? ' aria-current="page"' : '')
        . ' class="' . ($isActive ? 'font-semibold text-[#0d3b36]' : 'text-gray-600 hover:text-[#0d3b36]') . '">'
        . $headerEscape($label) . '</a>';
};
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if (isset($csrfToken)): ?>
    <meta name="csrf-token" content="<?= $headerEscape($csrfToken) ?>">
  <?php endif; ?>
  <title><?= $headerEscape($headerTitle) ?></title>
  <link rel="stylesheet" href="<?= $headerEscape($assetPrefix . 'assets/css/tailwind.css') ?>">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
  <!-- Shared account JavaScript: dropdown, profile popup, logout popup -->
  <script src="<?= $headerEscape($assetPrefix . 'assets/js/app.js') ?>" defer></script>
</head>
<body class="<?= $headerEscape($headerBodyClass) ?>">
  <header class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-4 py-4">
    <a href="<?= $headerEscape($dashboardUrl) ?>" class="flex shrink-0 items-center gap-3" aria-label="Pemon Barbershop">
      <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-[#0d3b36] text-xl font-bold text-white">P</span>
      <span class="font-bold tracking-wide">PEMON <span class="block text-xs font-normal text-gray-500">BARBERSHOP</span></span>
    </a>

    <nav class="order-last flex w-full items-center justify-center gap-4 border-t border-gray-200/70 pt-3 text-xs font-medium sm:order-none sm:w-auto sm:justify-start sm:border-0 sm:pt-0 sm:text-sm sm:gap-5" aria-label="Navigasi utama">
      <?= $headerNavLink('dashboard', 'Dashboard', $dashboardUrl) ?>
      <?= $headerNavLink('schedule', 'Jadwal', $scheduleUrl) ?>
      <?= $headerNavLink('about', 'Tentang', $aboutUrl) ?>
      <?= $headerNavLink('reservations', 'Reservasi', $reservationsUrl) ?>
    </nav>

    <?php if ($headerLoggedIn): ?>
      <div class="relative shrink-0">
        <button id="akun-btn" type="button"
                data-profile-url="<?= $headerEscape($profileApiUrl) ?>"
                data-login-url="<?= $headerEscape($loginUrl) ?>"
                class="flex items-center gap-2 rounded-xl px-2 py-1 hover:bg-white"
                aria-haspopup="true" aria-expanded="false" aria-controls="akun-menu">
          <span id="akun-initial" class="flex h-10 w-10 items-center justify-center rounded-full bg-[#0d3b36] font-bold text-white">U</span>
          <span id="akun-nama-depan" class="hidden text-sm font-medium sm:block">Akun</span>
        </button>

        <div id="akun-menu" class="absolute right-0 z-40 mt-2 hidden w-64 rounded-xl border border-gray-100 bg-white py-2 text-sm shadow-lg">
          <div class="border-b border-gray-100 px-4 pb-3 pt-1">
            <p id="akun-nama" class="font-bold text-[#0d3b36]">Memuat…</p>
            <p id="akun-username" class="text-xs text-gray-500"></p>
            <p id="akun-email" class="mt-1 break-all text-xs text-gray-600"></p>
            <p id="akun-hp" class="text-xs text-gray-600"></p>
          </div>
          <button id="akun-edit-btn" type="button" class="block w-full px-4 py-2 text-left hover:bg-gray-50">Edit Profil</button>
          <a href="<?= $headerEscape($reservationsUrl) ?>" class="block px-4 py-2 hover:bg-gray-50">Riwayat Reservasi</a>
          <!-- Normal POST form: works even without JavaScript. app.js shows a confirm popup first. -->
          <form id="akun-logout-form" method="post" action="<?= $headerEscape($logoutUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $headerEscape($csrfToken ?? '') ?>">
            <button id="akun-logout-btn" type="submit" class="block w-full px-4 py-2 text-left text-red-600 hover:bg-red-50">Keluar</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <a href="<?= $headerEscape($loginUrl) ?>" class="rounded-xl bg-[#0d3b36] px-4 py-2 text-sm font-semibold text-white">Masuk</a>
    <?php endif; ?>
  </header>

  <?php if ($headerLoggedIn): ?>
    <!-- Pop-up Edit Profil (sama di semua halaman pelanggan) -->
    <div id="akun-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="akun-modal-title">
      <form id="akun-form" class="max-h-full w-full max-w-md space-y-4 overflow-y-auto rounded-2xl bg-white p-6 text-[#0d3b36]" novalidate>
        <h2 id="akun-modal-title" class="text-lg font-bold">Edit Data Profil</h2>
        <div>
          <label for="akun-input-nama" class="text-sm font-semibold">Nama Lengkap</label>
          <input id="akun-input-nama" type="text" autocomplete="name" maxlength="255" aria-describedby="akun-nama-hint" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
          <p id="akun-nama-hint" class="mt-1 text-xs text-gray-500">Gunakan huruf dan spasi saja.</p>
        </div>
        <div>
          <label for="akun-input-hp" class="text-sm font-semibold">Nomor Handphone / WhatsApp</label>
          <input id="akun-input-hp" type="tel" autocomplete="tel" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
        </div>
        <div>
          <label for="akun-input-email" class="text-sm font-semibold">Email</label>
          <input id="akun-input-email" type="email" autocomplete="email" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
        </div>
        <div>
          <label for="akun-input-current-password" class="text-sm font-semibold">Password Saat Ini <span class="font-normal text-gray-400">(hanya jika mengganti password)</span></label>
          <input id="akun-input-current-password" type="password" autocomplete="current-password" placeholder="Masukkan password saat ini" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
        </div>
        <div>
          <label for="akun-input-password" class="text-sm font-semibold">Password Baru <span class="font-normal text-gray-400">(opsional)</span></label>
          <input id="akun-input-password" type="password" autocomplete="new-password" maxlength="72" placeholder="13–72 karakter" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
        </div>
        <div>
          <label for="akun-input-confirm-password" class="text-sm font-semibold">Konfirmasi Password Baru</label>
          <input id="akun-input-confirm-password" type="password" autocomplete="new-password" maxlength="72" placeholder="Masukkan ulang password baru" class="mt-1 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
        </div>
        <p id="akun-error" class="hidden text-sm text-red-600" role="alert"></p>
        <div class="flex justify-end gap-2">
          <button type="button" id="akun-batal" class="px-4 py-2 text-sm font-semibold">Batal</button>
          <button type="submit" class="rounded-lg bg-[#0d3b36] px-4 py-2 text-sm font-semibold text-white">Simpan Perubahan</button>
        </div>
      </form>
    </div>

    <!-- Pop-up konfirmasi Keluar (sama di semua halaman pelanggan) -->
    <div id="akun-logout-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="akun-logout-title">
      <div class="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 text-[#0d3b36]">
        <h2 id="akun-logout-title" class="text-lg font-bold">Keluar dari akun?</h2>
        <p class="text-sm text-gray-600">Anda perlu masuk lagi untuk melihat atau membuat reservasi.</p>
        <div class="flex justify-end gap-2">
          <button type="button" id="akun-logout-batal" class="px-4 py-2 text-sm font-semibold">Batal</button>
          <button type="button" id="akun-logout-ok" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white">Ya, Keluar</button>
        </div>
      </div>
    </div>
  <?php endif; ?>
