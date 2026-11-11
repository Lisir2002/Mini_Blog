<?php
/**
 * 通用区块协议渲染器 —— 页面只产结构化数据，标签结构由主题定义。
 * 变量：$blocks(list<array>)、$esc(callable)。
 * 支持 type：pre / text / list / table / form / notice / chart。
 *   notice  显式状态条：data-stats-state 让降级 / 空窗口 / 正常可被文本证据区分；
 *   chart   纯 CSS 柱状图：零脚本、零外部资源，柱高由页面算好的 ratio 提供。
 */
?>
<?php foreach ($blocks as $b): ?>
<section class="glass-card">
  <h2><?= $esc((string) ($b['title'] ?? '')) ?></h2>
<?php $type = (string) ($b['type'] ?? 'text'); ?>
<?php if ($type === 'pre'): ?>
  <pre class="glass-pre" id="<?= $esc((string) ($b['id'] ?? '')) ?>"><?= $esc((string) ($b['value'] ?? '')) ?></pre>
<?php elseif ($type === 'list'): ?>
  <ul class="glass-list" id="<?= $esc((string) ($b['id'] ?? '')) ?>">
<?php foreach ((array) ($b['items'] ?? []) as $it): ?>
    <li><?= $esc((string) $it) ?></li>
<?php endforeach; ?>
  </ul>
<?php elseif ($type === 'table'): ?>
  <table class="glass-table">
<?php foreach ((array) ($b['rows'] ?? []) as $row): ?>
    <tr>
      <th><?= $esc((string) ($row['k'] ?? '')) ?></th>
      <td class="<?= ($row['ok'] ?? false) ? 'is-ok' : 'is-warn' ?>"><?= $esc((string) ($row['v'] ?? '')) ?></td>
      <td class="glass-muted"><?= $esc((string) ($row['note'] ?? '')) ?></td>
    </tr>
<?php endforeach; ?>
  </table>
<?php elseif ($type === 'form'): ?>
  <div class="glass-row">
<?php foreach ((array) ($b['fields'] ?? []) as $f): ?>
<?php   $tag = (string) ($f['tag'] ?? 'input'); ?>
<?php   if ($tag === 'textarea'): ?>
    <textarea id="<?= $esc((string) ($f['id'] ?? '')) ?>" placeholder="<?= $esc((string) ($f['placeholder'] ?? '')) ?>"></textarea>
<?php   elseif ($tag === 'button'): ?>
    <button id="<?= $esc((string) ($f['id'] ?? '')) ?>" type="button"><?= $esc((string) ($f['label'] ?? '')) ?></button>
<?php   else: ?>
    <input id="<?= $esc((string) ($f['id'] ?? '')) ?>" type="<?= $esc((string) ($f['type'] ?? 'text')) ?>" placeholder="<?= $esc((string) ($f['placeholder'] ?? '')) ?>" autocomplete="off">
<?php   endif; ?>
<?php endforeach; ?>
  </div>
<?php elseif ($type === 'notice'): ?>
  <div class="glass-notice glass-notice--<?= $esc((string) ($b['tone'] ?? 'info')) ?>"
       id="<?= $esc((string) ($b['id'] ?? '')) ?>"
       data-stats-state="<?= $esc((string) ($b['state'] ?? '')) ?>" role="status">
    <p class="glass-notice__title"><?= $esc((string) ($b['title'] ?? '')) ?></p>
    <p class="glass-notice__value"><?= $esc((string) ($b['value'] ?? '')) ?></p>
  </div>
<?php elseif ($type === 'chart'): ?>
<?php   $pts = (array) ($b['points'] ?? []); ?>
  <div class="glass-chart" id="<?= $esc((string) ($b['id'] ?? '')) ?>"
       data-chart-points="<?= count($pts) ?>"
       data-chart-empty="<?= $pts === [] ? '1' : '0' ?>"
       data-chart-truncated="<?= !empty($b['truncated']) ? '1' : '0' ?>">
<?php   if ($pts === []): ?>
    <p class="glass-muted">窗口内没有采样点；此处刻意不画一根空柱，也不显示成「0 请求的正常看板」。</p>
<?php   else: ?>
    <div class="glass-chart__bars">
<?php     foreach ($pts as $p): ?>
<?php       if (!is_array($p)) continue; ?>
<?php       $ratio = max(0.0, min(1.0, (float) ($p['ratio'] ?? 0))); ?>
      <div class="glass-chart__bar" data-value="<?= (int) ($p['value'] ?? 0) ?>"
           title="<?= $esc((string) ($p['label'] ?? '')) ?> · <?= (int) ($p['value'] ?? 0) ?> <?= $esc((string) ($b['unit'] ?? '')) ?>">
        <span class="glass-chart__fill" style="height: <?= (int) round($ratio * 100) ?>%"></span>
        <span class="glass-chart__label"><?= $esc((string) ($p['label'] ?? '')) ?></span>
      </div>
<?php     endforeach; ?>
    </div>
<?php   endif; ?>
<?php   if (!empty($b['truncated'])): ?>
    <p class="glass-muted">序列被截断：仅显示前 <?= count($pts) ?> 个点，明确声明未列全。</p>
<?php   endif; ?>
  </div>
<?php else: ?>
  <p class="glass-muted"><?= $esc((string) ($b['value'] ?? '')) ?></p>
<?php endif; ?>
</section>
<?php endforeach; ?>
