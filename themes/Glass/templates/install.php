<?php
/** install 布局 —— 五步安装向导。变量同 site，表单与进度由页面数据驱动。 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main class="glass-shell" data-theme="<?= $esc($themeName) ?>">
  <header class="glass-card glass-card--hero">
    <p class="glass-eyebrow"><?= $esc($themeName) ?></p>
    <h1><?= $esc($title) ?></h1>
  </header>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
