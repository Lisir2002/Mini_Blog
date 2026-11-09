<?php
/**
 * deny 布局 —— 访问被拒绝页的外观同样由主题定义。
 * 变量同 site：$title / $blocks / $css / $esc；页面只产数据，不含任何鉴权逻辑。
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
<body data-layout="deny">
<header class="bar">
  <strong><?= $esc($title) ?></strong>
  <span class="hint">拒绝页的布局 / 样式同样来自主题包 · 页面只产数据</span>
</header>
<main>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<?php if (($js ?? '') !== ''): ?>
<script>
<?= $js ?>
</script>
<?php endif; ?>
</body>
</html>
