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
