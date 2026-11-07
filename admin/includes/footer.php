<footer class="lg:ml-72 px-4 sm:px-8 py-5 text-center text-xs text-slate-400">
  &copy; <?= date('Y') ?> Pemon Barbershop &middot; Administration Portal
</footer>
<script src="../assets/js/admin.js"></script>
<?php if (!empty($adminPageScript)): ?>
  <script src="<?= htmlspecialchars($adminPageScript, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
</body>
</html>
