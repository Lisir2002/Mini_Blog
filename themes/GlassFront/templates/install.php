<?php
/**
 * install 布局 —— 安装向导薄壳，表单与进度由页面数据驱动。
 * 变量同 site：$title / $themeName / $blocks / $state / $esc。
 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main>
  <header class="glass-card front-hero">
    <p class="glass-eyebrow">安装向导 · <?= $esc($themeName) ?></p>
    <h1><?= $esc($title) ?></h1>
  </header>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
