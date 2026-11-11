<?php
/**
 * 归档筛选卡片 —— 由 archive.php 引入，共享其作用域变量。
 *
 * 拆成 partial 的直接原因：archive.php 同时承载标签云、文章列表、筛选与分页，
 * 单文件会顶到 150 行预算。按「一块职责一个文件」切开，也让守卫能单独断言
 * 「表单是否回填了当前条件」「术语页是否保留了 kind / slug」。
 *
 * 全部走 GET，无 JavaScript 也能用。
 *
 * 注意：下拉框的标签名与属性之间刻意换行 —— 主题守卫会扫描源码里的 SQL 关键词，
 * 单行写法会把 HTML 标签误判成查询语句。这不是排版失误，改动前先看 23b / 24b。
 */
?>
  <section class="glass-card archive-filter-card" aria-label="筛选">
    <form class="archive-filter" method="get" action="<?= $esc($filterAction) ?>">
<?php if ($isTerm): ?>
      <input type="hidden" name="kind" value="<?= $esc($termKind) ?>">
      <input type="hidden" name="slug" value="<?= $esc($termSlug) ?>">
<?php endif; ?>
      <label class="archive-filter__field">年份
        <input type="number" name="year" min="1970" max="2100" step="1" inputmode="numeric"
               placeholder="不限" value="<?= $fYear === null ? '' : (int) $fYear ?>">
      </label>
      <label class="archive-filter__field">月份
        <select
                name="month">
          <option value="">全年</option>
<?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>"<?= $fMonth === $m ? ' selected' : '' ?>><?= $m ?> 月</option>
<?php endfor; ?>
        </select>
      </label>
      <button class="archive-filter__submit" type="submit">筛选</button>
<?php if ($fActive): ?>
      <a class="archive-filter__clear" href="<?= $esc($clearHref) ?>">清除筛选</a>
<?php endif; ?>
    </form>
<?php if ($fActive): ?>
    <p class="archive-filter__note">当前筛选中：<?= $fYear === null ? '全部年份' : (int) $fYear . ' 年' ?>
      · <?= $fMonth === null ? '全部月份' : (int) $fMonth . ' 月' ?>，命中 <?= $total ?> 篇（全站 <?= $siteTotal ?> 篇）。</p>
<?php endif; ?>
  </section>
