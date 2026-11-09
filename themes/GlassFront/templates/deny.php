<?php
/**
 * deny 布局 —— 访问被拒绝页。外观同样归主题，页面只产数据。
 * 变量：$title / $themeName / $blocks / $state / $esc。
 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main>
  <section class="glass-card front-hero">
    <p class="glass-eyebrow">403 · <?= $esc($themeName) ?></p>
    <h1><?= $esc($title) ?></h1>
    <p class="glass-muted">需要相应身份才能访问。<a href="/">回到门户首页</a></p>
  </section>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
