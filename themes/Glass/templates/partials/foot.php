<?php
/** 共享文档尾 —— 变量：$js。无脚本的布局不输出 script 标签。 */
?>
<?php if (($js ?? '') !== ''): ?>
<script>
<?= $js ?>
</script>
<?php endif; ?>
</body>
</html>
