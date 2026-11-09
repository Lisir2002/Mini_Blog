<?php
/** admin 布局 —— 后台控制台。静态骨架见 partials/dashboard.php，数据由 admin.js 取既有接口。 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main class="glass-admin" data-theme="<?= $esc($themeName) ?>">
<?php include __DIR__ . '/partials/dashboard.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
