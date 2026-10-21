<?php $pageTitle = 'Akses ditolak'; include BASE_PATH . '/views/header.php'; ?>
<div class="card empty-state">
  <h2>Akses ditolak</h2>
  <p class="muted">Anda tidak memiliki hak akses ke halaman ini.</p>
  <a class="btn" href="<?= e(url('dashboard')) ?>">Kembali ke Dashboard</a>
</div>
<?php include BASE_PATH . '/views/footer.php'; ?>
