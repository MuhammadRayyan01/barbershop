<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$csrfToken = getCsrfToken();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pageError = '';
$additionalServiceError = '';
$additionalServiceData = ['service_id' => '', 'additional_service_name' => '', 'additional_service_price' => ''];
$activeServices = [];
$flashMessage = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);
$formData = ['service_name' => '', 'price' => '', 'description' => '', 'duration_minutes' => '30', 'status' => 'active'];

try {
    $servicesStmt = $pdo->query("SELECT service_id, service_name FROM service WHERE status = 'active' ORDER BY service_name");
    $activeServices = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin additional service options error: ' . $e->getMessage());
    $additionalServiceError = 'Daftar layanan utama gagal dimuat.';
}

if ($requestMethod === 'POST') {
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');

    if (($_POST['action'] ?? '') === 'add_additional_service') {
        $additionalServiceData = [
            'service_id' => trim((string) ($_POST['service_id'] ?? '')),
            'additional_service_name' => trim((string) ($_POST['additional_service_name'] ?? '')),
            'additional_service_price' => preg_replace('/\D+/', '', (string) ($_POST['additional_service_price'] ?? '')),
        ];
        $serviceId = filter_var($additionalServiceData['service_id'], FILTER_VALIDATE_INT);
        $activeServiceIds = array_map('intval', array_column($activeServices, 'service_id'));
        $serviceIsAvailable = $serviceId !== false && in_array($serviceId, $activeServiceIds, true);

        if (
            !$serviceIsAvailable ||
            $additionalServiceData['additional_service_name'] === '' ||
            strlen($additionalServiceData['additional_service_name']) > 255 ||
            $additionalServiceData['additional_service_price'] === '' ||
            (float) $additionalServiceData['additional_service_price'] > 99999999.99
        ) {
            $additionalServiceError = !$serviceIsAvailable
                ? 'Pilih layanan utama yang aktif untuk layanan tambahan ini.'
                : 'Periksa kembali nama dan harga layanan tambahan.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO additional_service (service_id, additional_service_name, additional_service_price) VALUES (:service_id, :name, :price)');
                $stmt->execute([
                    'service_id' => $serviceId,
                    'name' => $additionalServiceData['additional_service_name'],
                    'price' => $additionalServiceData['additional_service_price'],
                ]);
                $_SESSION['admin_flash'] = 'Layanan tambahan berhasil ditambahkan.';
                header('Location: add-service.php');
                exit;
            } catch (PDOException $e) {
                error_log('Admin additional service creation error: ' . $e->getMessage());
                $additionalServiceError = 'Layanan tambahan gagal disimpan ke database.';
            }
        }
    } else {
        $formData = [
            'service_name' => trim((string) ($_POST['service_name'] ?? '')),
            'price' => preg_replace('/\D+/', '', (string) ($_POST['price'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'duration_minutes' => trim((string) ($_POST['duration_minutes'] ?? '')),
            'status' => $_POST['status'] ?? 'active',
        ];

        $durationMinutes = filter_var($formData['duration_minutes'], FILTER_VALIDATE_INT);
        if ($formData['service_name'] === '' || strlen($formData['service_name']) > 255 || $formData['price'] === '' || (float) $formData['price'] > 99999999.99 || strlen($formData['description']) > 10000 || $durationMinutes === false || $durationMinutes < 15 || $durationMinutes > 240 || !in_array($formData['status'], ['active', 'inactive'], true)) {
            $pageError = 'Periksa kembali nama, harga, deskripsi, durasi (15–240 menit), dan status layanan.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO service (service_name, description, price, duration_minutes, status) VALUES (:name, :description, :price, :duration_minutes, :status)');
                $stmt->execute([
                    'name' => $formData['service_name'],
                    'description' => $formData['description'] === '' ? null : $formData['description'],
                    'price' => $formData['price'],
                    'duration_minutes' => $durationMinutes,
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
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$adminPageTitle = 'Add Service | Pemon Admin';
$adminPageScript = '../assets/js/admin-add-service.js';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <h1 class="text-2xl sm:text-3xl font-extrabold">Tambah Layanan Baru</h1>
    <p class="text-sm text-slate-500 mt-1">Tambahkan layanan ke daftar yang tersimpan di database.</p>
    <?php if ($flashMessage !== ''): ?><p class="mt-4 max-w-2xl rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= $escape($flashMessage) ?></p><?php endif; ?>

    <div class="mt-6 grid max-w-7xl items-start gap-6 xl:grid-cols-2">
    <form id="add-form" method="post" action="add-service.php" class="w-full rounded-2xl bg-white p-6 shadow-sm sm:p-8 space-y-5" novalidate>
      <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
      <input type="hidden" name="action" value="add_service">
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
        <div class="flex justify-between mb-2"><label for="f-duration" class="text-sm font-semibold">Durasi layanan</label><span class="text-[11px] text-slate-400">15–240 menit</span></div>
        <div class="flex items-center gap-3 bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
          <span class="material-symbols-outlined text-[20px] text-slate-500">schedule</span>
          <input id="f-duration" name="duration_minutes" type="number" min="15" max="240" step="5" required value="<?= $escape($formData['duration_minutes']) ?>" class="w-full bg-transparent outline-none text-sm">
          <span class="text-sm text-slate-500">menit</span>
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

    <section class="w-full rounded-2xl bg-white p-6 shadow-sm sm:p-8">
      <div class="mb-5">
        <h2 class="text-xl font-bold">Tambah Layanan Tambahan</h2>
        <p class="mt-1 text-sm text-slate-500">Layanan ini akan muncul sebagai pilihan tambahan saat pelanggan membuat reservasi.</p>
      </div>
      <form method="post" action="add-service.php" class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="action" value="add_additional_service">
        <div>
          <label for="additional-service-parent" class="mb-2 block text-sm font-semibold">Layanan utama</label>
          <select id="additional-service-parent" name="service_id" required class="w-full rounded-xl border border-slate-100 bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/40" <?= $activeServices === [] ? 'disabled' : '' ?>>
            <option value="">Pilih layanan utama</option>
            <?php foreach ($activeServices as $service): ?>
              <option value="<?= (int) $service['service_id'] ?>" <?= (string) $additionalServiceData['service_id'] === (string) $service['service_id'] ? 'selected' : '' ?>><?= $escape($service['service_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($activeServices === [] && $additionalServiceError === ''): ?><p class="mt-2 text-xs text-amber-700">Tambahkan layanan utama yang aktif terlebih dahulu.</p><?php endif; ?>
        </div>
        <div>
          <label for="additional-service-name" class="mb-2 block text-sm font-semibold">Nama layanan tambahan</label>
          <input id="additional-service-name" name="additional_service_name" type="text" maxlength="255" required value="<?= $escape($additionalServiceData['additional_service_name']) ?>" placeholder="Contoh: Hot Towel Add-on" class="w-full rounded-xl border border-slate-100 bg-[#f8f8fd] px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-[#0d3b36]/40">
        </div>
        <div>
          <div class="mb-2 flex items-center justify-between"><label for="additional-service-price" class="text-sm font-semibold">Harga</label><span class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] text-emerald-800">Rupiah (IDR)</span></div>
          <div class="flex items-center gap-3 rounded-xl border border-slate-100 bg-[#f8f8fd] px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
            <span class="text-sm text-slate-500">Rp</span>
            <input id="additional-service-price" name="additional_service_price" type="text" inputmode="numeric" required value="<?= $escape($additionalServiceData['additional_service_price']) ?>" placeholder="0" class="w-full bg-transparent text-sm outline-none">
          </div>
        </div>
        <?php if ($additionalServiceError !== ''): ?><p role="alert" class="rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700"><?= $escape($additionalServiceError) ?></p><?php endif; ?>
        <div class="flex justify-end border-t border-slate-100 pt-4">
          <button type="submit" class="rounded-xl bg-[#0d3b36] px-5 py-2.5 text-sm font-bold text-white hover:bg-[#082724]">Simpan Layanan Tambahan</button>
        </div>
      </form>
    </section>
    </div>
  </main>

<?php require __DIR__ . '/includes/footer.php'; ?>
