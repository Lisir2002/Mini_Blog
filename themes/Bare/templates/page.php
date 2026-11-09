<?php
/**
 * site 布局 —— 原生 PHP 模板，无模板引擎。
 * 可用变量：$title / $items / $blocks / $css / $esc / $apiPrefix
 * 所有动态内容必须经 $esc() 输出；标签与样式 100% 属于本主题包。
 */
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $esc($title) ?></title>
<style>
<?= $css ?>
</style>
</head>
<body>
<main class="wx-shell">
  <h1><?= $esc($title) ?></h1>
<?php if ($blocks !== []): ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
<?php else: ?>
  <ul>
<?php foreach ($items as $item): ?>
    <li><?= $esc((string) $item) ?></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</main>
</body>
</html>
