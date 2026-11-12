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
| v0.43 | `attachments.update` / `attachments.delete` / `attachments.download` | 改名 / 改可见性 / 删除 / 受控下载没有后台入口 |
| v0.44 | `permission.acl.set` / `acl.list` / `role.tree` / `overrides` / `groups` / `group`、`users.view` | 资源 ACL 与用户组缺「选中某篇文章」的浏览路径，授权只能 curl |
| v0.45 | `content.terms.manage` / `content.attach` | 分类与标签只在前台展示，后台没有新建 / 改名 / 删除与文章挂载入口 |
| v0.46 | `comments.delete` / `emojis.delete` | 评论删除与表情删除没有后台入口：队列口径只覆盖被举报条目，表情目录只在 journey 脚本里出现 |
| v0.47 | `system.health` / `layers` / `contracts`、`content.stats`、`auth.session.revoke` / `auth.logout` | /console/system 早已逐项调用 system.*，但「'system.' . $key」拼接让扫描器看不见 —— 这不是漏接，是漏报；内容规模与两个会话撤销接口则真的没有消费方 |
| v0.48 | `release.history` / `lifecycle.verify` / `lifecycle.releases` / `state.task.status`；其余 10 个写接口转入刻意零消费登记表 | 「决定要不要回退」需要看得到事实，但升级面的四个只读接口没有页面；写接口的「刻意不接网页」从口头约定变成登记表 |
| v0.49 | `attachments.upload` | 唯一接收原始字节的写接口只有工具消费方：矩阵看到 faults / journey 用它就不算零消费，刻意零消费登记表也拦不住 —— 「能力在、入口缺」的存量缺口只能靠逐个接口过一遍 |

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

v0.43 再减少 3 个：`attachments.update` / `attachments.delete` 由 `/console/attachments` 消费（动作名动态拼接，记为字符串引用），`attachments.download` 通过页面产出的 `/attach/download?id=` 链接消费。为此矩阵新增「链接消费」识别：只认 `execute()` 会把二进制通道永远列成零消费，而页面**不该**直调它（文件字节会进页面层）。`49` 组加断言。零消费 34 → 31。

v0.44 再减少 7 个：`permission.acl.set` / `permission.acl.list` / `permission.role.tree` / `permission.overrides` / `permission.groups` / `permission.group` 与 `users.view` 均由 `/console/acl` 消费。页面先按 `content.posts` 选文章，再按 `type+id` 从 `acl.list` 的最近 100 条里筛出这一篇，并显式声明「不是完整快照」；`users.view` 同时是读路径（`?user=` 查账号）与写前校验。`permission.override.grant` 刻意仍是零消费：装配期动作只应经本机来源（RequestGuard `40302`），页面直调执行者会绕过它；`49` 组加断言把「刻意不接」与「忘了接」区分开。零消费 31 → 24。

v0.45 再减少 2 个：`content.terms.manage` 与 `content.attach` 由 `/console/terms` 消费（动作名经 `TermsInput` 的字符串字面量，矩阵记为字符串引用）。同一版把 `content.post` / `content.posts` 实际早已返回、但契约未声明的 `terms` / `items[].terms` 补进 `response_fields` —— 页面靠它预填挂载框，属于「消费方在依赖一个没写下来的字段」。零消费 24 → 22；`auth.logout` / `auth.session.revoke` 仍为零，页面登出直接调 `SessionManager::revoke()`，这两个接口按设计保留给 API 客户端。

v0.46 再减少 2 个：`comments.delete` 由 `/console/queue` 消费，并补上 `comments.list` 的「按文章浏览」读路径（队列口径只收 pending 与被举报，删正常文章下的评论必须另有入口）；`emojis.delete` 由新建的 `/console/emojis` 消费，同页还接了 `emojis.list` / `emojis.register`。`emojis.register` / `emojis.delete` 的动作名经 `EmojisAction` 的字符串字面量，矩阵记为字符串引用；`comments.list` 从此有前台文章页与后台队列页两个消费方。零消费 22 → 20；`auth.logout` / `auth.session.revoke` 按设计仍保留给 API 客户端，`permission.override.grant` 继续刻意不接网页。

v0.47 再减少 6 个，其中一半是**修漏报而不是补消费**：`/console/system` 从 v0.39 起就真的在调用 `system.health` / `system.layers` / `system.contracts`，但取数走 `'system.' . $key` 动态拼接，扫描器只认 `execute('operation', ...)` 字面量 —— 已消费被误报成零消费。改为显式字面量映射后矩阵如实记账，这一条写进 `64` 组守卫：拼接调用从此是反模式。同批真的补上的：`content.stats` 进 `/console/system` 的「内容规模」区块（published/total 与文章列表同口径）；`auth.session.revoke` 由安全层 `PageSession::logout` 消费（登出走与 API 客户端同一执行器，只撤 `kind=session`，接口不可用降级直撤并留痕）；`auth.logout` 由 `PageSession::revokeResidual` 消费（换号登录前把残留 Cookie 指向的旧凭据撤掉，`revoked=false` 幂等）。两者都经 `@consumes` 标注记为安全层引用。零消费 20 → 14。

