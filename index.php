<?php
declare(strict_types=1);

require_once __DIR__ . '/backend/config/session.php';
require_once __DIR__ . '/backend/config/booking.php';

$loggedIn = isset($_SESSION['user_id']) && is_int($_SESSION['user_id']) && $_SESSION['user_id'] > 0;
$bookingUrl = $loggedIn ? '/user/booking/booking.php' : '/user/login.php?next=booking';
$accountUrl = $loggedIn ? '/user/dashboard/index.php' : '/user/login.php';
$openingTime = substr(BOOKING_OPENING_TIME, 0, 5);
$closingTime = substr(BOOKING_CLOSING_TIME, 0, 5);
$currentYear = (new DateTimeImmutable('now', new DateTimeZone(BOOKING_TIMEZONE)))->format('Y');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#123f3a">
  <meta name="description" content="Pemon Barbershop — pilih layanan dan jadwal reservasi secara online dengan informasi harga dan durasi yang jelas.">
  <title>Pemon Barbershop | Reservasi Grooming</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/tailwind.css">
</head>
<body data-booking-url="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>">
  <header class="site-header">
    <div class="nav-wrap">
      <a class="brand" href="/" aria-label="Pemon Barbershop, beranda">
        <span class="brand-mark">P</span>
        <span class="brand-name">PEMON <small>BARBERSHOP</small></span>
      </a>
      <button class="menu-toggle" id="menu-toggle" type="button" aria-expanded="false" aria-controls="main-nav" aria-label="Buka navigasi">
        <span class="material-symbols-outlined" aria-hidden="true">menu</span>
      </button>
      <nav class="main-nav" id="main-nav" aria-label="Navigasi utama">
        <a href="#layanan">Layanan</a>
        <a href="#cara-reservasi">Cara Reservasi</a>
        <a href="#jadwal">Jadwal</a>
        <a href="#lokasi">Lokasi</a>
      </nav>
      <div class="nav-actions">
        <a class="button button-primary button-small" href="<?= htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8') ?>">
          <?= $loggedIn ? 'Dashboard' : 'Masuk' ?>
        </a>
        <a class="button button-primary button-small nav-book" href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>">Reservasi</a>
      </div>
    </div>
  </header>

  <main>
    <div class="status-strip">
      <div class="landing-container status-inner">
        <span class="status-dot" aria-hidden="true"></span>
        <strong>Reservasi online tersedia</strong>
        <span class="status-divider">·</span>
        <span>Jam pilihan <?= htmlspecialchars($openingTime, ENT_QUOTES, 'UTF-8') ?>–<?= htmlspecialchars($closingTime, ENT_QUOTES, 'UTF-8') ?> WIB</span>
        <span class="status-note">Kapasitas jadwal diperbarui dari sistem reservasi</span>
      </div>
    </div>

    <section class="hero">
      <div class="landing-container hero-grid">
        <div class="hero-copy">
          <span class="eyebrow"><span class="material-symbols-outlined" aria-hidden="true">content_cut</span> Pemon Barbershop</span>
          <h1>Potongan rapi. Waktu kunjungan yang terencana.</h1>
          <p>Pilih layanan, lihat durasi dan harga, lalu tentukan jadwal yang tersedia. Reservasi dimulai online agar kunjunganmu lebih mudah direncanakan.</p>
          <div class="hero-actions">
            <a class="button button-primary" href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>">Buat reservasi <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a>
            <a class="button button-light" href="#layanan">Lihat layanan</a>
          </div>
          <div class="hero-points" aria-label="Informasi reservasi">
            <div><strong><?= htmlspecialchars($openingTime, ENT_QUOTES, 'UTF-8') ?>–<?= htmlspecialchars($closingTime, ENT_QUOTES, 'UTF-8') ?></strong><span>Jam pilihan reservasi</span></div>
            <div><strong>Durasi jelas</strong><span>Ditampilkan per layanan</span></div>
            <div><strong>Jadwal aktual</strong><span>Kapasitas mengikuti barber</span></div>
          </div>
        </div>
        <div class="hero-visual">
          <img src="https://lh3.googleusercontent.com/aida-public/AB6AXuB8Tf3Mg_yjXNRefjUlalyweYUYwunyj57MO9xO5fjieg6Rf3kt0cCpMDM5a0Ym9-3UOQeUcAoMbyABUD9HfTCWArme0eFAT_E1cIJw1lH5_nMJxsmWuLDcIci6IxOwCAh0lRaQqhisCt8DIHCr7axY9PsJfakFmEpf_CQ5YdJfIi0GnFVc5y-YqlpbhPHuFcepBLUpDubaCv6cxpZnORHpbVILnZ_gPBrOSz2NUms" alt="Suasana ruang grooming Pemon Barbershop" fetchpriority="high">
          <div class="visual-caption"><span>PEMON BARBERSHOP</span><strong>Perawatan yang dimulai dari jadwal yang jelas.</strong></div>
          <div class="visual-badge"><span class="material-symbols-outlined" aria-hidden="true">schedule</span><span>Reservasi sesuai jadwal tersedia</span></div>
        </div>
      </div>
    </section>

    <section class="benefits section-muted" id="cara-reservasi">
      <div class="landing-container">
        <div class="section-heading heading-row">
          <div><span class="section-kicker">Pengalaman reservasi</span><h2>Datang dengan rencana yang jelas</h2></div>
          <p>Pilih sendiri layanan dan waktu kunjungan. Informasi harga, durasi, serta ketersediaan ditampilkan sebelum reservasi dikonfirmasi.</p>
        </div>
        <div class="benefit-grid">
          <article class="benefit-card"><span class="material-symbols-outlined">event_available</span><h3>Pilih jadwal</h3><p>Lihat jadwal per jam dan kapasitas yang tersedia sebelum menentukan waktu kunjungan.</p></article>
          <article class="benefit-card"><span class="material-symbols-outlined">content_cut</span><h3>Pilih layanan</h3><p>Bandingkan layanan, deskripsi, harga, dan durasi dari katalog yang tersimpan di website.</p></article>
          <article class="benefit-card"><span class="material-symbols-outlined">receipt_long</span><h3>Kelola reservasi</h3><p>Setelah masuk, pelanggan dapat melihat reservasi mendatang dan riwayat kunjungannya.</p></article>
        </div>
      </div>
    </section>

    <section class="services section" id="layanan">
      <div class="landing-container">
        <div class="section-heading heading-row">
          <div><span class="section-kicker">Katalog layanan</span><h2>Pilih layanan yang kamu butuhkan</h2></div>
          <a class="text-link" href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>">Mulai reservasi <span aria-hidden="true">→</span></a>
        </div>
        <div class="service-grid" id="services-grid" aria-live="polite">
          <p class="loading-card">Memuat layanan dari katalog...</p>
        </div>
        <p class="section-footnote" id="services-note">Harga dan durasi berasal dari katalog layanan aktif.</p>
      </div>
    </section>

    <section class="schedule section-muted" id="jadwal">
      <div class="landing-container">
        <div class="schedule-panel">
          <div class="section-heading heading-row">
            <div><span class="section-kicker">Ketersediaan jadwal</span><h2>Lihat slot yang tersedia hari ini</h2><p class="section-description">Ketersediaan dapat berubah saat reservasi dibuat. Jadwal akan diperiksa kembali saat kamu melanjutkan booking.</p></div>
            <div class="today-chip"><span class="material-symbols-outlined" aria-hidden="true">calendar_month</span><span id="today-label">Hari ini · WIB</span></div>
          </div>
          <div class="slot-grid" id="slot-grid" aria-live="polite"><p class="slot-message">Memuat ketersediaan jadwal...</p></div>
          <div class="schedule-footer"><p id="availability-note">Slot tersedia mengikuti kapasitas barber yang bertugas.</p><a class="button button-primary" href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>">Pilih jadwal <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a></div>
        </div>
      </div>
    </section>

    <section class="steps section" id="tentang-pemon">
      <div class="landing-container steps-layout">
        <div><span class="section-kicker">Cara reservasi</span><h2>Tiga langkah menuju kunjunganmu</h2><p class="section-description">Alur reservasi dirancang supaya pelanggan dapat memilih kebutuhan dan jadwal sebelum datang ke studio.</p></div>
        <ol class="step-list">
          <li><span>01</span><div><h3>Masuk atau buat akun</h3><p>Akun digunakan untuk menyimpan dan mengelola reservasi.</p></div></li>
          <li><span>02</span><div><h3>Pilih layanan dan jadwal</h3><p>Periksa deskripsi, durasi, harga, lalu pilih slot yang masih tersedia.</p></div></li>
          <li><span>03</span><div><h3>Konfirmasi reservasi</h3><p>Ikuti instruksi pembayaran dan cek status reservasi dari dashboard pelanggan.</p></div></li>
        </ol>
      </div>
    </section>

    <section class="location section-muted" id="lokasi">
      <div class="landing-container location-panel">
        <div><span class="section-kicker">Kunjungi studio</span><h2>Pemon Barbershop</h2><p>Studio Senopati, Jakarta Selatan</p><p class="section-description">Jam reservasi yang tersedia: <?= htmlspecialchars($openingTime, ENT_QUOTES, 'UTF-8') ?>–<?= htmlspecialchars($closingTime, ENT_QUOTES, 'UTF-8') ?> WIB. Pilih tanggal dan slot pada halaman booking untuk melihat jadwal yang dapat dipesan.</p></div>
        <a class="button button-primary" href="<?= htmlspecialchars($bookingUrl, ENT_QUOTES, 'UTF-8') ?>">Reservasi sekarang <span class="material-symbols-outlined" aria-hidden="true">arrow_forward</span></a>
      </div>
    </section>
  </main>

  <footer class="site-footer">
    <div class="landing-container footer-inner"><a class="brand" href="/" aria-label="Pemon Barbershop"><span class="brand-mark">P</span><span class="brand-name">PEMON <small>BARBERSHOP</small></span></a><p>© <?= htmlspecialchars($currentYear, ENT_QUOTES, 'UTF-8') ?> Pemon Barbershop. Waktu operasional menggunakan WIB.</p><a href="<?= htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8') ?>"><?= $loggedIn ? 'Dashboard pelanggan' : 'Masuk ke akun' ?></a></div>
  </footer>
  <script src="/assets/js/landing.js" defer></script>
</body>
</html>
