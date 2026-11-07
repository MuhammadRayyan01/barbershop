<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/config/session.php';
require_once __DIR__ . '/../backend/config/db.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$csrfToken = getCsrfToken();
$loginError = '';
$username = '';

if (isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id'])) {
    $stmt = $pdo->prepare('SELECT 1 FROM admin WHERE admin_id = :admin_id');
    $stmt->execute(['admin_id' => $_SESSION['admin_id']]);
    if ($stmt->fetchColumn()) {
        header('Location: dashboard.php');
        exit;
    }
    unset($_SESSION['admin_id'], $_SESSION['admin_name']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';
    if ($username === '' || !is_string($password) || $password === '') {
        $loginError = 'Username dan password wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT admin_id, username, email, full_name, password_hash FROM admin WHERE username = :username OR email = :email LIMIT 1');
            $stmt->execute(['username' => $username, 'email' => $username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$admin || !password_verify($password, $admin['password_hash'])) {
                $loginError = 'Username atau password salah.';
            } else {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int) $admin['admin_id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                unset($_SESSION['csrf_token']);
                header('Location: dashboard.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('Admin login error: ' . $e->getMessage());
            $loginError = 'Login gagal diproses. Coba lagi.';
        }
    }
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login | Pemon Barbershop</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" rel="stylesheet">
  <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="min-h-screen bg-[#f8f8fd] text-slate-900 flex flex-col items-center justify-center p-6">

  <div class="flex flex-col items-center mb-6">
    <div class="w-14 h-14 rounded-full bg-[#0d3b36] text-white flex items-center justify-center shadow-md">
      <span class="material-symbols-outlined">content_cut</span>
    </div>
    <p class="mt-3 text-[11px] font-bold tracking-[0.18em] text-slate-600">PEMON ARTISANAL ATELIER</p>
  </div>

  <main class="w-full max-w-md bg-white rounded-2xl shadow-lg shadow-slate-200/70 p-8">
    <h1 class="text-2xl font-extrabold text-center">Admin Log</h1>
    <p class="text-sm text-slate-500 text-center mt-1">Masuk ke portal manajemen kasir &amp; reservasi</p>

    <form id="login-form" method="post" action="login.php" class="mt-8 space-y-5" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <div>
        <div class="flex justify-between items-center mb-2">
          <label for="username" class="text-sm font-semibold">Username</label>
          <span class="text-[11px] text-slate-500">ID Kasir / Staf</span>
        </div>
        <div class="flex items-center gap-3 bg-[#f8f8fd] rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
          <span class="material-symbols-outlined text-[20px] text-slate-500">person</span>
          <input id="username" name="username" type="text" value="<?= $escape($username) ?>" autocomplete="username" placeholder="Masukkan username admin"
                 class="w-full bg-transparent outline-none text-sm">
        </div>
      </div>

      <div>
        <label for="password" class="text-sm font-semibold block mb-2">Password</label>
        <div class="flex items-center gap-3 bg-[#f8f8fd] rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
          <span class="material-symbols-outlined text-[20px] text-slate-500">lock</span>
          <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password"
                 class="w-full bg-transparent outline-none text-sm">
          <button type="button" id="toggle-pass" class="text-slate-500 hover:text-slate-800" aria-label="Tampilkan password">
            <span class="material-symbols-outlined text-[20px]">visibility</span>
          </button>
        </div>
      </div>

      <?php if ($loginError !== ''): ?><p class="text-sm text-red-600 bg-red-50 rounded-lg px-4 py-2"><?= $escape($loginError) ?></p><?php endif; ?>

      <button type="submit" class="w-full py-3 rounded-xl bg-[#0d3b36] hover:bg-[#082724] text-white text-sm font-bold transition">
        Masuk
      </button>
    </form>
  </main>

  <p class="text-xs text-slate-400 mt-6">&copy; Pemon Barbershop &middot; Administration Portal</p>

  <script src="../assets/js/admin-login.js"></script>
</body>
</html>
