<?php // ส่วนท้ายชุดเดียวกับแอปผลิต (shared/ui_footer.php) — สองแอปใช้ session เดียวกัน โทเคน csrf จึงใช้ร่วมกันได้
      require_once dirname(__DIR__, 2) . '/shared/ui_footer.php';
      if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
      echo ui_app_footer_html(ui_finishgoogs_base_url(), function_exists('app_release_version') ? (string) app_release_version() : '', (string) $_SESSION['csrf']); ?>
    </main>
</div>
<script src="<?= e(ui_finishgoogs_base_url()) ?>/assets/sidebar.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/finishgoogs_ma_update/assets/sidebar.js') ?: time() ?>"></script>
<script src="<?= url('/assets/app.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?: 3 ?>"></script>
<script src="<?= e(ui_finishgoogs_base_url()) ?>/assets/app-footer.js?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/finishgoogs_ma_update/assets/app-footer.js') ?>"></script>
</body>
</html>
