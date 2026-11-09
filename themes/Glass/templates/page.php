<?php
/** site 布局 —— 首页与普通独立页。变量：$title / $items / $blocks / $state。 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main class="glass-shell">
  <header class="glass-card glass-card--hero">
    <p class="glass-eyebrow"><?= $esc($themeName) ?></p>
    <h1><?= $esc($title) ?></h1>
  </header>
  <section class="glass-card">
    <ul class="glass-list">
<?php foreach ($items as $item): ?>
      <li><?= $esc((string) $item) ?></li>
<?php endforeach; ?>
    </ul>
  </section>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
