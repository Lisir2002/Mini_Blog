<?php
/**
 * 附件区块 —— 文章正文之后的下载列表。
 *
 * 三种状态必须可区分（data-attachments-state 是给守卫读的机器标记）：
 *   ok          有附件：名称即下载链接，附类型 / 大小 / 内容指纹前 12 位；
 *   empty       确实没有：明确写「本文没有附件」，不是什么都不可见；
 *   unavailable 读取失败：写出原因，绝不伪装成「没有附件」。
 *
 * 只渲染页面算好的数据；私有附件在系统层就已被可见性过滤，模板不做权限判断。
 */
$att = is_array($attachments ?? null)
    ? $attachments : ['items' => [], 'total' => 0, 'state' => null, 'message' => ''];
$items = (array) ($att['items'] ?? []);
$state = $att['state'] ?? null;
?>
<section class="glass-card glass-card--attachments" id="attachments"
         data-attachments-state="<?= $state !== null ? 'unavailable' : ($items === [] ? 'empty' : 'ok') ?>"
         data-attachments="<?= count($items) ?>">
  <h2>附件</h2>
<?php if ($state !== null): ?>
  <p class="glass-muted">附件信息暂时读不到：<?= $esc((string) ($att['message'] ?? '')) ?>。
    这不代表本文没有附件。</p>
<?php elseif ($items === []): ?>
  <p class="glass-muted">本文没有附件。此处没有内容，是因为确实没有，而不是加载失败。</p>
<?php else: ?>
  <ul class="glass-attachments">
<?php foreach ($items as $item): ?>
<?php   if (!is_array($item)) continue; ?>
    <li class="glass-attachments__item">
      <a class="glass-attachments__name" href="<?= $esc((string) ($item['url'] ?? '')) ?>" download>
        <?= $esc((string) ($item['name'] ?? '附件')) ?>
      </a>
      <span class="glass-muted glass-attachments__meta">
        <?= $esc(strtoupper((string) ($item['ext'] ?? ''))) ?>
        · <?= number_format(((int) ($item['bytes'] ?? 0)) / 1024, 1) ?> KB
<?php   if ((string) ($item['sha256'] ?? '') !== ''): ?>
        · sha256 <?= $esc(substr((string) $item['sha256'], 0, 12)) ?>
<?php   endif; ?>
      </span>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
