<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/session.php';

// Redirect users who are already logged in.
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard/index.php');
    exit;
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Daftar | Pemon Barbershop</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8f8fd;
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-4 py-10">

    <div class="bg-white rounded-[24px] shadow-sm w-full max-w-[420px] p-8 md:p-10 border-t-4 border-[#0d3b36]">

        <!-- Header -->
        <div class="text-center mb-8">

            <div class="w-12 h-12 bg-[#e6f4ea] rounded-xl flex items-center justify-center mx-auto mb-4 text-[#0d3b36]">

                <span class="material-symbols-outlined text-xl">
                    content_cut
                </span>

            </div>

            <h1 class="text-2xl font-extrabold text-slate-900 mb-2">
                Daftar Akun Baru
            </h1>

            <p class="text-sm text-slate-500">
                Buat akun untuk reservasi lebih cepat dan kelola jadwal cukur Anda
            </p>

        </div>


        <!-- Registration Form -->
        <form id="register-form" method="POST" class="space-y-4" novalidate>

            <!-- Full Name -->
            <div>

                <label for="full_name" class="block text-sm font-semibold text-slate-900 mb-2">
                    Nama Lengkap
                </label>

                <div class="relative flex items-center">

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        placeholder="Misal: Raden Mas Danu"
                        autocomplete="name"
                        maxlength="150"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-4 pr-11 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                    <span class="material-symbols-outlined absolute right-4 text-slate-400 text-[20px]">
                        person
                    </span>

                </div>

            </div>


            <!-- Username -->
            <div>

                <label for="username" class="block text-sm font-semibold text-slate-900 mb-2">
                    Username
                </label>

                <div class="relative flex items-center">

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Masukkan username"
                        autocomplete="username"
                        maxlength="50"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-4 pr-11 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                    <span class="material-symbols-outlined absolute right-4 text-slate-400 text-[20px]">
                        account_circle
                    </span>

                </div>

            </div>


            <!-- Phone Number -->
            <div>

                <div class="flex justify-between items-end mb-2">

                    <label for="phone_number" class="block text-sm font-semibold text-slate-900">
                        Nomor Handphone (WhatsApp)
                    </label>

                    <span class="text-[11px] text-slate-400">
                        Untuk konfirmasi jadwal
                    </span>

                </div>

                <div class="relative flex items-center">

                    <input
                        type="tel"
                        id="phone_number"
                        name="phone_number"
                        placeholder="08xxxxxxxxxx"
                        autocomplete="tel"
                        maxlength="15"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-4 pr-11 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                    <span class="material-symbols-outlined absolute right-4 text-slate-400 text-[20px]">
                        chat
                    </span>

                </div>

            </div>


            <!-- Email -->
            <div>

                <label for="email" class="block text-sm font-semibold text-slate-900 mb-2">
                    Email
                </label>

                <div class="relative flex items-center">

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="nama@domain.com"
                        autocomplete="email"
                        maxlength="254"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-4 pr-11 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                    <span class="material-symbols-outlined absolute right-4 text-slate-400 text-[20px]">
                        mail
                    </span>

                </div>

            </div>


            <!-- Password -->
            <div>

                <label for="reg-password" class="block text-sm font-semibold text-slate-900 mb-2">
                    Kata Sandi
                </label>

                <div class="relative flex items-center">

                    <input
                        type="password"
                        id="reg-password"
                        name="password"
                        placeholder="Minimal 12 karakter"
                        autocomplete="new-password"
                        maxlength="72"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-4 pr-11 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                    <button
                        type="button"
                        onclick="togglePassword('reg-password', 'reg-eye-1')"
                        class="absolute right-4 text-slate-400 hover:text-slate-600 focus:outline-none flex items-center"
                    >

                        <span id="reg-eye-1" class="material-symbols-outlined text-[20px]">
                            visibility_off
                        </span>

                    </button>

                </div>

            </div>


            <!-- Confirm Password -->
            <div>

                <label for="reg-password-confirm" class="block text-sm font-semibold text-slate-900 mb-2">
                    Konfirmasi Kata Sandi
                </label>

                <div class="relative flex items-center">

                    <input
                        type="password"
                        id="reg-password-confirm"
                        placeholder="Ulangi kata sandi"
                        autocomplete="new-password"
                        maxlength="72"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-4 pr-11 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                    <button
                        type="button"
                        onclick="togglePassword('reg-password-confirm', 'reg-eye-2')"
                        class="absolute right-4 text-slate-400 hover:text-slate-600 focus:outline-none flex items-center"
                    >

                        <span id="reg-eye-2" class="material-symbols-outlined text-[20px]">
                            visibility_off
                        </span>

                    </button>

                </div>

            </div>


            <!-- API Message -->
            <div
                id="register-message"
                class="hidden text-sm rounded-xl p-3"
                role="alert"
                aria-live="polite"
            ></div>


            <!-- Terms & Submit -->
            <div class="pt-2">

                <p class="text-[11px] text-center text-slate-500 mb-4 px-2 leading-relaxed">

                    Dengan mendaftar, Anda menyetujui Ketentuan Layanan dan Kebijakan Privasi Pemon Barbershop.

                </p>

                <button
                    type="submit"
                    id="register-button"
                    class="w-full bg-[#0d3b36] hover:bg-[#0a2e2a] text-white font-semibold rounded-xl py-3.5 flex items-center justify-center gap-2 transition-all disabled:opacity-60 disabled:cursor-not-allowed"
                >

                    <span id="register-button-text">
                        Daftar Sekarang
                    </span>

                    <span class="material-symbols-outlined text-[18px]">
                        arrow_forward
                    </span>

                </button>

            </div>

        </form>


        <!-- Footer -->
        <div class="text-center mt-6 pt-6 border-t border-slate-100">

            <p class="text-sm text-slate-600">

                Sudah punya akun?

                <a
                    href="login.php"
                    class="font-bold text-slate-900 hover:text-[#0d3b36]"
                >
                    Masuk di sini
                </a>

            </p>

        </div>

    </div>


    <!-- Password Toggle -->
    <script>
        function togglePassword(inputId, iconId) {

            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);

            if (input.type === 'password') {

                input.type = 'text';
                icon.textContent = 'visibility';

            } else {

                input.type = 'password';
                icon.textContent = 'visibility_off';

            }

        }
    </script>

    <!-- Registration JavaScript -->
    <script src="../assets/js/user_register.js" defer></script>

</body>
</html>
