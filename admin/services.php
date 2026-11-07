<?php
require_once __DIR__ . '/../backend/middleware/require_admin_page.php';
require_once __DIR__ . '/../backend/config/csrf.php';

$csrfToken = getCsrfToken();
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pageError = '';
$editData = ['service_id' => '', 'service_name' => '', 'price' => '', 'description' => ''];
$reopenEdit = false;
$flashMessage = $_SESSION['admin_flash'] ?? '';
unset($_SESSION['admin_flash']);

if ($requestMethod === 'POST') {
    requireValidCsrfToken(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'deactivate') {
            $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$serviceId || $serviceId < 1) {
                throw new InvalidArgumentException('ID layanan tidak valid.');
            }
            $stmt = $pdo->prepare("UPDATE service SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE service_id = :id AND status = 'active'");
            $stmt->execute(['id' => $serviceId]);
            if ($stmt->rowCount() === 0) throw new InvalidArgumentException('Layanan aktif tidak ditemukan.');
            $_SESSION['admin_flash'] = 'Layanan berhasil dinonaktifkan.';
            header('Location: services.php');
            exit;
        }

        if ($action !== 'update') throw new InvalidArgumentException('Aksi tidak valid.');
        $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string) ($_POST['service_name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $priceText = preg_replace('/\D+/', '', (string) ($_POST['price'] ?? ''));
        $editData = ['service_id' => $serviceId ?: '', 'service_name' => $name, 'price' => $priceText, 'description' => $description];
        if (!$serviceId || $serviceId < 1 || $name === '' || strlen($name) > 255 || $priceText === '' || (float) $priceText > 99999999.99 || strlen($description) > 10000) {
            throw new InvalidArgumentException('Periksa kembali nama, harga, dan deskripsi layanan.');
        }

        $stmt = $pdo->prepare("UPDATE service SET service_name = :name, description = :description, price = :price, updated_at = CURRENT_TIMESTAMP WHERE service_id = :id AND status = 'active' RETURNING service_id");
        $stmt->execute([
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'price' => $priceText,
            'id' => $serviceId,
        ]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('Layanan aktif tidak ditemukan.');
        $_SESSION['admin_flash'] = 'Layanan berhasil diperbarui.';
        header('Location: services.php');
        exit;
    } catch (InvalidArgumentException $e) {
        $pageError = $e->getMessage();
        $reopenEdit = $action === 'update';
    } catch (PDOException $e) {
        error_log('Admin service form error: ' . $e->getMessage());
        $pageError = 'Perubahan layanan gagal disimpan.';
        $reopenEdit = $action === 'update';
    }
}

try {
    $stmt = $pdo->query("SELECT service_id, service_name, description, price FROM service WHERE status = 'active' ORDER BY service_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Admin service list page error: ' . $e->getMessage());
    $services = [];
    $pageError = 'Daftar layanan gagal dimuat.';
}

$escape = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$adminPageTitle = 'Services | Pemon Admin';
$adminPageScript = '../assets/js/admin-services.js';
?>
<?php require __DIR__ . '/includes/header.php'; ?>

  <main class="lg:ml-72 px-4 sm:px-8 py-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div class="max-w-xl">
        <h1 class="text-2xl sm:text-3xl font-extrabold">Kelola &amp; Perbarui Layanan</h1>
        <p class="text-sm text-slate-500 mt-1">Atur tarif dan deskripsi layanan untuk reservasi.</p>
      </div>
      <a href="add-service.php" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-[#0d3b36] hover:bg-[#082724] text-white text-sm font-bold">
        <span class="material-symbols-outlined text-[18px]">add</span>Tambah Layanan Baru
      </a>
    </div>

    <div class="flex flex-wrap items-center gap-3 mt-6">
      <div class="flex items-center gap-2 bg-white rounded-xl px-4 py-3 flex-1 min-w-[260px] shadow-sm">
        <span class="material-symbols-outlined text-[20px] text-slate-500">search</span>
        <input id="search" type="text" placeholder="Search by name (Cari nama layanan pangkas rambut, cuci, styling...)" class="w-full bg-transparent outline-none text-sm">
        <button id="clear-search" class="hidden text-slate-400 hover:text-slate-700" aria-label="Hapus"><span class="material-symbols-outlined text-[18px]">close</span></button>
      </div>
      <span id="total-chip" class="px-3 py-2 rounded-lg bg-indigo-50 text-xs font-semibold text-slate-700">Total: <?= count($services) ?> Layanan Aktif</span>
      <span class="inline-flex items-center gap-2 px-3 py-2 rounded-lg bg-emerald-100 text-xs font-semibold text-emerald-900">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-700"></span>Data dari database
      </span>
    </div>
    <?php if ($pageError !== ''): ?><p class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"><?= $escape($pageError) ?></p><?php endif; ?>
    <?php if ($flashMessage !== ''): ?><p class="mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= $escape($flashMessage) ?></p><?php endif; ?>

    <section class="bg-white rounded-2xl shadow-sm mt-5 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[760px]">
          <thead class="bg-indigo-50/60 text-[11px] tracking-wider text-slate-500 uppercase">
            <tr>
              <th class="text-left font-semibold px-4 py-3 w-14">#</th>
              <th class="text-left font-semibold px-4 py-3">Service Name</th>
              <th class="text-left font-semibold px-4 py-3">Description</th>
              <th class="text-right font-semibold px-4 py-3">Price</th>
              <th class="text-right font-semibold px-4 py-3">Action</th>
            </tr>
          </thead>
          <tbody id="svc-body" class="divide-y divide-slate-100">
            <?php if ($services === []): ?>
              <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">Belum ada layanan aktif.</td></tr>
            <?php else: foreach ($services as $service): ?>
              <tr class="service-row hover:bg-slate-50" data-name="<?= $escape($service['service_name']) ?>" data-description="<?= $escape($service['description'] ?? '') ?>">
                <td class="px-4 py-5 text-slate-400"><?= str_pad((string) $service['service_id'], 2, '0', STR_PAD_LEFT) ?></td>
                <td class="px-4 py-5 font-semibold w-52 service-name"><?= $escape($service['service_name']) ?></td>
                <td class="px-4 py-5 text-xs text-slate-600 max-w-sm service-description"><?= $escape($service['description'] ?? '-') ?></td>
                <td class="px-4 py-5 text-right font-bold whitespace-nowrap service-price">Rp <?= number_format((float) $service['price'], 0, ',', '.') ?></td>
                <td class="px-4 py-5">
                  <div class="flex items-center justify-end gap-3">
                    <button type="button" data-act="edit" data-id="<?= (int) $service['service_id'] ?>" data-name="<?= $escape($service['service_name']) ?>" data-price="<?= $escape($service['price']) ?>" data-description="<?= $escape($service['description'] ?? '') ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-100 text-emerald-900 text-xs font-semibold hover:bg-emerald-200"><span class="material-symbols-outlined text-[16px]">edit</span>Update</button>
                    <button type="button" data-act="deactivate" data-id="<?= (int) $service['service_id'] ?>" data-name="<?= $escape($service['service_name']) ?>" class="p-1 text-slate-500 hover:text-red-600" aria-label="Arsipkan" title="Nonaktifkan layanan"><span class="material-symbols-outlined text-[20px]">inventory_2</span></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>

  <div id="update-modal" class="modal hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
    <form id="update-form" method="post" action="services.php" data-reopen="<?= $reopenEdit ? '1' : '0' ?>" class="bg-white rounded-2xl shadow-xl w-full max-w-lg overflow-hidden" novalidate>
      <div class="flex items-center justify-between px-6 py-4 bg-indigo-50/60">
        <h3 class="font-bold text-lg flex items-center gap-2"><span class="material-symbols-outlined">edit_note</span>Update Layanan</h3>
        <button type="button" data-close-modal class="p-1 rounded-lg hover:bg-white/70"><span class="material-symbols-outlined">close</span></button>
      </div>
      <div class="px-6 py-5 space-y-5">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" id="f-id" name="service_id" value="<?= $escape($editData['service_id']) ?>">
        <div>
          <div class="flex justify-between mb-2"><label for="f-name" class="text-sm font-semibold">Service Name</label><span class="text-[11px] text-slate-400">Wajib diisi</span></div>
          <div class="flex items-center gap-3 bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
            <span class="material-symbols-outlined text-[20px] text-slate-500">content_cut</span>
            <input id="f-name" name="service_name" type="text" value="<?= $escape($editData['service_name']) ?>" class="w-full bg-transparent outline-none text-sm">
          </div>
        </div>
        <div>
          <div class="flex justify-between mb-2"><label for="f-price" class="text-sm font-semibold">Price</label><span class="text-[11px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-800">Rupiah (IDR)</span></div>
          <div class="flex items-center gap-3 bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-[#0d3b36]/40">
            <span class="material-symbols-outlined text-[20px] text-slate-500">payments</span>
            <span class="text-sm text-slate-500">Rp</span>
            <input id="f-price" name="price" type="text" value="<?= $escape($editData['price']) ?>" inputmode="numeric" class="w-full bg-transparent outline-none text-sm">
          </div>
        </div>
        <div>
          <label for="f-desc" class="text-sm font-semibold block mb-2">Deskripsi Singkat</label>
          <textarea id="f-desc" name="description" rows="3" class="w-full bg-[#f8f8fd] border border-slate-100 rounded-xl px-4 py-3 outline-none text-sm focus:ring-2 focus:ring-[#0d3b36]/40"><?= $escape($editData['description']) ?></textarea>
        </div>
        <p id="f-error" class="hidden text-sm text-red-600 bg-red-50 rounded-lg px-4 py-2"></p>
      </div>
      <div class="px-6 py-4 border-t border-slate-100 flex justify-end gap-2">
        <button type="button" data-close-modal class="px-5 py-2.5 rounded-xl bg-slate-100 text-sm font-semibold text-slate-600 hover:bg-slate-200">Batal</button>
        <button type="submit" class="px-5 py-2.5 rounded-xl bg-[#0d3b36] hover:bg-[#082724] text-white text-sm font-bold">Update</button>
      </div>
    </form>
  </div>

  <form id="deactivate-form" method="post" action="services.php" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="action" value="deactivate">
    <input type="hidden" id="deactivate-id" name="service_id">
  </form>

<?php require __DIR__ . '/includes/footer.php'; ?>
