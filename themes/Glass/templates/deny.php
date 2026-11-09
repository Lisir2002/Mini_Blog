<?php
/** deny 布局 —— 访问被拒绝页。外观同样归主题，页面只产数据。 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main class="glass-shell glass-shell--narrow" data-theme="<?= $esc($themeName) ?>">
  <section class="glass-card glass-card--hero">
    <p class="glass-eyebrow">403 · <?= $esc($themeName) ?></p>
    <h1><?= $esc($title) ?></h1>
  </section>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
