<?php
/**
 * 状态反馈片段 —— 由主题渲染，核心只提供结构化数据。
 * 五态共用一个骨架：role / aria-live 随相位切换，进行态额外标记 aria-busy。
 * 变量：$state(array)、$esc(callable)
 */
$s = is_array($state ?? null) ? $state : [];
if ($s === []) {
    return;
}
$phase    = (string) ($s['phase'] ?? 'done');
$failed   = $phase === 'failed';
$busy     = in_array($phase, ['pending', 'running'], true);
$progress = is_array($s['progress'] ?? null) ? $s['progress'] : null;
$actions  = (array) ($s['actions'] ?? []);
$evidence = (array) ($s['evidence'] ?? []);
?>
<div class="state state--<?= $esc($phase) ?>"
     role="<?= $failed ? 'alert' : 'status' ?>"
     aria-live="<?= $failed ? 'assertive' : 'polite' ?>"
     data-state-phase="<?= $esc($phase) ?>"<?= $busy ? ' aria-busy="true"' : '' ?>>
  <span class="state__mark" aria-hidden="true"></span>
  <div class="state__body">
    <p class="state__title"><?= $esc((string) ($s['title'] ?? '')) ?></p>
<?php if (($s['message'] ?? '') !== ''): ?>
    <p class="state__message"><?= $esc((string) $s['message']) ?></p>
<?php endif; ?>
<?php if ($progress !== null): ?>
<?php   $total = (int) ($progress['total'] ?? 0); $done = (int) ($progress['done'] ?? 0); ?>
<?php   $pct = $total > 0 ? (int) round($done / $total * 100) : 0; ?>
    <div class="state__progress">
      <progress value="<?= $done ?>" max="<?= $total ?>"><?= $pct ?>%</progress>
      <span><?= $esc((string) ($progress['label'] ?? '')) ?> <?= $done ?>/<?= $total ?><?= $esc((string) ($progress['unit'] ?? '')) ?></span>
    </div>
<?php endif; ?>
<?php if (($s['hint'] ?? '') !== ''): ?>
    <p class="state__hint"><?= $esc((string) $s['hint']) ?></p>
<?php endif; ?>
<?php if ($actions !== []): ?>
    <div class="state__actions">
<?php foreach ($actions as $action): ?>
<?php   $href = (string) ($action['href'] ?? ''); ?>
<?php   $method = strtoupper((string) ($action['method'] ?? 'GET')); ?>
<?php   $kind = (string) ($action['kind'] ?? 'secondary'); ?>
<?php   $label = (string) ($action['label'] ?? ''); ?>
<?php   if ($method === 'GET' && $href !== ''): ?>
      <a class="state__action state__action--<?= $esc($kind) ?>" href="<?= $esc($href) ?>"><?= $esc($label) ?></a>
<?php   elseif ($href !== ''): ?>
      <form class="state__form" method="post" action="<?= $esc($href) ?>">
        <button class="state__action state__action--<?= $esc($kind) ?>" type="submit"
                name="state_action" value="<?= $esc((string) ($action['key'] ?? '')) ?>"><?= $esc($label) ?></button>
      </form>
<?php   else: ?>
      <button class="state__action state__action--<?= $esc($kind) ?>" type="button"
              data-state-action="<?= $esc((string) ($action['key'] ?? '')) ?>"><?= $esc($label) ?></button>
<?php   endif; ?>
<?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($failed && ($evidence['trace_id'] ?? '') !== ''): ?>
    <details class="state__evidence">
      <summary>追踪信息</summary>
      <p>编号 <code><?= $esc((string) $evidence['trace_id']) ?></code><?php if (($evidence['at'] ?? '') !== ''): ?>
        · <time datetime="<?= $esc((string) $evidence['at']) ?>"><?= $esc((string) $evidence['at']) ?></time>
      <?php endif; ?></p>
    </details>
<?php endif; ?>
  </div>
</div>
