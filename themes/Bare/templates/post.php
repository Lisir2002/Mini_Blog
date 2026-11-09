<?php
/**
 * post 布局 —— 文章正文 + 评论树。原生 PHP 模板，无模板引擎。
 * 可用变量：$title / $post / $comments / $csrf / $state / $css / $js / $esc
 */
$post = is_array($post ?? null) ? $post : null;
$comments = is_array($comments ?? null) ? $comments : ['total' => 0, 'items' => [], 'tree' => []];
$csrf = (string) ($csrf ?? '');
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $esc($title) ?> · <?= $esc('Mini Blog') ?></title>
<style>
<?= $css ?>
</style>
</head>
<body>
<main class="wx-shell wx-post">
<?php if ($post === null): ?>
  <h1>文章不存在</h1>
  <p>这篇内容可能已删除，或链接有误。<a href="/">回到首页</a></p>
<?php else: ?>
  <p class="wx-post__back"><a href="/">← 返回首页</a></p>
  <article class="wx-post__article">
    <h1><?= $esc((string) ($post['title'] ?? '')) ?></h1>
    <p class="wx-post__meta">
      <span><?= $esc((string) ($post['author'] ?? '匿名')) ?></span>
      <time datetime="<?= $esc((string) ($post['created_at'] ?? '')) ?>"><?= $esc((string) ($post['created_at'] ?? '')) ?></time>
    </p>
    <?php $tokens = (array) ($post['body_tokens'] ?? []); ?>
    <div class="wx-post__body"><?php include __DIR__ . '/partials/emoji.php'; ?></div>
  </article>
  <?php include __DIR__ . '/partials/state.php'; ?>
  <?php include __DIR__ . '/partials/comment.php'; ?>
<?php endif; ?>
</main>
<?php if (($js ?? '') !== ''): ?>
<script>
<?= $js ?>
</script>
<?php endif; ?>
</body>
</html>
