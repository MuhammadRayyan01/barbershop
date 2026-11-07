<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$csrfToken = getCsrfToken();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pageError = '';
$formData = ['service_name' => '', 'price' => '', 'description' => '', 'status' => 'active'];

if ($requestMethod === 'POST') {
    $formData = [
        'service_name' => trim((string) ($_POST['service_name'] ?? '')),
        'price' => preg_replace('/\D+/', '', (string) ($_POST['price'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'status' => $_POST['status'] ?? 'active',
    ];
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');

    if ($formData['service_name'] === '' || strlen($formData['service_name']) > 255 || $formData['price'] === '' || (float) $formData['price'] > 99999999.99 || strlen($formData['description']) > 10000 || !in_array($formData['status'], ['active', 'inactive'], true)) {
        $pageError = 'Periksa kembali nama, harga, deskripsi, dan status layanan.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO service (service_name, description, price, status) VALUES (:name, :description, :price, :status)');
            $stmt->execute([
                'name' => $formData['service_name'],
                'description' => $formData['description'] === '' ? null : $formData['description'],
                'price' => $formData['price'],
                'status' => $formData['status'],
            ]);
            $_SESSION['admin_flash'] = 'Layanan baru berhasil ditambahkan.';
            header('Location: services.php');
            exit;
        } catch (PDOException $e) {
            error_log('Admin service creation error: ' . $e->getMessage());
            $pageError = 'Layanan gagal disimpan ke database.';
        }
    }
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$adminPageTitle = 'Add Service | Pemon Admin';
$adminPageScript = '../assets/js/admin-add-service.js';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <h1 class="text-2xl sm:text-3xl font-extrabold">Tambah Layanan Baru</h1>
    <p class="text-sm text-slate-500 mt-1">Tambahkan layanan ke daftar yang tersimpan di database.</p>

    <form id="add-form" method="post" action="add-service.php" class="bg-white rounded-2xl shadow-sm p-6 sm:p-8 mt-6 max-w-2xl space-y-5" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <div>
        <div class="flex justify-between mb-2"><label for="f-name" class="text-sm font-semibold">Service Name</label><span class="text-[11px] text-slate-400">Wajib diisi</span></div>
        <div class="flex items-center gap-3 bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
          <span class="material-symbols-outlined text-[20px] text-slate-500">content_cut</span>
          <input id="f-name" name="service_name" type="text" value="<?= $escape($formData['service_name']) ?>" placeholder="Contoh: Hair Spa" class="w-full bg-transparent outline-none text-sm">
        </div>
      </div>

      <div>
        <div class="flex justify-between mb-2"><label for="f-price" class="text-sm font-semibold">Price</label><span class="text-[11px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">Rupiah (IDR)</span></div>
        <div class="flex items-center gap-3 bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
          <span class="material-symbols-outlined text-[20px] text-slate-500">payments</span>
          <span class="text-sm text-slate-500">Rp</span>
          <input id="f-price" name="price" type="text" value="<?= $escape($formData['price']) ?>" inputmode="numeric" placeholder="0" class="w-full bg-transparent outline-none text-sm">
        </div>
      </div>

      <div>
        <label for="f-desc" class="text-sm font-semibold block mb-2">Deskripsi Singkat</label>
        <textarea id="f-desc" name="description" rows="3" placeholder="Jelaskan isi layanan..." class="w-full bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 outline-none text-sm focus:ring-2 focus:ring-[#0d3b36]/40"><?= $escape($formData['description']) ?></textarea>
      </div>

      <div>
        <label for="f-status" class="text-sm font-semibold block mb-2">Status</label>
        <select id="f-status" name="status" class="w-full bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 outline-none text-sm">
          <option value="active" <?= $formData['status'] === 'active' ? 'selected' : '' ?>>Active (tampil di booking)</option>
          <option value="inactive" <?= $formData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive (disembunyikan)</option>
        </select>
      </div>

      <?php if ($pageError !== ''): ?><p class="text-sm text-red-600 bg-red-50 rounded-lg px-4 py-2"><?= $escape($pageError) ?></p><?php endif; ?>
      <p id="f-error" class="hidden text-sm text-red-600 bg-red-50 rounded-lg px-4 py-2"></p>

      <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
        <a href="services.php" class="px-5 py-2.5 mt-4 rounded-xl bg-slate-100 text-sm font-semibold text-slate-600 hover:bg-slate-200">Batal</a>
        <button type="submit" class="px-5 py-2.5 mt-4 rounded-xl bg-[#0d3b36] hover:bg-[#082724] text-white text-sm font-bold">Simpan Layanan</button>
      </div>
    </form>
  </main>

<?php require __DIR__ . '/includes/footer.php'; ?>
