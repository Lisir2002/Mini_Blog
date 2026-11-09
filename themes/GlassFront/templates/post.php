<?php
/**
 * post 布局 —— 文章正文 + 评论树 + 表情。原生 PHP 模板，无 JavaScript 也能评论。
 * 变量：$title / $post / $comments / $csrf / $state / $esc。
 */
$post = is_array($post ?? null) ? $post : null;
$comments = is_array($comments ?? null) ? $comments : ['total' => 0, 'items' => [], 'tree' => []];
$csrf = (string) ($csrf ?? '');
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main>
  <p class="glass-back"><a href="/">← 返回门户首页</a></p>
<?php if ($post === null): ?>
  <section class="glass-card front-article">
    <h1>文章不存在</h1>
    <p class="glass-muted">这篇内容可能已删除，或链接有误。<a href="/">回到首页</a></p>
  </section>
<?php else: ?>
  <article class="glass-card front-article">
    <h1><?= $esc((string) ($post['title'] ?? '')) ?></h1>
    <p class="glass-meta">
      <span><?= $esc((string) ($post['author'] ?? '匿名')) ?></span>
      <time datetime="<?= $esc((string) ($post['created_at'] ?? '')) ?>"><?= $esc((string) ($post['created_at'] ?? '')) ?></time>
    </p>
    <div class="front-article__divider"></div>
    <?php $tokens = (array) ($post['body_tokens'] ?? []); ?>
    <div class="glass-body"><?php include __DIR__ . '/partials/emoji.php'; ?></div>
  </article>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/comment.php'; ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
