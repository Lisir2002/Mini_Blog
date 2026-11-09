<?php
/**
 * site 布局 —— 前端门户首页 / 普通独立页。
 * 变量：$title / $themeName / $family / $items / $blocks / $state / $esc。
 * 首页文章流先由服务端用 $items 直出一个可读的初始列表，front.js 再渐进增强为接口数据；
 * 无脚本、接口不可用时页面依然可读（渐进增强，不产生空白门户）。
 * 非首页（如友链页）复用 site 布局但不输出门户容器，脚本据此不发请求。
 */
$isHome = ($title ?? '') !== '友情链接';
?>
<?php include __DIR__ . '/partials/head.php'; ?>

<?php if ($isHome): ?>
  <header class="glass-card front-hero">
    <p class="glass-eyebrow"><?= $esc($family) ?> 材质 · 前端元主题</p>
    <h1><?= $esc($title) ?></h1>
    <p class="glass-muted">半透明磨砂玻璃质感的访客门户。文章、概览与状态全部来自既有系统层，
      主题只负责呈现，不持有任何业务数据。</p>
    <div class="front-hero__actions">
      <a class="state__action" href="#front-articles">浏览文章</a>
      <a class="ghost" href="/links">友情链接</a>
    </div>
  </header>

  <section class="front-stats" id="front-stats" aria-label="站点概览">
    <div class="glass-card front-stat">
      <p class="front-stat__label">文章 / 系统 / 接口 / 表情</p>
      <p class="front-stat__value">—</p>
      <p class="front-stat__note">由公开接口载入</p>
    </div>
  </section>

  <section class="glass-card" id="front-articles">
    <div class="front-section__head">
      <h2>文章</h2>
      <span class="glass-faint front-section__hint" id="front-posts-count">服务端直出</span>
    </div>
    <div class="front-posts" id="front-posts">
<?php foreach ($items as $item): ?>
      <article class="glass-card front-post">
        <h3 class="front-post__title"><?= $esc((string) $item) ?></h3>
        <p class="front-post__excerpt">来自结构化数据的初始内容，接口可用时由门户脚本增强。</p>
      </article>
<?php endforeach; ?>
<?php if ($items === []): ?>
      <p class="front-empty" id="front-empty">暂无内容。</p>
<?php endif; ?>
    </div>
  </section>
<?php else: ?>
  <header class="glass-card front-hero">
    <p class="glass-eyebrow"><?= $esc($family) ?> 材质</p>
    <h1><?= $esc($title) ?></h1>
  </header>
  <section class="glass-card">
    <ul class="glass-list">
<?php foreach ($items as $item): ?>
      <li><?= $esc((string) $item) ?></li>
<?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
<?php include __DIR__ . '/partials/foot.php'; ?>
