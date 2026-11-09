<?php
/**
 * 共享文档尾 —— 变量：$themeName / $family / $js / $esc。
 * 页脚标明材质家族与「前端面」身份；无脚本的布局不输出 script 标签。
 */
?>
  <footer class="glass-panel front-foot">
    <span><?= $esc($themeName) ?> · 前端面 · <?= $esc($family) ?> 材质家族 · 玻璃质感门户</span>
    <span>核心 <code id="front-version">—</code> · 呈现归主题（规矩 J）</span>
  </footer>
</div>
<?php if (($js ?? '') !== ''): ?>
<script>
<?= $js ?>
</script>
<?php endif; ?>
</body>
</html>
