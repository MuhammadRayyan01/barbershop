<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/session.php';
require_once __DIR__ . '/../../backend/config/csrf.php';

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) || $userId < 1) {
    header('Location: /user/login.php');
    exit;
}

$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="csrf-token"
        content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
    >

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard Pelanggan | Pemon Barbershop</title>

    <link rel="stylesheet" href="../../assets/css/tailwind.css">

    <style>
        body {
            font-family: Arial, sans-serif;
        }
    </style>
</head>

<body class="bg-[#f4f7f5] text-[#0d3b36] font-sans min-h-screen flex flex-col justify-between p-6">

    <!-- Header / Navbar -->
    <header class="max-w-5xl w-full mx-auto flex justify-between items-center py-4">

        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-[#0d3b36] text-white flex items-center justify-center rounded-lg font-bold text-xl">
                P
            </div>

            <span class="font-bold tracking-wide">
                PEMON
                <br>
                <span class="text-xs font-normal text-gray-500">
                    BARBERSHOP
                </span>
            </span>
        </div>

        <nav
            class="hidden md:flex items-center gap-6 font-medium text-sm"
            aria-label="Navigasi utama"
        >
            <a
                href="index.php"
                aria-current="page"
                class="text-[#0d3b36]"
            >
                Dashboard
            </a>

            <a
                href="schedule.php"
                class="text-gray-600 hover:text-[#0d3b36]"
            >
                Schedule
            </a>

            <a
                href="../aboutUs.php"
                class="text-gray-600 hover:text-[#0d3b36]"
            >
                About Us
            </a>
        </nav>

        <!-- Account Menu -->
        <div class="relative">

            <button
                type="button"
                id="akun-btn"
                class="flex items-center gap-2"
                aria-label="Buka menu akun"
                aria-expanded="false"
            >
                <span class="w-10 h-10 bg-[#0d3b36] text-white rounded-full flex items-center justify-center font-bold">
                    P
                </span>

                <span class="hidden sm:block text-sm font-medium">
                    Akun
                </span>
            </button>

            <div
                id="akun-menu"
                class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-lg border border-gray-100 z-40 text-sm"
            >

                <div class="p-4 border-b border-gray-100">

                    <p class="font-bold">
                        Halo,
                        <span id="akun-nama-depan"></span>
                    </p>

                    <p
                        id="akun-nama"
                        class="text-xs text-gray-500 mt-1"
                    ></p>

                    <p
                        id="akun-hp"
                        class="text-xs text-gray-500"
                    ></p>

                    <p
                        id="akun-email"
                        class="text-xs text-gray-500"
                    ></p>

                </div>

                <button
                    type="button"
                    id="akun-edit-btn"
                    class="block w-full text-left px-4 py-2 hover:bg-gray-50"
                >
                    Edit Profil
                </button>

                <a
                    href="schedule.php"
                    class="block px-4 py-2 hover:bg-gray-50"
                >
                    Riwayat Reservasi
                </a>

                <a
                    href="../../backend/auth/logout.php"
                    id="logout-btn"
                    class="block px-4 py-2 text-red-600 hover:bg-red-50"
                >
                    Keluar
                </a>

            </div>
        </div>

    </header>


    <!-- Main Content -->
    <main class="max-w-3xl w-full mx-auto my-8 space-y-4">

        <!-- Dashboard Summary -->
        <section
            class="bg-white rounded-2xl p-6 sm:p-8 shadow-sm border border-gray-100 space-y-6"
            aria-labelledby="dashboard-title"
        >

            <div>

                <span class="inline-flex items-center gap-2 bg-[#e2efe8] text-[#0d3b36] text-xs font-semibold px-3 py-1 rounded-full">
                    <span aria-hidden="true">●</span>
                    IKHTISAR AKUN
                </span>

                <h1
                    id="dashboard-title"
                    class="mt-4 text-2xl sm:text-3xl font-extrabold text-[#0d3b36] leading-tight"
                >
                    Selamat datang kembali,
                    <span id="dashboard-nama">Memuat...</span>
                </h1>

                <p class="mt-2 text-gray-600 text-sm leading-relaxed">
                    Lihat reservasi Anda dan kelola jadwal kunjungan di Pemon Barbershop.
                </p>

            </div>


            <!-- Reservation Statistics -->
            <div
                class="grid grid-cols-2 gap-4 sm:gap-8"
                aria-label="Ringkasan reservasi"
            >

                <div class="space-y-1">

                    <span aria-hidden="true" class="text-lg">
                        ▦
                    </span>

                    <p
                        id="jumlah-reservasi"
                        class="text-2xl font-bold"
                    >
                        0
                    </p>

                    <p class="text-xs sm:text-sm text-gray-600">
                        Reservasi mendatang
                    </p>

                </div>


                <div class="space-y-1">

                    <span aria-hidden="true" class="text-lg">
                        ▤
                    </span>

                    <p
                        id="jumlah-pembayaran"
                        class="text-2xl font-bold"
                    >
                        0
                    </p>

                    <p class="text-xs sm:text-sm text-gray-600">
                        Menunggu pembayaran
                    </p>

                </div>

            </div>


            <hr class="border-gray-100">


            <!-- Upcoming Reservations -->
            <section aria-labelledby="reservasi-title">

                <h2
                    id="reservasi-title"
                    class="font-bold text-sm mb-3"
                >
                    Reservasi Mendatang
                </h2>

                <div id="reservasi-container">

                    <p class="text-sm text-gray-500">
                        Memuat data reservasi...
                    </p>

                </div>

            </section>


            <hr class="border-gray-100">


            <!-- Barbershop Features -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">

                <div>
                    <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">
                        Master Barber
                    </p>

                    <p class="font-bold text-[#0d3b36]">
                        Berpengalaman & Tersertifikasi
                    </p>
                </div>


                <div>
                    <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">
                        Jaminan Waktu
                    </p>

                    <p class="font-bold text-[#0d3b36]">
                        Tepat Waktu & Bebas Antre
                    </p>
                </div>


                <div>
                    <p class="text-gray-400 font-semibold uppercase tracking-wider mb-1">
                        Higienis
                    </p>

                    <p class="font-bold text-[#0d3b36]">
                        Peralatan Steril & Eksklusif
                    </p>
                </div>

            </div>

        </section>


        <!-- Location & Booking CTA -->
        <div class="bg-[#eaf4ef] border border-[#d2e8dd] rounded-2xl p-6 flex flex-col md:flex-row justify-between items-center gap-4">

            <div>

                <h3 class="font-bold text-[#0d3b36] text-sm">
                    ◎ &nbsp;Studio Senopati, Jakarta Selatan
                </h3>

                <p class="text-xs text-gray-600 mt-1">
                    ◷ &nbsp;Buka Setiap Hari: 10:00 - 21:00 WIB
                </p>

            </div>

            <a
                href="../booking/booking.html"
                class="bg-[#0d3b36] hover:bg-[#082724] text-white font-semibold text-sm px-6 py-3 rounded-xl transition flex items-center gap-2"
            >
                Mulai Reservasi Sekarang
                <span>&rarr;</span>
            </a>

        </div>

    </main>


    <!-- Edit Profile Modal -->
    <div
        id="akun-modal"
        class="hidden fixed inset-0 z-50 items-center justify-center bg-black/40 p-4"
    >

        <form
            id="akun-form"
            class="bg-white rounded-2xl w-full max-w-md p-6 space-y-4 text-[#0d3b36] max-h-[90vh] overflow-y-auto"
        >

            <h2 class="text-lg font-bold">
                Edit Data Profil
            </h2>


            <!-- Full Name -->
            <div>

                <label
                    for="akun-input-nama"
                    class="text-sm font-semibold"
                >
                    Nama Lengkap
                </label>

                <input
                    id="akun-input-nama"
                    type="text"
                    autocomplete="name"
                    maxlength="255"
                    required
                    class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                >

            </div>


            <!-- Phone Number -->
            <div>

                <label
                    for="akun-input-hp"
                    class="text-sm font-semibold"
                >
                    Nomor Handphone / WhatsApp
                </label>

                <input
                    id="akun-input-hp"
                    type="tel"
                    autocomplete="tel"
                    maxlength="15"
                    required
                    class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                >

            </div>


            <!-- Email -->
            <div>

                <label
                    for="akun-input-email"
                    class="text-sm font-semibold"
                >
                    Email
                </label>

                <input
                    id="akun-input-email"
                    type="email"
                    autocomplete="email"
                    maxlength="255"
                    required
                    class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                >

            </div>


            <hr class="border-gray-100">


            <!-- Password Change Section -->
            <div>

                <h3 class="text-sm font-bold">
                    Ubah Password
                </h3>

                <p class="text-xs text-gray-500 mt-1">
                    Kosongkan bagian password jika Anda tidak ingin mengubahnya.
                </p>

            </div>


            <!-- Current Password -->
            <div>

                <label
                    for="akun-input-current-password"
                    class="text-sm font-semibold"
                >
                    Password Saat Ini
                </label>

                <input
                    id="akun-input-current-password"
                    type="password"
                    autocomplete="current-password"
                    placeholder="Masukkan password saat ini"
                    class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                >

            </div>


            <!-- New Password -->
            <div>

                <label
                    for="akun-input-password"
                    class="text-sm font-semibold"
                >
                    Password Baru
                </label>

                <input
                    id="akun-input-password"
                    type="password"
                    autocomplete="new-password"
                    minlength="13"
                    maxlength="72"
                    placeholder="13–72 karakter"
                    class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                >

            </div>


            <!-- Confirm Password -->
            <div>

                <label
                    for="akun-input-confirm-password"
                    class="text-sm font-semibold"
                >
                    Konfirmasi Password Baru
                </label>

                <input
                    id="akun-input-confirm-password"
                    type="password"
                    autocomplete="new-password"
                    minlength="13"
                    maxlength="72"
                    placeholder="Masukkan ulang password baru"
                    class="w-full mt-1 border border-gray-200 rounded-lg px-3 py-2 text-sm"
                >

            </div>


            <!-- Error Message -->
            <p
                id="akun-error"
                class="hidden text-sm text-red-600"
                role="alert"
            ></p>


            <!-- Modal Buttons -->
            <div class="flex justify-end gap-2">

                <button
                    type="button"
                    id="akun-batal"
                    class="px-4 py-2 text-sm font-semibold"
                >
                    Batal
                </button>

                <button
                    type="submit"
                    class="px-4 py-2 rounded-lg bg-[#0d3b36] text-white text-sm font-semibold"
                >
                    Simpan Perubahan
                </button>

            </div>

        </form>

    </div>


    <!-- Dashboard JavaScript -->
    <script src="../../assets/js/dashboard.js"></script>


    <!-- Footer -->
    <footer class="max-w-5xl w-full mx-auto text-xs text-gray-400 flex justify-between items-center py-4">

        <p>
            © 2025 Pemon Barbershop. All rights reserved.
        </p>

        <div class="flex gap-4">

            <a href="#" class="hover:underline">
                Syarat & Ketentuan
            </a>

            <a href="#" class="hover:underline">
                Bantuan
            </a>

        </div>

    </footer>

</body>
</html>
