<?php
/**
 * admin 布局 —— 前端元主题下的后台页薄壳。
 * 门户元主题覆盖六槽位以求「初始化即可用」，但后台仪表盘是 Glass 后端元主题的职责；
 * 这里只用通用区块 / 状态协议呈现页面数据，不复制仪表盘逻辑（规矩 J：呈现归主题）。
 * 变量：$title / $themeName / $blocks / $state / $esc。
 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main data-view="<?= $esc($view ?? '') ?>">
  <header class="glass-card front-hero">
    <p class="glass-eyebrow">控制台 · <?= $esc($themeName) ?> 前端元主题</p>
    <h1><?= $esc($title) ?></h1>
    <p class="glass-muted">完整仪表盘见后端元主题 <code>glass</code>；此处为门户一致外观的通用呈现。
      统计聚合见 <a href="/console/stats">统计看板</a>（需管理员权限）。</p>
  </header>
<?php include __DIR__ . '/partials/admin-nav.php'; ?>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
