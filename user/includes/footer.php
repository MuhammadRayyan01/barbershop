  <?php if (in_array($userActivePage ?? "", ["dashboard", "schedule"], true)): ?>
    <a style="position:fixed;right:20px;bottom:20px;z-index:50;display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:9999px;background:#25D366;color:#fff;font-size:14px;font-weight:700;box-shadow:0 10px 25px rgba(0,0,0,.18)" href="https://wa.me/6282313128282" target="_blank" rel="noopener noreferrer" aria-label="Chat Pemon Barbershop di WhatsApp">
      <span aria-hidden="true" class="text-xl leading-none">✆</span><span>WhatsApp</span>
    </a>
  <?php endif; ?>
  <footer class="mx-auto mt-auto flex w-full max-w-6xl flex-col justify-between gap-2 border-t border-gray-200/70 py-5 text-xs text-gray-400 sm:flex-row">
    <span>&copy; <?= date('Y') ?> Pemon Barbershop. All rights reserved.</span>
    <span>Waktu Indonesia Barat (WIB)</span>
  </footer>
  <script>
    const accountButton = document.getElementById('akun-btn');
    const accountMenu = document.getElementById('akun-menu');

    accountButton?.addEventListener('click', event => {
      event.stopPropagation();
      const isOpen = !accountMenu.classList.contains('hidden');
      accountMenu.classList.toggle('hidden', isOpen);
      accountButton.setAttribute('aria-expanded', String(!isOpen));
    });

    document.addEventListener('click', event => {
      if (accountMenu && !accountMenu.contains(event.target) && !accountButton?.contains(event.target)) {
        accountMenu.classList.add('hidden');
        accountButton?.setAttribute('aria-expanded', 'false');
      }
    });
  </script>
  <?php foreach (($userPageScripts ?? []) as $script): ?>
    <script src="<?= htmlspecialchars($script, ENT_QUOTES, 'UTF-8') ?>" defer></script>
  <?php endforeach; ?>
</body>
</html>
