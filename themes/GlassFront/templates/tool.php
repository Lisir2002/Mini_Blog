<?php
/**
 * tool 布局 —— 工具页薄壳。变量同 site：$title / $themeName / $blocks / $state / $esc。
 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main>
  <header class="glass-card front-hero">
    <p class="glass-eyebrow">工具 · <?= $esc($family) ?> 材质</p>
    <h1><?= $esc($title) ?></h1>
  </header>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
