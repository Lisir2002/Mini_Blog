<?php
/** admin 布局 —— 后台外观完全由主题定义。变量同 site，另需 $apiPrefix / $js */
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
<body data-api="<?= $esc($apiPrefix) ?>" data-layout="admin">
<header class="bar">
  <strong><?= $esc($title) ?></strong>
  <span class="hint">布局 / 样式 / 脚本由主题包提供 · 页面只产数据</span>
</header>
<main>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<script>
<?= $js ?>
</script>
</body>
</html>
