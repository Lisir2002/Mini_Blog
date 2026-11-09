<?php
/**
 * 评论树片段 —— 原生 PHP 递归渲染，无模板引擎、无 JavaScript。
 * 可用变量：$comments（{total, items, tree}）、$esc、$csrf、$post。
 *
 * 回复表单放在 <details> 里：无脚本也能展开、也能提交；JS 只是让展开更顺滑。
 * 已删除的评论渲染为占位，子回复照常显示 —— 对话结构不因删除而断裂。
 */
$renderNode = function (array $node) use (&$renderNode, $esc, $csrf, $post): void {
    $id      = (int) ($node['id'] ?? 0);
    $deleted = (bool) ($node['deleted'] ?? false);
    $depth   = (int) ($node['depth'] ?? 0);
    $slug    = (string) ($post['slug'] ?? '');
    $tokens  = (array) ($node['tokens'] ?? []);
    ?>
<li id="comment-<?= $id ?>" class="wx-comment<?= $deleted ? ' is-deleted' : '' ?>" data-depth="<?= $depth ?>">
  <div class="wx-comment__head">
    <strong class="wx-comment__author"><?= $esc((string) ($node['author'] ?? '匿名访客')) ?></strong>
    <time class="wx-comment__time" datetime="<?= $esc((string) ($node['created_at'] ?? '')) ?>"><?= $esc((string) ($node['created_at'] ?? '')) ?></time>
  </div>
<?php if ($deleted): ?>
  <p class="wx-comment__body wx-comment__body--deleted">该评论已删除</p>
<?php else: ?>
  <div class="wx-comment__body"><?php include __DIR__ . '/emoji.php'; ?></div>
  <div class="wx-comment__actions">
    <?php if ($depth < 5): ?>
    <details class="wx-comment__reply">
      <summary>回复</summary>
      <form method="post" action="/post" class="wx-form wx-form--reply">
        <input type="hidden" name="slug" value="<?= $esc($slug) ?>">
        <input type="hidden" name="_token" value="<?= $esc($csrf) ?>">
        <input type="hidden" name="parent_id" value="<?= $id ?>">
        <label>昵称<input name="author" maxlength="64" autocomplete="nickname"></label>
        <label>回复内容<textarea name="body" rows="3" required maxlength="5000"></textarea></label>
        <button type="submit">发表回复</button>
      </form>
    </details>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php if (($node['children'] ?? []) !== []): ?>
  <ol class="wx-comment__children">
    <?php foreach ((array) $node['children'] as $child) $renderNode((array) $child); ?>
  </ol>
<?php endif; ?>
</li>
    <?php
};
?>
<section id="comments" class="wx-comments">
  <h2>评论<?= ((int) ($comments['total'] ?? 0)) > 0 ? '（' . (int) $comments['total'] . '）' : '' ?></h2>
<?php if (($comments['tree'] ?? []) === []): ?>
  <p class="wx-comments__empty">还没有评论，来说点什么吧。</p>
<?php else: ?>
  <ol class="wx-comments__list">
    <?php foreach ((array) $comments['tree'] as $node) $renderNode((array) $node); ?>
  </ol>
<?php endif; ?>
  <details class="wx-comments__compose" open>
    <summary>写评论</summary>
    <form method="post" action="/post" class="wx-form">
      <input type="hidden" name="slug" value="<?= $esc((string) ($post['slug'] ?? '')) ?>">
      <input type="hidden" name="_token" value="<?= $esc($csrf) ?>">
      <label>昵称<input name="author" maxlength="64" autocomplete="nickname" placeholder="留空显示为匿名访客"></label>
      <label>内容<textarea name="body" rows="4" required maxlength="5000" placeholder="支持 :smile: 这类短码表情"></textarea></label>
      <button type="submit">发表评论</button>
      <p class="wx-form__hint">支持 <code>:smile:</code>、<code>:tada:</code>、<code>:shrug:</code> 等短码；未知短码会原样显示。</p>
    </form>
  </details>
</section>
