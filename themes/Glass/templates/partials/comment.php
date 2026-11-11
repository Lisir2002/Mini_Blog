<?php
/**
 * 评论树片段 —— 原生 PHP 递归渲染，无模板引擎、无 JavaScript。
 * 变量：$comments（{total, items, tree}）、$esc、$csrf、$post、$visitorId。
 * 回复 / 点赞 / 举报全部走「同页 POST → 303」的 PRG，共用一枚 CSRF 一次性令牌；
 * 互动按钮只在页面算好的 can_interact 为真时出现，主题不判断业务状态。
 * 编辑入口只在页面附了 edit_ticket 时出现：票据是服务端签发的短期凭证，
 * 表单本身不带身份，这里绝不能凭 editable 就画一个提交必失败的按钮。
 */
$renderNode = function (array $node) use (&$renderNode, $esc, $csrf, $post, $visitorId): void {
    $id      = (int) ($node['id'] ?? 0);
    $deleted = (bool) ($node['deleted'] ?? false);
    $depth   = (int) ($node['depth'] ?? 0);
    $slug    = (string) ($post['slug'] ?? '');
    $tokens  = (array) ($node['tokens'] ?? []);
    $likes    = (int)  ($node['likes'] ?? 0);
    $liked    = !empty($node['liked']);
    $reports  = (int)  ($node['reports'] ?? 0);
    $reported = !empty($node['reported']);
    $canInteract = !empty($node['can_interact']);
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
<?php if ($canInteract): ?>
  <p class="wx-comment__metrics">
    <span class="wx-comment__likes" data-likes="<?= $likes ?>"><?= $likes ?> 赞</span>
<?php   if ($reports > 0): ?>
    <span class="wx-comment__reports" data-reports="<?= $reports ?>"><?= $reports ?> 次举报</span>
<?php   endif; ?>
  </p>
<?php endif; ?>
  <div class="wx-comment__actions">
<?php if ($canInteract): ?>
    <form method="post" action="/post" class="wx-form wx-form--inline">
      <input type="hidden" name="slug" value="<?= $esc($slug) ?>">
      <input type="hidden" name="_token" value="<?= $esc($csrf) ?>">
      <input type="hidden" name="visitor_id" value="<?= $esc($visitorId) ?>">
      <input type="hidden" name="_intent" value="like">
      <input type="hidden" name="comment_id" value="<?= $id ?>">
      <input type="hidden" name="vote" value="<?= $liked ? 'remove' : 'add' ?>">
      <button type="submit" class="wx-interact<?= $liked ? ' is-on' : '' ?>"
              data-liked="<?= $liked ? '1' : '0' ?>"><?= $liked ? '取消赞' : '赞' ?></button>
    </form>
    <details class="wx-comment__report">
      <summary><?= $reported ? '已举报' : '举报' ?></summary>
      <form method="post" action="/post" class="wx-form wx-form--inline">
        <input type="hidden" name="slug" value="<?= $esc($slug) ?>">
        <input type="hidden" name="_token" value="<?= $esc($csrf) ?>">
        <input type="hidden" name="visitor_id" value="<?= $esc($visitorId) ?>">
        <input type="hidden" name="_intent" value="report">
        <input type="hidden" name="comment_id" value="<?= $id ?>">
        <label class="sr-only" for="reason-<?= $id ?>">举报原因</label>
        <select
                id="reason-<?= $id ?>" name="reason">
          <option value="spam">垃圾广告</option>
          <option value="abuse">辱骂攻击</option>
          <option value="offtopic">无关内容</option>
          <option value="other">其他</option>
        </select>
        <button type="submit" class="wx-interact wx-interact--report">提交举报</button>
      </form>
    </details>
<?php endif; ?>
<?php if ((string) ($node['edit_ticket'] ?? '') !== ''): ?>
    <details class="wx-comment__edit">
      <summary>编辑</summary>
      <form method="post" action="<?= $esc((string) ($node['edit_action'] ?? '/post')) ?>" class="wx-form wx-form--edit">
        <input type="hidden" name="slug" value="<?= $esc($slug) ?>">
        <input type="hidden" name="_token" value="<?= $esc($csrf) ?>">
        <input type="hidden" name="visitor_id" value="<?= $esc($visitorId) ?>">
        <input type="hidden" name="_intent" value="edit">
        <input type="hidden" name="comment_id" value="<?= $id ?>">
        <input type="hidden" name="_ticket" value="<?= $esc((string) $node['edit_ticket']) ?>">
        <label>修改内容<textarea name="body" rows="3" required maxlength="5000"><?= $esc((string) ($node['body'] ?? '')) ?></textarea></label>
        <p class="wx-form__hint">编辑窗口从发表时起算，不因修改而延长；保存成功后本页上的旧编辑入口会失效，请刷新后再改。</p>
        <button type="submit">保存修改</button>
      </form>
    </details>
<?php endif; ?>
<?php if ($depth < 5): ?>
    <details class="wx-comment__reply">
      <summary>回复</summary>
      <form method="post" action="/post" class="wx-form wx-form--reply">
        <input type="hidden" name="slug" value="<?= $esc($slug) ?>">
        <input type="hidden" name="_token" value="<?= $esc($csrf) ?>">
        <input type="hidden" name="visitor_id" value="<?= $esc($visitorId) ?>">
        <input type="hidden" name="_intent" value="reply">
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
      <input type="hidden" name="visitor_id" value="<?= $esc($visitorId) ?>">
      <input type="hidden" name="_intent" value="comment">
      <label>昵称<input name="author" maxlength="64" autocomplete="nickname" placeholder="留空显示为匿名访客"></label>
      <label>内容<textarea name="body" rows="4" required maxlength="5000" placeholder="支持 :smile: 这类短码表情"></textarea></label>
      <button type="submit">发表评论</button>
      <p class="wx-form__hint">支持 <code>:smile:</code>、<code>:tada:</code>、<code>:shrug:</code> 等短码；未知短码会原样显示。</p>
    </form>
  </details>
</section>
