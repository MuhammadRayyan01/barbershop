  <footer class="mx-auto mt-auto flex w-full max-w-6xl flex-col justify-between gap-2 border-t border-gray-200/70 py-5 text-xs text-gray-400 sm:flex-row">
    <span>&copy; <?= date('Y') ?> Pemon Barbershop. All rights reserved.</span>
    <span>Waktu Indonesia Barat (WIB)</span>
  </footer>

  <!-- Page-specific JavaScript (set $userPageScripts in the page). Shared account JS is loaded by header.php. -->
  <?php foreach (($userPageScripts ?? []) as $pageScript): ?>
    <script src="<?= htmlspecialchars((string) $pageScript, ENT_QUOTES, 'UTF-8') ?>" defer></script>
  <?php endforeach; ?>
</body>
</html>