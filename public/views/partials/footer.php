<!-- Footer -->
<footer class="mt-5 py-3">
  <div class="container">
    <div class="footer-info">
      <div class="row">
        <div class="col-md-6">
          <strong><?= APP_NAME ?> v<?= APP_VERSION ?></strong>
          <span class="text-muted">- Dashboard Chilemon administrado por Guillermo Ismodes - <a href="mailto:ca2iig@qsl.net">CA2IIG</a> en La Serena - Chile   </span>
        </div>
        <div class="col-md-6 text-md-end">
          <small>
            <span id="full-date"></span> |
            <a href="#" class="text-decoration-none" onclick="toggleTheme(); return false;">
              <i class="bi <?= $darkMode ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
              <?= $darkMode ? 'Tema claro' : 'Tema oscuro' ?>
            </a>
          </small>
        </div>
      </div>
    </div>
  </div>
</footer>

<!-- Botón flotante tema -->
<button class="theme-toggle-btn" onclick="toggleTheme()"
        title="<?= $darkMode ? 'Tema claro' : 'Tema oscuro' ?>">
  <i class="bi <?= $darkMode ? 'bi-sun' : 'bi-moon-stars' ?>"></i>
</button>