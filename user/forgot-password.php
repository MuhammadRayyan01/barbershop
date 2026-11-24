<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/config/session.php';
require_once __DIR__ . '/../backend/config/csrf.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard/index.php');
    exit;
}

$csrfToken = getCsrfToken();
$resetToken = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
$isReset = preg_match('/^[a-f0-9]{64}$/', $resetToken) === 1;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isReset ? 'Atur Ulang Kata Sandi' : 'Lupa Kata Sandi' ?> | Pemon Barbershop</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
    <style>body{font-family:'Plus Jakarta Sans',sans-serif;background-color:#f8f8fd}</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <main class="bg-white rounded-[24px] shadow-sm w-full max-w-[420px] p-8 md:p-10">
        <header class="text-center mb-8">
            <div class="w-14 h-14 bg-[#e6f4ea] rounded-full flex items-center justify-center mx-auto mb-4 text-[#0d3b36]">
                <span class="material-symbols-outlined text-2xl"><?= $isReset ? 'lock_reset' : 'key' ?></span>
            </div>
            <p class="text-xs font-bold tracking-widest text-slate-500 uppercase mb-2">Pemon Barbershop</p>
            <h1 class="text-2xl font-extrabold text-slate-900 mb-2"><?= $isReset ? 'Atur Ulang Kata Sandi' : 'Lupa Kata Sandi?' ?></h1>
            <p class="text-sm text-slate-500"><?= $isReset ? 'Buat kata sandi baru untuk akun Anda' : 'Masukkan email akun Anda untuk menerima tautan pengaturan ulang' ?></p>
        </header>

        <form id="forgot-password-form" class="space-y-5" data-mode="<?= $isReset ? 'reset' : 'request' ?>" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" novalidate>
            <?php if ($isReset): ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label for="new-password" class="block text-sm font-semibold text-slate-900 mb-2">Kata Sandi Baru</label>
                    <input type="password" id="new-password" name="password" autocomplete="new-password" minlength="12" maxlength="72" required placeholder="Minimal 12 karakter" class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 px-4 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 text-slate-700 placeholder-slate-400">
                </div>
                <div>
                    <label for="confirm-password" class="block text-sm font-semibold text-slate-900 mb-2">Ulangi Kata Sandi Baru</label>
                    <input type="password" id="confirm-password" name="confirm_password" autocomplete="new-password" minlength="12" maxlength="72" required placeholder="Masukkan ulang kata sandi" class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 px-4 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 text-slate-700 placeholder-slate-400">
                </div>
            <?php else: ?>
                <div>
                    <label for="email" class="block text-sm font-semibold text-slate-900 mb-2">Email</label>
                    <div class="relative flex items-center">
                        <span class="material-symbols-outlined absolute left-4 text-slate-400 text-[20px]">mail</span>
                        <input type="email" id="email" name="email" autocomplete="email" maxlength="254" required placeholder="nama@domain.com" class="w-full bg-[#f8f8fd] text-sm rounded-xl py-3.5 pl-11 pr-4 outline-none focus:ring-2 focus:ring-[#0d3b36]/20 text-slate-700 placeholder-slate-400">
                    </div>
                </div>
            <?php endif; ?>
            <div id="forgot-password-message" class="hidden text-sm rounded-xl p-3" role="status" aria-live="polite"></div>
            <button type="submit" id="forgot-password-submit" class="w-full bg-[#0d3b36] hover:bg-[#0a2e2a] text-white font-semibold rounded-xl py-3.5 flex items-center justify-center gap-2 transition-all">
                <span><?= $isReset ? 'Simpan Kata Sandi' : 'Kirim Tautan' ?></span>
                <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
            </button>
        </form>
        <div class="text-center mt-8">
            <a href="login.php" class="text-sm font-bold text-slate-900 hover:text-[#0d3b36]">Kembali ke halaman masuk</a>
        </div>
    </main>
    <script src="../assets/js/forgot_password.js" defer></script>
</body>
</html>
