<?php
/** install 布局 —— 安装向导外观由主题定义，无脚本。 */
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
<body data-layout="install">
<main class="wx-shell">
  <h1><?= $esc($title) ?></h1>
<?php include __DIR__ . '/partials/blocks.php'; ?>
</main>
</body>
</html>
