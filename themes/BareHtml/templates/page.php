<?php
/**
 * 零样式原生 PHP 模板：仅使用语义标签，不含样式声明、类名与脚本。
 * 可用变量：$title(string)、$items(array)、$esc(callable)
 */
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $esc($title) ?></title>
</head>
<body>
<h1><?= $esc($title) ?></h1>
<ul>
<?php foreach ($items as $item): ?>
<li><?= $esc((string) $item) ?></li>
<?php endforeach; ?>
</ul>
</body>
</html>
