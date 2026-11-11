<?php
/**
 * admin 布局 —— 后端控制台 / 统计看板 / 审核队列 / 内容管理共用槽位。
 * 常规后台走 dashboard.php 的 JS 仪表盘；服务端渲染的视图（stats / queue / content）
 * 改走通用区块，这样零脚本也能读、也能提交（审核与内容操作都是纯表单）。
 * 零新增布局槽位：所有呈现都由同一个 admin 槽位按页面 view 分流。
 */
?>
<?php $serverView = in_array((string) ($view ?? ''), ['stats', 'queue', 'content', 'write', 'users', 'roles', 'decisions', 'systems', 'logs', 'member'], true); ?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main class="glass-admin" data-theme="<?= $esc($themeName) ?>" data-view="<?= $esc($view ?? '') ?>">
<?php if ($serverView): ?>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
<?php else: ?>
<?php include __DIR__ . '/partials/dashboard.php'; ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
