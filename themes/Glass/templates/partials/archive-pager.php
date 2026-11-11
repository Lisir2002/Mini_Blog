<?php
/**
 * 归档分页导航 —— 由 archive.php 引入，共享其作用域变量。
 *
 * 只在多于 1 页时渲染：单页站点不出现一排永远点不动的控件。
 * 上一页 / 下一页用 rel 标注，无 JavaScript 也能翻页；链接由页面拼好，
 * 筛选条件与术语条件都在链接里（翻页不丢筛选）。
 */
?>
<?php if ($pages > 1 || $beyond): ?>
    <nav class="archive-pager" aria-label="分页" data-pager="1" data-page="<?= $pageNo ?>" data-pages="<?= $pages ?>">
<?php if ($hasPrev): ?>
      <a class="archive-pager__link" rel="prev" href="<?= $esc($prevHref) ?>">← <?= $beyond ? '回到第 ' . $pages . ' 页' : '上一页' ?></a>
<?php else: ?>
      <span class="archive-pager__link is-disabled">← 上一页</span>
<?php endif; ?>
      <span class="archive-pager__status"><?= $beyond ? '超出范围' : '第 ' . $pageNo . ' / ' . $pages . ' 页' ?> · 每页 <?= $perPage ?> 篇</span>
<?php if ($hasNext): ?>
      <a class="archive-pager__link" rel="next" href="<?= $esc($nextHref) ?>">下一页 →</a>
<?php else: ?>
      <span class="archive-pager__link is-disabled">下一页 →</span>
<?php endif; ?>
    </nav>
<?php endif; ?>
