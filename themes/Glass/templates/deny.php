<?php
/**
 * deny 布局 —— 访问被拒绝 / 页面不存在共用槽位。外观归主题，页面只产数据。
 * 状态码来自 payload：401 需要登录、403 无权访问、404 没有这个页面 —— 不能一律写 403。
 * 变量：$title / $status / $themeName / $blocks / $state / $esc。
 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main class="glass-shell glass-shell--narrow" data-theme="<?= $esc($themeName) ?>">
  <section class="glass-card glass-card--hero">
    <p class="glass-eyebrow"><?= (int) ($status ?? 403) ?> · <?= $esc($themeName) ?></p>
    <h1><?= $esc($title) ?></h1>
  </section>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
