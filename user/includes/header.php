<?php
require_once __DIR__ . '/../../backend/config/session.php';

$pageTitle = htmlspecialchars($userPageTitle ?? 'Pemon Barbershop', ENT_QUOTES, 'UTF-8');
$activePage = $userActivePage ?? '';
$assetPrefix = $userAssetPrefix ?? '../../';
$bodyClass = $userBodyClass ?? 'min-h-screen bg-[#f4f7f5] text-[#0d3b36]';
$showProfileEdit = $userShowProfileEdit ?? false;
$dashboardUrl = $assetPrefix . 'user/dashboard/index.php';
$scheduleUrl = $assetPrefix . 'user/dashboard/schedule.php';
$bookingUrl = $assetPrefix . 'user/booking/booking.php';
$aboutUrl = $assetPrefix . 'user/dashboard/aboutUs.html';
$logoutUrl = $assetPrefix . 'backend/auth/logout.php';
$isLoggedIn = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php if (isset($csrfToken)): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <title><?= $pageTitle ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($assetPrefix . 'assets/css/tailwind.css', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
  <header class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-4 py-4">
    <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex shrink-0 items-center gap-3" aria-label="Pemon Barbershop">
      <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-[#0d3b36] text-xl font-bold text-white">P</span>
      <span class="font-bold tracking-wide">PEMON <span class="block text-xs font-normal text-gray-500">BARBERSHOP</span></span>
    </a>

    <nav class="order-last flex w-full items-center justify-center gap-4 border-t border-gray-200/70 pt-3 text-xs font-medium sm:order-none sm:w-auto sm:justify-start sm:border-0 sm:pt-0 sm:text-sm sm:gap-5" aria-label="Navigasi utama">
      <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" <?= $activePage === 'dashboard' ? 'aria-current="page"' : '' ?> class="<?= $activePage === 'dashboard' ? 'font-semibold text-[#0d3b36]' : 'text-gray-600 hover:text-[#0d3b36]' ?>">Dashboard</a>
      <a href="<?= htmlspecialchars($scheduleUrl, ENT_QUOTES, 'UTF-8') ?>" <?= $activePage === 'schedule' ? 'aria-current="page"' : '' ?> class="<?= $activePage === 'schedule' ? 'font-semibold text-[#0d3b36]' : 'text-gray-600 hover:text-[#0d3b36]' ?>">Jadwal</a>
      <a href="<?= htmlspecialchars($aboutUrl, ENT_QUOTES, 'UTF-8') ?>" <?= $activePage === 'about' ? 'aria-current="page"' : '' ?> class="<?= $activePage === 'about' ? 'font-semibold text-[#0d3b36]' : 'text-gray-600 hover:text-[#0d3b36]' ?>">Tentang</a>
      <a href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>" <?= $activePage === 'booking' ? 'aria-current="page"' : '' ?> class="<?= $activePage === 'booking' ? 'font-semibold text-[#0d3b36]' : 'text-gray-600 hover:text-[#0d3b36]' ?>">Reservasi</a>
    </nav>

    <?php if ($isLoggedIn): ?>
      <div class="relative shrink-0">
        <button id="akun-btn" type="button" class="flex items-center gap-2 rounded-xl px-2 py-1 hover:bg-white" aria-expanded="false" aria-controls="akun-menu">
          <span class="flex h-10 w-10 items-center justify-center rounded-full bg-[#0d3b36] font-bold text-white">U</span>
          <span class="hidden text-sm font-medium sm:block">Akun</span>
        </button>
        <div id="akun-menu" class="absolute right-0 z-40 mt-2 hidden w-56 rounded-xl border border-gray-100 bg-white py-2 text-sm shadow-lg">
          <?php if ($showProfileEdit): ?>
            <button id="akun-edit-btn" type="button" class="block w-full px-4 py-2 text-left hover:bg-gray-50">Edit Profil</button>
          <?php else: ?>
            <a href="<?= htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') ?>" class="block px-4 py-2 hover:bg-gray-50">Kelola Profil</a>
          <?php endif; ?>
          <a href="<?= htmlspecialchars($scheduleUrl, ENT_QUOTES, 'UTF-8') ?>" class="block px-4 py-2 hover:bg-gray-50">Riwayat Reservasi</a>
          <form method="post" action="<?= htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="block w-full px-4 py-2 text-left text-red-600 hover:bg-red-50">Keluar</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <a href="<?= htmlspecialchars($assetPrefix . 'user/login.php', ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl bg-[#0d3b36] px-4 py-2 text-sm font-semibold text-white">Masuk</a>
    <?php endif; ?>
  </header>
