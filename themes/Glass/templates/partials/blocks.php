<?php
/**
 * 通用区块协议渲染器 —— 页面只产结构化数据，标签结构由主题定义。
 * 变量：$blocks(list<array>)、$esc(callable)。
 * 支持 type：pre / text / list / table / form / notice / chart / links。
 *   notice  显式状态条：data-stats-state 让降级 / 空窗口 / 正常可被文本证据区分；
 *   chart   纯 CSS 柱状图：零脚本、零外部资源，柱高由页面算好的 ratio 提供；
 *   links   链接组：窗口切换等 GET 导航，active 用 aria-current 标出，不靠颜色单独表意；
 *   list    条目可为纯文本或 {label, href, note}：搜索结果需要标题即链接，纯文本行保持不变；
 *   form    带 action 时输出真正的 <form>（含 hidden 与 submit），用于无脚本可提交的后台操作；
 *           不带 action 时保持原样（无 name 的输入行，由 admin.js 读取），向后兼容。
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
<?php   if (is_array($it)): ?>
    <li>
      <a class="glass-link" href="<?= $esc((string) ($it['href'] ?? '')) ?>"><?= $esc((string) ($it['label'] ?? '')) ?></a>
<?php     if ((string) ($it['note'] ?? '') !== ''): ?>
      <span class="glass-muted"><?= $esc((string) $it['note']) ?></span>
<?php     endif; ?>
    </li>
<?php   else: ?>
    <li><?= $esc((string) $it) ?></li>
<?php   endif; ?>
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
<?php   $action = (string) ($b['action'] ?? ''); ?>
<?php   $method = strtolower((string) ($b['method'] ?? 'post')) === 'get' ? 'get' : 'post'; ?>
<?php   if ($action !== ''): ?>
  <form class="glass-form" method="<?= $esc($method) ?>" action="<?= $esc($action) ?>">
<?php     if ((string) ($b['body'] ?? '') !== ''): ?>
    <p class="glass-form__body"><?= $esc((string) $b['body']) ?></p>
<?php     endif; ?>
<?php     if ((string) ($b['href'] ?? '') !== ''): ?>
    <p><a class="glass-link" href="<?= $esc((string) $b['href']) ?>"><?= $esc((string) ($b['href_label'] ?? '查看')) ?></a></p>
<?php     endif; ?>
<?php     foreach ((array) ($b['hidden'] ?? []) as $h): ?>
<?php       if (!is_array($h)) continue; ?>
    <input type="hidden" name="<?= $esc((string) ($h['name'] ?? '')) ?>" value="<?= $esc((string) ($h['value'] ?? '')) ?>">
<?php     endforeach; ?>
<?php   endif; ?>
  <div class="glass-row">
<?php foreach ((array) ($b['fields'] ?? []) as $f): ?>
<?php   $tag = (string) ($f['tag'] ?? 'input'); ?>
<?php   $name = (string) ($f['name'] ?? ''); ?>
<?php   if ($tag === 'textarea'): ?>
    <textarea id="<?= $esc((string) ($f['id'] ?? '')) ?>"<?= $name !== '' ? ' name="' . $esc($name) . '"' : '' ?> placeholder="<?= $esc((string) ($f['placeholder'] ?? '')) ?>"><?= $esc((string) ($f['value'] ?? '')) ?></textarea>
<?php   elseif ($tag === 'submit'): ?>
    <button<?= $name !== '' ? ' name="' . $esc($name) . '" value="' . $esc((string) ($f['value'] ?? '')) . '"' : '' ?> type="submit"><?= $esc((string) ($f['label'] ?? '提交')) ?></button>
<?php   elseif ($tag === 'button'): ?>
    <button id="<?= $esc((string) ($f['id'] ?? '')) ?>" type="button"><?= $esc((string) ($f['label'] ?? '')) ?></button>
<?php   else: ?>
    <input id="<?= $esc((string) ($f['id'] ?? '')) ?>"<?= $name !== '' ? ' name="' . $esc($name) . '"' : '' ?> type="<?= $esc((string) ($f['type'] ?? 'text')) ?>"<?= isset($f['value']) ? ' value="' . $esc((string) $f['value']) . '"' : '' ?> placeholder="<?= $esc((string) ($f['placeholder'] ?? '')) ?>" autocomplete="off">
<?php   endif; ?>
<?php endforeach; ?>
  </div>
<?php   if ($action !== ''): ?>
<?php     if ((string) ($b['note'] ?? '') !== ''): ?>
  <p class="glass-muted"><?= $esc((string) $b['note']) ?></p>
<?php     endif; ?>
  </form>
<?php   endif; ?>
<?php elseif ($type === 'notice'): ?>
  <div class="glass-notice glass-notice--<?= $esc((string) ($b['tone'] ?? 'info')) ?>"
       id="<?= $esc((string) ($b['id'] ?? '')) ?>"
       data-stats-state="<?= $esc((string) ($b['state'] ?? '')) ?>" role="status">
    <p class="glass-notice__title"><?= $esc((string) ($b['title'] ?? '')) ?></p>
    <p class="glass-notice__value"><?= $esc((string) ($b['value'] ?? '')) ?></p>
  </div>
<?php elseif ($type === 'links'): ?>
  <nav class="glass-links" id="<?= $esc((string) ($b['id'] ?? '')) ?>"
       aria-label="<?= $esc((string) ($b['title'] ?? '链接组')) ?>">
<?php foreach ((array) ($b['items'] ?? []) as $it): ?>
<?php   if (!is_array($it)) continue; ?>
<?php   $on = !empty($it['active']); ?>
    <a class="glass-links__item<?= $on ? ' is-active' : '' ?>"
       href="<?= $esc((string) ($it['href'] ?? '')) ?>"<?= $on ? ' aria-current="page"' : '' ?>>
      <?= $esc((string) ($it['label'] ?? '')) ?>
<?php   if ((string) ($it['note'] ?? '') !== ''): ?>
      <span class="glass-muted">（<?= $esc((string) $it['note']) ?>）</span>
<?php   endif; ?>
    </a>
<?php endforeach; ?>
  </nav>
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
