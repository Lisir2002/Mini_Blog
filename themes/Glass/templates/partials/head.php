<?php
/**
 * 共享文档头 —— 变量：$title / $themeName / $layout / $apiPrefix / $css / $esc
 * 背景光晕是纯 CSS 径向渐变，不引用任何图片或外部资源（零依赖）。
 */
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title><?= $esc($title) ?> · <?= $esc($themeName) ?></title>
<link rel="alternate" type="application/rss+xml" title="RSS" href="/feed">
<style>
<?= $css ?>
</style>
</head>
<body data-layout="<?= $esc($layout) ?>" data-api="<?= $esc($apiPrefix) ?>">
<div class="glass-aurora" aria-hidden="true"><span></span><span></span><span></span></div>
