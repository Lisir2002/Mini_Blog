<?php
/** tool 布局 —— 工具页外观与交互由主题定义。 */
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
<body data-layout="tool">
<main class="wx-shell">
  <h1><?= $esc($title) ?></h1>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
<script>
<?= $js ?>
</script>
</body>
</html>
