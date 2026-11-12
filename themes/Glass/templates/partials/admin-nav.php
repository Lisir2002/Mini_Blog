<?php
/**
 * 后台统一导航条 —— 由两款元主题的 admin.php 引入，源码逐字同源。
 * 每个已注册的后台页面都在这里有一个入口：页面能不能少导航是页面的自由，
 * 但「页面存在却没有全局入口」会变成孤儿页，由守卫组 67 兜底拦下。
 * 高亮只看 $view（页面自报的 view 名），主题不查询权限也不判断业务状态；
 * 未登录 / 无权限时页面自己会给出 deny 信号，导航条只负责指路。
 * 变量：$view（页面自报的 view 名，缺省兜底为 ''）、$esc。
 */
$view = (string) ($view ?? '');
$navGroups = [
    '内容' => [
        ['/console/stats', '统计看板', 'stats'],
        ['/console/content', '内容管理', 'content'],
        ['/console/write', '正文创作', 'write'],
        ['/console/terms', '内容标签', 'terms'],
        ['/console/emojis', '表情库', 'emojis'],
        ['/console/attachments', '附件库', 'attachments'],
        ['/console/queue', '审核队列', 'queue'],
    ],
    '治理' => [
        ['/console/logs', '系统日志', 'logs'],
        ['/console/users', '用户管理', 'users'],
        ['/console/roles', '角色授权', 'roles'],
        ['/console/acl', '资源 ACL', 'acl'],
        ['/console/decisions', '决策轨迹', 'decisions'],
        ['/console/member', '成员成长', 'member'],
        ['/console/system', '系统层', 'systems'],
    ],
];
?>
<nav class="glass-card admin-nav" aria-label="后台页面">
<?php foreach ($navGroups as $groupLabel => $links): ?>
  <span class="admin-nav__group"><?= $esc($groupLabel) ?></span>
<?php   foreach ($links as [$href, $label, $viewKey]): ?>
  <a class="admin-nav__item<?= $view === $viewKey ? ' is-active' : '' ?>"
     href="<?= $esc($href) ?>"<?= $view === $viewKey ? ' aria-current="page"' : '' ?>><?= $esc($label) ?></a>
<?php   endforeach; ?>
<?php endforeach; ?>
  <span class="admin-nav__group">账户</span>
  <a class="admin-nav__item" href="/account">我的账号</a>
  <a class="admin-nav__item" href="/">回前台</a>
</nav>
