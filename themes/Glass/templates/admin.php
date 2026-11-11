<?php
/**
 * admin 布局 —— 后端控制台 / 统计看板共用槽位。
 * 常规后台走 dashboard.php 的 JS 仪表盘；view=stats 时改走服务端渲染的通用区块，
 * 这样统计看板零脚本也能读（采集器降级状态是文本证据，不该依赖 JS 才看得见）。
 * 零新增布局槽位：两处呈现都由同一个 admin 槽位按页面 view 分流。
 */
?>
<?php include __DIR__ . '/partials/head.php'; ?>
<main class="glass-admin" data-theme="<?= $esc($themeName) ?>" data-view="<?= $esc($view ?? '') ?>">
<?php if (($view ?? '') === 'stats'): ?>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php include __DIR__ . '/partials/blocks.php'; ?>
<?php else: ?>
<?php include __DIR__ . '/partials/dashboard.php'; ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/partials/foot.php'; ?>
