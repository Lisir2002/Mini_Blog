<?php
/**
 * 共享文档头 —— 变量：$title / $themeName / $layout / $surface / $family / $meta
 *   $apiPrefix / $css / $esc。
 * body 上的 data-* 是「元主题身份」的机器可验证标记，不参与呈现。
 * 背景光晕为纯 CSS 径向渐变，零图片、零外部资源。
 */
$badge = $meta ? '元主题 · 初始化基线' : '主题';
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title><?= $esc($title) ?> · <?= $esc($themeName) ?></title>
<style>
<?= $css ?>
</style>
</head>
<body data-layout="<?= $esc($layout) ?>" data-surface="<?= $esc($surface) ?>"
      data-family="<?= $esc($family) ?>" data-meta="<?= $meta ? '1' : '0' ?>"
      data-theme="<?= $esc($themeName) ?>" data-api="<?= $esc($apiPrefix) ?>">
<div class="glass-aurora" aria-hidden="true"><span></span><span></span><span></span></div>
<div class="front-shell">
  <nav class="glass-panel front-nav" aria-label="主导航">
    <span class="front-nav__brand"><i class="front-nav__dot" aria-hidden="true"></i><?= $esc($themeName) ?></span>
    <div class="front-nav__links">
      <a href="/"<?= $layout === 'site' ? ' class="is-active"' : '' ?>>首页</a>
      <a href="/post?slug=first-post">文章</a>
      <a href="/archive"<?= $layout === 'archive' ? ' class="is-active"' : '' ?>>归档</a>
      <a href="/terms">标签</a>
      <a href="/links">友链</a>
      <a href="/console">控制台</a>
    </div>
    <span class="front-nav__badge"><?= $esc($badge) ?></span>
  </nav>
