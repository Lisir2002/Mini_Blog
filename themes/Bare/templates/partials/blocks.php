<?php
/**
 * 通用区块协议渲染器 —— 页面只产结构化数据，标签结构由主题定义。
 * 变量：$blocks(list<array>)、$esc(callable)
 * 支持 type：pre / text / list / table / form
 */
?>
<?php foreach ($blocks as $b): ?>
  <section class="blk">
    <h2><?= $esc((string) ($b['title'] ?? '')) ?></h2>
<?php $type = (string) ($b['type'] ?? 'text'); ?>
<?php if ($type === 'pre'): ?>
    <pre id="<?= $esc((string) ($b['id'] ?? '')) ?>"><?= $esc((string) ($b['value'] ?? '')) ?></pre>
<?php elseif ($type === 'list'): ?>
    <ul id="<?= $esc((string) ($b['id'] ?? '')) ?>">
<?php foreach ((array) ($b['items'] ?? []) as $it): ?>
      <li><?= $esc((string) $it) ?></li>
<?php endforeach; ?>
    </ul>
<?php elseif ($type === 'table'): ?>
    <table>
<?php foreach ((array) ($b['rows'] ?? []) as $row): ?>
      <tr>
        <th><?= $esc((string) ($row['k'] ?? '')) ?></th>
        <td class="<?= ($row['ok'] ?? false) ? 'ok' : 'warn' ?>"><?= $esc((string) ($row['v'] ?? '')) ?></td>
        <td class="hint"><?= $esc((string) ($row['note'] ?? '')) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
<?php elseif ($type === 'form'): ?>
    <div class="row">
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
<?php else: ?>
    <p class="hint"><?= $esc((string) ($b['value'] ?? '')) ?></p>
<?php endif; ?>
  </section>
<?php endforeach; ?>
