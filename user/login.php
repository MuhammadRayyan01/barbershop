<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/session.php';

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

    <title>Masuk | Pemon Barbershop</title>

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

<body class="min-h-screen flex items-center justify-center p-4">

    <div class="bg-white rounded-[24px] shadow-sm w-full max-w-[420px] p-8 md:p-10">

        <!-- Header -->
        <div class="text-center mb-8">

            <div class="w-14 h-14 bg-[#e6f4ea] rounded-full flex items-center justify-center mx-auto mb-4 text-[#0d3b36]">
                <span class="material-symbols-outlined text-2xl">
                    content_cut
                </span>
            </div>

            <p class="text-xs font-bold tracking-widest text-slate-500 uppercase mb-2">
                Pemon Barbershop
            </p>

            <h1 class="text-2xl font-extrabold text-slate-900 mb-2">
                Masuk ke Akun
            </h1>

            <p class="text-sm text-slate-500">
                Lanjutkan reservasi perawatan dan jadwal cukur Anda
            </p>

        </div>


        <!-- Login Form -->
        <form id="login-form" method="POST" action="../backend/auth/login.php" class="space-y-5" novalidate>

            <!-- Username / Email -->
            <div>

                <label for="login-identifier" class="block text-sm font-semibold text-slate-900 mb-2">
                    Username atau Email
                </label>

                <div class="relative flex items-center">

                    <span class="material-symbols-outlined absolute left-4 text-slate-400 text-[20px]">
                        person
                    </span>

                    <input
                        type="text"
                        id="login-identifier"
                        name="login"
                        placeholder="Masukkan username atau email"
                        autocomplete="username"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-11 pr-4 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                </div>

            </div>


            <!-- Password -->
            <div>

                <label for="login-password" class="block text-sm font-semibold text-slate-900 mb-2">
                    Kata Sandi
                </label>

                <div class="relative flex items-center">

                    <span class="material-symbols-outlined absolute left-4 text-slate-400 text-[20px]">
                        lock
                    </span>

                    <input
                        type="password"
                        id="login-password"
                        name="password"
                        placeholder="Masukkan kata sandi"
                        autocomplete="current-password"
                        required
                        class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-11 pr-11 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 transition-all text-slate-700 placeholder-slate-400"
                    >

                    <button
                        type="button"
                        onclick="togglePassword('login-password', 'login-eye')"
                        class="absolute right-4 text-slate-400 hover:text-slate-600 focus:outline-none flex items-center"
                        aria-label="Tampilkan kata sandi"
                    >

                        <span id="login-eye" class="material-symbols-outlined text-[20px]">
                            visibility_off
                        </span>

                    </button>

                </div>

            </div>


            <!-- Error Message -->
            <div
                id="login-message"
                class="hidden text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl p-3"
                role="alert"
                aria-live="polite"
            ></div>


            <!-- Remember Me & Forgot Password -->
            <div class="flex items-center justify-between pt-1">

                <label class="flex items-center gap-2 cursor-pointer group">

                    <input
                        type="checkbox"
                        id="remember-me"
                        name="remember"
                        class="w-4 h-4 rounded border-slate-300 text-[#0d3b36] focus:ring-[#0d3b36] cursor-pointer"
                    >

                    <span class="text-sm text-slate-600 group-hover:text-slate-900 transition-colors">
                        Ingat saya
                    </span>

                </label>

                <a
                    href="forgot-password.php"
                    class="text-sm font-semibold text-slate-900 hover:text-[#0d3b36] transition-colors"
                >
                    Lupa Password?
                </a>

            </div>


            <!-- Submit Button -->
            <button
                type="submit"
                id="login-submit"
                class="w-full bg-[#0d3b36] hover:bg-[#0a2e2a] text-white font-semibold rounded-xl py-3.5 mt-2 flex items-center justify-center gap-2 transition-all"
            >

                <span id="login-button-text">Masuk</span>

                <span class="material-symbols-outlined text-[18px]">
                    arrow_forward
                </span>

            </button>

        </form>


        <!-- Footer -->
        <div class="text-center mt-8">

            <p class="text-sm text-slate-600">

                Belum punya akun?

                <a
                    href="register.html"
                    class="font-bold text-slate-900 hover:text-[#0d3b36]"
                >
                    Daftar sekarang
                </a>

            </p>

        </div>

    </div>


    <!-- Password Visibility -->
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


    <!-- Login API JavaScript -->
    <script src="../assets/js/user_login.js" defer></script>

</body>
</html>
