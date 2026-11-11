# 接口消费方矩阵

`tools/consumers.php` 回答一个问题：**哪个接口有人用，哪些只是「存在」？**

## 为什么需要

前几版反复出现同一种断裂，而且是靠人肉翻 `api/registry.json` 才发现的：

| 版本 | 能力已实现 | 断裂 |
| --- | --- | --- |
| v0.29 | `comments.queue` / `comments.moderate` | 举报进了队列，站上没人看得见 |
| v0.25 | `content.posts` 的 `keyword` | 搜索从上线起 500，前台也没有入口 |
| v0.28 | 四态与五个生命周期接口 | 页面上一个字都写不了，只能 curl |
| v0.33 | `attachments.list` | 附件传了，文章页不显示 |
| v0.34 | `auth.session.*` / `auth.register` / `users.me` | 后台说「需要登录」，浏览器里却没有登录页 |
| v0.35 | `content.create` / `content.update` | 能改状态、能进回收站，但站上写不了正文 |
| v0.36 | `users.list` / `users.manage` | 授权只能 curl，站点上没有用户管理界面 |
| v0.37 | `permission.roles` / `grant` / `revoke` / `role.parent` | 角色与许可只能 curl 改 |
| v0.38 | `permission.decisions` / `permission.check` | 决策留痕只能翻库，站点上问不了「为什么允许」 |
| v0.39 | `system.health` / `layers` / `contracts`、`lifecycle.units`、`release.status` | 运维只能 SSH 跑命令，站点上看不到自身状态 |
| v0.40 | `logs.query` | 排查只能 SSH 看日志文件，页面上没有入口 |
| v0.41 | `member.levels` / `titles` / `xp.rules` / `leaderboard` / `profile` | 等级表、头衔、排行、档案在站点上一个都看不见 |
| v0.42 | `member.xp.award` / `xp.rule` / `title` / `title.grant` / `level` | 发经验、定规则、定头衔、授头衔、改等级只能 curl |

每一次都是「能力完备、闭环缺失」。人工检查不可持续，所以把它变成一个可重跑的查询。

## 怎么用

```bash
php tools/consumers.php          # 全量矩阵
php tools/consumers.php --zero   # 只看零消费
php tools/consumers.php --json   # 机器可读（守卫用）
```

输出把消费方分成两类：

- **确证调用**（`<-`）：源码里出现 `execute('op')`，或前端脚本引用了该接口路径；
- **字符串引用**（`<- ~`）：操作名作为字面量出现，但没有直接 `execute` ——
  典型是 `$intent === 'reply' ? 'comments.reply' : 'comments.add'` 这类动态分派。
  标成引用而不是消费，是为了不把「提到过」当成「用上了」；
- **系统层接缝**（`<- ≈`）：页面不直接调用，而是经过一个有名字的服务（如
  `systems/Security/PageSession.php` 调 `auth.session.create`）。这类文件必须在
  docblock 里显式写 `@consumes <operation>`，工具才认；声明必须能在清单里找到。
  不把整个 `systems/` 算成消费方 —— 系统层之间的相互调用是实现耦合，不是站点可达。

**零消费**只列两类都没有的 operation。

## 扫描范围

只扫「会真正调用接口的地方」：`pages/`、主题的 `assets` 脚本、`tools/`。
系统层自己 `registerOperation` 不算消费方 —— 那是实现，不是使用。
测试与旅程脚本也不计入：它们调用接口是为了验证，不是因为站点需要这个能力。

## 怎么读结果

- 零消费不都是缺陷：`system.*`、`lifecycle.*`、`permission.*` 属于运维面，
  由 CLI / 监控 / 安装器使用，本来就没有前台页面；
- 但**对外承诺过的能力**出现在零消费里，就是闭环断了。v0.33 之前
  `comments.queue`、`content.transition`、`attachments.list` 都在这一栏。

## 证据

`49` 组守卫：矩阵覆盖全部 operation、本轮新接的页面确实被算作消费方、
零消费与确证消费互斥、`--zero` 不混入已消费段，以及 `@consumes` 接缝能被识别。

v0.34 后零消费从 63 降到 57：`auth.session.create`（接缝）、`auth.session.current`、
`auth.register`、`auth.password.change`、`users.me`、`users.me.update` 均已离开零消费。
`auth.session.revoke` / `auth.logout` 仍为零：页面登出直接调 `SessionManager::revoke()`，
这两个接口保留给 API 客户端 —— 零消费不等于无用，只等于「站点不经过它」。
v0.35 再减少 2 个：`content.create` 与 `content.update` 由 `/console/write` 消费。
v0.36 再减少 2 个：`users.list` 与 `users.manage` 由 `/console/users` 消费。`users.view` 仍为零 —— 列表已经够用，详情接口留给后续的账号详情页。
v0.37 再减少 3 个：`permission.roles`、`permission.catalog`、`permission.role.parent` 由 `/console/roles` 消费；`permission.grant` / `permission.revoke` 通过同一页的写入口消费（工具把它们算作页面调用）。
v0.38 再减少 2 个：`permission.decisions` 与 `permission.check` 由 `/console/decisions` 消费。`permission.acl.list` / `permission.acl.set` / `permission.groups` / `permission.group` 仍为零 —— 资源 ACL 与用户组缺的是「选中某篇文章」的浏览路径，不是页面容器。
v0.39 再减少 5 个：`system.health`、`system.layers`、`system.contracts`、`lifecycle.units`、`release.status` 由 `/console/system` 消费。v0.40 再减少 1 个：`logs.query` 由 `/console/logs` 消费；同一版修掉了它的 `total` 缺陷（先切片再计数），并补 `returned` / `truncated` / `exists` / `dates`。
v0.41 再减少 5 个：`member.levels`、`member.titles`、`member.xp.rules`、`member.leaderboard`、`member.profile` 由 `/console/member` 消费（前四个经本地 `call()` 包装，矩阵记为字符串引用；`member.profile` 是直调）。同一版修掉了 `leaderboard` 的 `total`（原先是本页条数）与 `profile` 对不存在的数字 ID 编造空档案两个缺陷。成员层的五个写接口仍为零消费 —— 发经验 / 授头衔 / 改等级与规则留给下一版。

v0.42 再减少 5 个：五个写接口由 `/console/member` 的操作台消费（经 `MemberAction` 的动作表动态拼接，矩阵记为字符串引用）。同一版修掉了扫描器的一处误报：member 的权限点与 operation 同名，v0.41 的 `MemberQuery::capabilities()` 里 `allows($identity, 'member.xp.award')` 曾被当成「已经有人调过这个接口」，把两个写接口伪装成已消费；现在扫字符串前先抹掉 `allows(...)` 调用整段，并在 `49` 组加回归断言。零消费 37 → 34。
