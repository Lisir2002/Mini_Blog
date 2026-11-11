<?php
/**
 * archive 布局 —— 归档 / 标签云 / 术语详情。
 *
 * 变量（由 GlassFrontRenderer 从结构化 payload 收敛）：
 *   $view / $sections / $terms / $term / $total / $shown / $truncated /
 *   $empty / $termsState / $links / $state，以及共享的 $title / $family / $layout / $esc。
 *
 * 页面只给「文本 + 目标地址」；是否渲染成链接、链接长什么样，全由本模板决定（规矩 J）。
 * header 上的 data-* 是给守卫读的机器标记：空 / 截断 / 术语不可用 / 主失败
 * 四种状态必须能被文本证据区分，而不是靠「看起来没内容」去猜。
 */
$isFailed = is_array($state) && $state !== [];
$active = match ($view) { 'terms' => 'terms', default => 'archive' };
?>
<?php include __DIR__ . '/partials/head.php'; ?>

<header class="glass-card archive-hero" data-view="<?= $esc($view) ?>"
        data-total="<?= $total ?>" data-shown="<?= $shown ?>"
        data-truncated="<?= $truncated ? '1' : '0' ?>" data-empty="<?= $empty ? '1' : '0' ?>"
        data-terms-state="<?= $termsState === null ? '0' : '1' ?>" data-failed="<?= $isFailed ? '1' : '0' ?>">
  <p class="glass-eyebrow"><?= $esc($family) ?> 材质 · 内容索引</p>
  <h1><?= $esc($title) ?></h1>
  <p class="glass-muted">文章按时间归档，分类与标签来自内容系统；主题只负责呈现，不持有业务数据。</p>
  <nav class="archive-nav" aria-label="内容索引导航">
<?php foreach ($links as $key => $link): ?>
<?php if (!is_array($link)) continue; ?>
    <a class="archive-nav__link<?= ($key === $active) ? ' is-active' : '' ?>"
       href="<?= $esc((string) ($link['href'] ?? '')) ?>"><?= $esc((string) ($link['label'] ?? '')) ?></a>
<?php endforeach; ?>
  </nav>
<?php if (is_array($term)): ?>
  <p class="archive-term-meta"><?= ($term['kind'] ?? '') === 'tag' ? '标签' : '分类' ?>
    · <?= $esc((string) ($term['name'] ?? '')) ?> · <?= (int) ($term['count'] ?? 0) ?> 篇</p>
<?php endif; ?>
</header>

<?php if ($isFailed): ?>
<?php include __DIR__ . '/partials/state.php'; ?>
<?php else: ?>

<?php if ($view !== 'term'): ?>
  <section class="glass-card archive-cloud" aria-label="分类与标签">
    <div class="front-section__head">
      <h2>分类与标签</h2>
      <span class="glass-faint"><?= count($terms) ?> 个术语</span>
    </div>
<?php if ($termsState !== null): ?>
<?php   $state = $termsState; include __DIR__ . '/partials/state.php'; ?>
<?php elseif ($terms === []): ?>
    <p class="archive-empty">站点还没有分类或标签。接口已正常返回，这是「确实没有」而不是「功能不可用」。</p>
<?php else: ?>
    <ul class="archive-cloud__list">
<?php   foreach ($terms as $t): ?>
<?php     if (!is_array($t)) continue; ?>
      <li>
        <a class="archive-chip" href="<?= $esc((string) ($t['href'] ?? '')) ?>">
          <span class="archive-chip__kind"><?= ($t['kind'] ?? '') === 'tag' ? '标签' : '分类' ?></span>
          <span class="archive-chip__name"><?= $esc((string) ($t['name'] ?? '')) ?></span>
          <span class="archive-chip__count"><?= (int) ($t['count'] ?? 0) ?></span>
        </a>
      </li>
<?php   endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($view !== 'terms'): ?>
  <section class="glass-card archive-index" aria-label="文章归档">
    <div class="front-section__head">
      <h2><?= $view === 'term' ? '该术语下的文章' : '全部文章' ?></h2>
      <span class="glass-faint">共 <?= $total ?> 篇 · 本页 <?= $shown ?> 篇</span>
    </div>
<?php if ($empty): ?>
    <p class="archive-empty">暂无已发布文章。这与「读取失败」「功能不可用」是三种不同的状态，这里明确是第一种。</p>
<?php else: ?>
<?php   foreach ($sections as $sec): ?>
<?php     if (!is_array($sec)) continue; ?>
    <h3 class="archive-group__title"><?= $esc((string) ($sec['label'] ?? '')) ?>
      <small><?= (int) ($sec['count'] ?? 0) ?> 篇</small></h3>
    <ul class="archive-list">
<?php     foreach ((array) ($sec['items'] ?? []) as $it): ?>
<?php       if (!is_array($it)) continue; ?>
<?php       $href = (string) ($it['href'] ?? ''); ?>
      <li class="archive-item">
<?php       if ($href !== ''): ?>
        <a class="archive-item__title" href="<?= $esc($href) ?>"><?= $esc((string) ($it['title'] ?? '')) ?></a>
<?php       else: ?>
        <span class="archive-item__title"><?= $esc((string) ($it['title'] ?? '')) ?></span>
<?php       endif; ?>
        <time class="archive-item__date" datetime="<?= $esc((string) ($it['date'] ?? '')) ?>"><?= $esc((string) ($it['date'] ?? '')) ?></time>
<?php       if ((array) ($it['terms'] ?? []) !== []): ?>
        <span class="archive-item__terms">
<?php         foreach ((array) $it['terms'] as $t): ?>
<?php           if (!is_array($t)) continue; ?>
          <a class="archive-item__term" href="<?= $esc((string) ($t['href'] ?? '')) ?>"><?= $esc((string) ($t['name'] ?? '')) ?></a>
<?php         endforeach; ?>
        </span>
<?php       endif; ?>
      </li>
<?php     endforeach; ?>
    </ul>
<?php   endforeach; ?>
<?php   if ($truncated): ?>
    <p class="archive-truncated">归档设有展示上限：此处列出 <?= $shown ?> 篇，共 <?= $total ?> 篇。未列出的文章仍可通过分页接口读取 —— 明确声明截断，不假装已全部列出。</p>
<?php   endif; ?>
<?php endif; ?>
  </section>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/partials/foot.php'; ?>
