    </main>
</div>
<?php $appVer = function_exists('app_release_version') ? app_release_version() : ''; ?>
<?php if ($appVer !== '') { ?>
<?php // ป้ายเดียวกับแอปผลิต ใช้สไตล์จาก style.css ของแอปนั้นซึ่งโหลดร่วมกันอยู่แล้ว ?>
<p class="app-release-ver" title="รหัสชุด deploy — ใช้เทียบว่าเซิร์ฟเวอร์ได้ไฟล์ล่าสุดแล้วหรือยัง">Version <?= e($appVer) ?></p>
<?php } ?>
<script src="<?= e(ui_finishgoogs_base_url()) ?>/assets/sidebar.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/finishgoogs_ma_update/assets/sidebar.js') ?: time() ?>"></script>
<script src="<?= url('/assets/app.js') ?>?v=<?= @filemtime(__DIR__ . '/../assets/app.js') ?: 3 ?>"></script>
</body>
</html>