v0.48 分两步走。先真补上 4 个只读探查接口（`release.history` 发布历史完整上表、`lifecycle.verify` 单元摘要核对、`lifecycle.releases` 快照清单、`state.task.status` 按任务 id 查相位），全部由 `/console/system` 以 GET 查询消费 —— 14 → 10，剩下的 10 个全部是「有写能力、按设计不接网页」。再把这句话制度化：`api/deliberate-zero.json` 登记这 10 个接口（安装 / 启用 / 升级 / 卸载 / 回滚 / 契约覆盖授权），每条带理由；`tools/consumers.php` 把零消费分成「刻意保留」（登记过）与「未登记」（真正没人用）两桶，`49` / `64` 组守卫做双向核对 —— 登记了已消费的 op、存在未登记的零消费、理由缺失，都会被拦下。**未登记零消费从此必须为空**：新接口要么有入口、要么先写清为什么不做入口。

v0.49 补上 `attachments.upload`：从 v0.13 起它就是唯一接收原始字节的写接口，但消费方只有 faults / journey 这类测试工具 —— 矩阵看到工具消费就不算零消费，v0.48 的登记表也只拦「零消费」，两头都拦不住「能力在、入口缺」的存量缺口。`/console/attachments` 新增独立 multipart 上传表单（`core/Http/Request` 加 `files` 通道，`file()` 访问器只接受 `UPLOAD_ERR_OK` 且 tmp 存在的上传，其余返回 null），页面 Action 现场问 `attachment.upload`（与改动的 `attachment.manage` 互相独立），把字节原样交给执行器 —— 展示名不做输入（接口的 `name` 参数同时决定扩展名，另起名会破坏类型判定）。`66` 组守卫同时锁两件事：上传链路的运行时行为（303 / 422 / 白名单 / 魔数 / 权限），以及 `attachments.upload` 必须保留页面消费方 —— 防止未来清理工具时页面入口被误伤回退。

v0.50 消费矩阵零变化：导航收口不产生新的 API 消费 —— GlassFront 主导航补
注册 / 账户 / 工具箱三个页面入口、新增 admin-nav.php 后台统一导航条（两款元主题
逐字同源，覆盖全部 `/console` 页与 `/account`）都是纯呈现层接线，页面产数逻辑
一行未动。零消费维持 10 刻意 / 0 未登记；`49` / `64` 组守卫照常通过。同版新增
`67` 组导航覆盖守卫，把「页面有路由、全站没入口」的孤儿页与零消费制度对齐成
同一条纪律：例外必须带 ≥20 字理由登记、已有人指到的路由不许冗余登记。

v0.51 补上 `content.terms` 的第二个消费方：站点地图从只列「3 个静态页 + 文章」
扩成「4 个静态页（+`/links`）+ 文章（带 lastmod）+ 有文章挂载的标签页」。此前
标签详情页 `/term` 只被归档页的标签云链接消费 —— 对爬虫而言这些页面是不可见的，
sitemap 收录的是「读者能到达、爬虫也能发现」的完整集合。post_count>0 的过滤
在 FeedQuery::terms() 取数层完成（空标签页是空列表，不该被收录）；接口不可用
（内存降级 50101）时标签段显式注释留痕，文章段照常 —— 收录降级要看得见。
`48` 组从 6 项扩到 8 项：序列化层锁 lastmod 的 W3C 格式、`&` 转义、截断显式
声明与不可用留痕；取数层锁过滤口径与降级回报；运行时锁真实 sitemap 的收录行为
与 robots 的 `Disallow: /account`。消费矩阵有消费方变化（`content.terms` 仍已
消费），零消费维持 10 刻意 / 0 未登记。

v0.52 消费矩阵零变化：UTF-8 文本原语统一是纯取数与序列化层的口径修正 ——
新建 `systems/Data/Utf8Text.php`（PCRE 单一实现，零扩展依赖），收口全部 10 处
「mb 探测 + 字节退化」双路实现。修复的行为缺陷：字节截断把多字节字符从中间
切开，RSS 摘要末尾出现「�」、显示名截断后 `json_encode` 返回 false 让整个
响应变空白；更系统的问题是 mbstring 装没装会让同一个输入得到两种业务行为
（关键词 80 字上限一处按字符一处按字节）。零消费维持 10 刻意 / 0 未登记；
`68` 组锁住 Utf8Text 行为契约与双路实现回流。

v0.53 把「零消费」的粒度从 operation 下沉到**参数**：`content.transition` 早已被
本页的发布 / 转草稿 / 归档按钮消费，`content.update` 早已被写作页消费，消费矩阵
因此一直显示两者「已消费」——但 `publish_at`、`pinned`、`weight` 三个参数从来
没有任何页面能设置。零消费归零、登记表双向核对都拦不住这类缺口，因为矩阵只看
operation 名。本版在 `/console/content` 选中一篇文章后补两张独立表单：排期走
`content.transition` 的 `publish_at`（`content.publish`），推荐位走 `content.update`
的 `pinned` / `weight`（`content.edit`，只带这两个字段以免字段级 ACL 连累正文）。
落在内容管理页而非写作页，是因为 `content.post` 对「已发布但 publish_at 未到点」
的文章无条件 40403 —— 写作页读不到自己刚排期的文章。消费矩阵 operation 数不变，
零消费维持 10 刻意 / 0 未登记；`69` / `69b` 两组共 10 项守卫锁住参数级入口与定时可见性。
