<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$adminPageTitle = 'Daftarkan Admin | Pemon Barbershop';
$csrfToken = getCsrfToken();
$errors = [];
$values = ['full_name' => '', 'username' => '', 'email' => ''];
$created = false;
$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');

    foreach ($values as $field => $_) {
        $values[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if ($values['full_name'] === '' || $values['username'] === '' || $values['email'] === '' || !is_string($password) || $password === '' || !is_string($passwordConfirm) || $passwordConfirm === '') {
        $errors[] = 'Semua kolom wajib diisi.';
    }
    if (strlen($values['full_name']) > 150 || strlen($values['username']) > 50 || strlen($values['email']) > 254) {
        $errors[] = 'Nama, username, atau email melebihi batas panjang.';
    }
    if ($values['email'] !== '' && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Masukkan alamat email yang valid.';
    }
    if ($values['username'] !== '' && filter_var($values['username'], FILTER_VALIDATE_EMAIL) !== false) {
        $errors[] = 'Username tidak boleh berupa alamat email.';
    }
    if (is_string($password) && (strlen($password) < 12 || strlen($password) > 72)) {
        $errors[] = 'Kata sandi harus terdiri dari 12 sampai 72 karakter.';
    }
    if (is_string($password) && is_string($passwordConfirm) && $password !== $passwordConfirm) {
        $errors[] = 'Konfirmasi kata sandi tidak cocok.';
    }

    if (!$errors) {
        try {
            $check = $pdo->prepare('SELECT 1 FROM admin WHERE username = :username OR email = :email LIMIT 1');
            $check->execute(['username' => $values['username'], 'email' => $values['email']]);
            if ($check->fetchColumn()) {
                $errors[] = 'Username atau email sudah terdaftar sebagai admin.';
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO admin (username, email, password_hash, full_name)
                     VALUES (:username, :email, :password_hash, :full_name)'
                );
                $insert->execute([
                    'username' => $values['username'],
                    'email' => $values['email'],
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $values['full_name'],
                ]);
                $created = true;
                $values = ['full_name' => '', 'username' => '', 'email' => ''];
            }
        } catch (PDOException $e) {
            error_log('Admin registration error: ' . $e->getMessage());
            $errors[] = $e->getCode() === '23505'
                ? 'Username atau email sudah terdaftar sebagai admin.'
                : 'Pendaftaran admin gagal diproses. Silakan coba lagi.';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<main class="mx-auto max-w-3xl p-6 lg:p-10">
  <div class="mb-8">
    <p class="text-sm font-semibold text-[#0d3b36]">MANAJEMEN ADMIN</p>
    <h1 class="mt-2 text-3xl font-extrabold">Daftarkan Admin</h1>
    <p class="mt-2 text-sm text-slate-600">Buat akun admin baru untuk mengakses portal manajemen.</p>
  </div>

  <section class="rounded-2xl bg-white p-6 shadow-sm md:p-8">
    <?php if ($created): ?>
      <p role="status" class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">Admin berhasil didaftarkan.</p>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <ul class="list-inside list-disc space-y-1"><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form method="post" action="register.php" class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <div>
        <label for="full_name" class="mb-2 block text-sm font-semibold">Nama Lengkap</label>
        <input id="full_name" name="full_name" type="text" maxlength="150" autocomplete="name" required value="<?= $escape($values['full_name']) ?>" class="w-full rounded-xl border border-slate-200 bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:border-[#0d3b36] focus:ring-2 focus:ring-[#0d3b36]/20">
      </div>
      <div class="grid gap-5 md:grid-cols-2">
        <div>
          <label for="username" class="mb-2 block text-sm font-semibold">Username</label>
          <input id="username" name="username" type="text" maxlength="50" autocomplete="username" required value="<?= $escape($values['username']) ?>" class="w-full rounded-xl border border-slate-200 bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:border-[#0d3b36] focus:ring-2 focus:ring-[#0d3b36]/20">
        </div>
        <div>
          <label for="email" class="mb-2 block text-sm font-semibold">Email</label>
          <input id="email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?= $escape($values['email']) ?>" class="w-full rounded-xl border border-slate-200 bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:border-[#0d3b36] focus:ring-2 focus:ring-[#0d3b36]/20">
        </div>
      </div>
      <div class="grid gap-5 md:grid-cols-2">
        <div>
          <label for="password" class="mb-2 block text-sm font-semibold">Kata Sandi</label>
          <input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required class="w-full rounded-xl border border-slate-200 bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:border-[#0d3b36] focus:ring-2 focus:ring-[#0d3b36]/20">
          <p class="mt-1 text-xs text-slate-500">12–72 karakter</p>
        </div>
        <div>
          <label for="password_confirm" class="mb-2 block text-sm font-semibold">Konfirmasi Kata Sandi</label>
          <input id="password_confirm" name="password_confirm" type="password" minlength="12" maxlength="72" autocomplete="new-password" required class="w-full rounded-xl border border-slate-200 bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:border-[#0d3b36] focus:ring-2 focus:ring-[#0d3b36]/20">
        </div>
      </div>
      <button type="submit" class="w-full rounded-xl bg-[#0d3b36] px-5 py-3 font-bold text-white transition hover:bg-[#082724] md:w-auto">Daftarkan Admin</button>
    </form>
  </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
