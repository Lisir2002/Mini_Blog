# 数据系统（v0.14）：把「连接层」原位升级为「数据层」

> 一句话结论：**没有再开第 18 层，而是把第 8 层 `database` 改名 `data` 并换掉职责。**
> 它原本只管「怎么连上」，现在管连接与严格模式、事务会话、查询观测、孤儿巡检四件事。
> 系统层仍是 **17 个**，`core/` 仍是 **24 个 PHP 文件**，新增的 3 个接口全部落在 `systems/Data/`，不占核心预算。

---

## 0. 为什么要独立设计，以及为什么不加层

用户问的是「数据系统是否单独写一个，还是有什么对接写什么」。先看现状：升级前 `systems/Database` 只有 **194 行、4 个方法**（`pdo / query / exec / prefix`）加一个建表兜底，它是个**插座**；而真正的数据平面有约 **1,522 行**，分散在 12 个业务仓储里各写各的。

> **1 : 8 的比例就是问题**：数据代码已经占全项目一成，管它的那一层却只管「怎么连上」。加固、效率、安全、操作这四件事没有主人，只能靠每个仓储自觉。

但「重要」不等于「该开一层」。判据只有三条，且必须同时成立：

| 判据 | 问的是 | 结论 |
| --- | --- | --- |
| 独立生命周期 | 它会单独演化吗（换驱动 / 读写分离 / 加缓存 / 换巡检策略）？ | ✅ 会 |
| 跨系统被依赖 | 12 个系统都要用它吗？ | ✅ 是 |
| 可整体替换 | 能换掉实现而不动业务吗？ | ✅ 能，走 `DatabaseInterface` |

三条都成立，所以**数据确实需要一个系统**。但按同一组判据把「数据」拆开，归属完全不同：

| 归类 | 内容 | 归属 | 理由 |
| --- | --- | --- | --- |
| **A · 该进层** | 事务作用域、批量取数、查询计数 / 慢查询、完整性巡检、连接与严格模式 | ✅ 升级为 `data` 层 | 有独立生命周期、被 12 层依赖、可整体替换 |
| **B · 不该进层** | 列白名单、`hydrate` 类型化、软删过滤、SQL 拼接 | ❌ 留在各仓储 | 这是**每个仓储自己的知识**。抽成「通用仓储」它必须知道所有表，立刻变成上帝对象，且违反职责预算 |
| **C · 不进热路径** | 备份、导出、恢复、表优化 | ⚠️ `tools/` + 受控入口 | 不参与请求热路径，做成系统层只会平白增加装配顺序约束 |

**不再开一层 `data` 的理由**：它会和 `database` 抢同一件事，违反项目一贯的「一件事一个主人」。层数已 17，加层不是成就，是负债。原位升级后，`log → data → security → …` 的装配顺序不变，其它层的 `requires` 只是把 `database` 改成 `data`。

---

## 1. 升级清单

| 项 | v0.13 | v0.14 |
| --- | --- | --- |
| 层名 / 目录 | `database` / `systems/Database` | `data` / `systems/Data` |
| 职责 | 连接 + `query/exec` + 建表兜底 | 连接与严格模式 + 事务会话 + 查询观测 + 孤儿巡检 |
| 容器注册项 | `DatabaseInterface` | 新增 4 项（见第 4 节），`contracts` 18 → **22** |
| 系统层数 | 17 | **17**（未新增） |
| `core/Contracts/` | 13 个文件 | **13 个文件**（未新增，新接口都在 `systems/Data/`） |
| 表 | 24 张 | **24 张**（migrations 定义，未新增业务表） |

装配件 `DataSystem::register()` 只做「注册单例」，不认识任何业务表；`boot()` 里的建表兜底仍由各归属系统自己执行。**核心零改动**——这次升级没有碰 `core/` 里任何一个文件。

---

## 2. 四个维度：现状、落地与反例

四个维度都不是「补一个功能」，而是补**一道边界**。每节末尾的反例列是刻意的：写清楚「什么做法看起来能跑、实则埋雷」，比写十条正确做法更有用。

### 2.1 数据加固（integrity）

| 落地 | 实现位置 | 反例 |
| --- | --- | --- |
| 全部 prepared statement，注入面为零 | 12 个 `Pdo*` 仓储 | 拼接用户输入进 SQL |
| 20 个二级索引 / 5 个 UNIQUE / 游标分页 | `migrations/*.sql` | `OFFSET` 深分页 |
| **显式 `sql_mode`**：`STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | `PdoDatabase::SQL_MODE` + `MYSQL_ATTR_INIT_COMMAND` | 依赖服务器默认值——宽松模式下超长字符串会被**静默截断**，等于静默改变用户意图 |
| 两处 `SELECT *` 改显式列 | `PdoContentRepository`、`PdoStateStore` | `SELECT *` 会让「加一列」直接变成「改一次接口形状」，且新列默认出站 |
| 结构指纹：迁移内容没变时冷启动不重放 DDL | `Schema::apply()` + `MigrationRunner` 末尾 `Schema::invalidate()` | 每次启动跑 20+ 条 `CREATE TABLE IF NOT EXISTS`；或迁移改了结构却因指纹未作废而漏建 |

**有意不做外键**：跨系统解耦是设计选择，外键会把「卸载一个系统」变成跨库 DDL。替代方案是第 5 节的**登记 + 巡检**。

### 2.2 传输效率（efficiency）

| 落地 | 实现位置 | 反例 |
| --- | --- | --- |
| 表情目录 `findMany(array $codes)`，`EmojiParser::parse()` 先扫全量短码再一次性取 | `EmojiCatalogInterface` + 两个实现 | 对每个 `:code:` 调一次 `find()` —— 一段正文 N 个短码就是 N 条 SQL |
| `EmojiParser::parseMany()` + `emoji.parse_many` 钩子，列表整页只查一次目录 | `EmojiSystem` / `ContentSystem::presentMany()` | 逐篇 `parse()`，延迟随「页面条数 × 短码种类」线性劣化 |
| `QueryProfiler`：覆盖 `prepare→execute` 而非只数 `query/exec` | `CountingPdo` + `ProfiledStatement` | 用日志抽查——日志是「有没有异常」，计数器才能断言「恰好 N 条」 |
| 附件按 SHA-256 内容寻址去重 | `AttachmentStorage` | 同一字节重复落盘 |
| 游标分页（keyset） | 内容仓储 | `LIMIT OFFSET` |

**为什么需要计数器**：没有它，「列表页打了几条 SQL」根本无法断言，效率就只是口头承诺。`QueryProfiler` 保持请求级内存态、可 `reset()`，慢查询阈值 50ms、留样 50 条。

### 2.3 数据安全（egress）

| 落地 | 实现位置 | 反例 |
| --- | --- | --- |
| **出站显式白名单**：`present()` 列出允许字段，不 `unset` 敏感字段 | `CommentOperations::present()` | 原样返回仓储的物理行 —— 会把 `ip_hash`、`author_id`、`path` 一起送出站，而接口文档根本没声明这几个字段 |
| 密码 `password_hash`、令牌只存 SHA-256、IP 只存不可逆指纹 | Security / Comment | 明文或可逆哈希 |
| 巡检 SQL 的表名/列名全部经正则形状校验 | `RelationRegistry::declare()` | 把关系名拼进 SQL —— 直接开一个注入口 |
| 附件下载每次重新授权 + `nosniff` / `CSP` | `AttachmentRoute`（见 `SPEC.md` 第 8 节） | 把「链接发出去过」当作授权结果 |

**「列分级」没有做成框架级能力**，只在出站口用白名单兑现。理由：真正的分级要每个仓储声明每个列的级别，成本高且容易腐化；而**出站口是唯一必须正确的位置**，把守这里收益最大。已知限制：`present()` 需要人工维护，新增契约字段时若忘记加会被守卫挡下（`21c` 断言「字段集恰好等于清单声明」）。

### 2.4 数据操作（consistency）

| 落地 | 实现位置 | 反例 |
| --- | --- | --- |
| `DataSession::transaction()`：嵌套走 `SAVEPOINT`，异常只回滚到对应层 | `DataSession` | 全项目只有 1 处事务（Member 档案）；评论 `add()` → `update(path, root_id)` 在事务外，第二条失败就留下 `path=''` 且已对外可见的坏行 |
| `afterCommit()`：写文件 / 发事件排在提交之后 | `DataSession` | 库回滚了、文件已落盘 —— 无法撤销的半成品 |
| 只对死锁（1213）重试，其余异常不重试 | `DataSession::isRetryable()` | 对所有 `PDOException` 重试，把参数错误放大成 N 次写 |
| 父评论不可见时**整枝隐藏**，不提升为根 | `CommentOperations::pruneHidden()` | 父 `pending/hidden`、子回复已发布 → 用户看到一条「凭空出现、没有上文」的评论 |
| 迁移幂等 + 每个迁移带 `.down.sql` | `migrations/` | 重复执行直接失败 |
| 软删（tombstone）保留对话结构 | `CommentPolicy` | 硬删让子回复变成孤儿 |

**父缺失与父不可见必须分开**：父行根本不存在是脏数据，交给 `CommentTree::build()` 提升为根是合理兜底；父行存在但当前视角看不到，说明这条回复不该出现，`pruneHidden()` 直接整枝过滤。

---

## 3. 三道边界（本轮最核心的结论）

四个维度看起来是四件事，实际缺的都是同一类东西——**边界**。规矩 S 把它们固化成三句话：

1. **物理列不出站**：仓储返回物理行，operation 按契约裁剪；用「列出允许的」而不是「删掉敏感的」，这样加列时才不会默认泄漏。
2. **多语句必进事务**：凡是「先插入、再回填」或跨表写，两句必须同生共死；无法回滚的副作用走 `afterCommit()`。
3. **列表查询必须有上限且必须批量**：每条列表查询带 `LIMIT`；一段 / 一页正文的关联取数一次完成，不允许随条数线性放大。

> 这三句话对应 `21c`、`21a`、`21b` 三组守卫。规矩写在 `CONVENTIONS.md`，每条都有可执行断言，不是文档承诺。

---

## 4. 四个新注册项

`DataSystem::register()` 向容器登记，全部是 `systems/Data/` 内的类型，`core/Contracts/` 一个文件没加：

| 注册项 | 类型 | 职责 | 谁用 |
| --- | --- | --- | --- |
| `QueryProfilerInterface` | 接口 | `count / totalMs / slow / reset`，只观测不改变执行 | `CountingPdo`、守卫 `21b` |
| `DataSessionInterface` | 接口 | `transaction / afterCommit / inTransaction / depth` | `CommentWriter`、守卫 `21a` |
| `IntegrityCheckerInterface` | 接口 | `inspect(): array`，只读巡检 | `tools/data.php`、守卫 `21d` |
| `RelationRegistry` | 具体类 | `declare / all / count`，登记「子表.列 → 父表.列」 | 三个业务系统 `boot()`、巡检 |

`DatabaseInterface` 保持不变——**12 个业务仓储零改动**，这是「契约与实现可分离」的直接兑现：升级的是实现与旁边的协作者，不是契约本身。

---

## 5. 关系登记与孤儿巡检

数据层不认识「评论」是什么，它只认识关系形状。登记动作由**表的归属系统**在 `boot()` 完成：

| 关系名 | 形状 | 登记方 |
| --- | --- | --- |
| `comment.parent` | `comments.parent_id → comments.id`（0 视作无父） | `CommentSystem` |
| `comment.root` | `comments.root_id → comments.id` | `CommentSystem` |
| `emoji.attachment` | `emojis.attachment_id → attachments.id` | `EmojiSystem` |
| `member.profile_user` | `member_profiles.user_id → user_accounts.id` | `MemberSystem` |

```bash
php tools/data.php verify   # 只读巡检：子行指向不存在的父行；只报告不修复；退出码 0/1
php tools/data.php stats    # 各表行数（information_schema 估算值）
```

**巡检只报告、不自动修复**：孤儿行往往意味着上游删错了或漏了补偿，自动删会把「可调查的事故」变成「无声的数据丢失」。表尚未建立（未迁移 / 已卸载）时跳过并明确标记 `skipped` —— **「没验证」不等于「通过」**，这是 `21d` 专门守的一条。

**已知限制**：`COUNT(*)` + `LEFT JOIN` 是全表扫描，表大了要离线化；它只查库内引用，**附件字节文件是否缺失不在巡检范围**（那是文件系统侧的事）。

---

## 6. 明确不做

| 不做 | 理由 |
| --- | --- |
| ORM | 会吃掉 `core/` 的 24 文件预算，且把「SQL 归各系统层自持」这条设计推翻 |
| 数据库外键 | 跨系统解耦是有意的，用登记 + 巡检替代；外键让卸载变成跨库 DDL |
| 通用仓储（Generic Repository） | 它必须知道所有表，立刻变成上帝对象，违反职责预算与「一件事一个主人」 |
| 自写备份 / 导出 / 导入 | `mysqldump` 比我们写得好；导出是全库泄露面，不进 `tools/` |
| 自动修复孤儿 | 见第 5 节 |
| 读写分离 / 连接池 / 查询缓存 | 原型阶段无必要；`QueryProfiler` 已把「观测」这一步先立住，将来要加有落点 |
| 病毒扫描 / 图片二次编码 | 仍列在 v1.0，不假装完成 |

---

## 7. 守卫（14 项新增，全部可重跑）

| 组 | 断言 | 守的是哪条边界 |
| --- | --- | --- |
| `21a` 数据会话 | 事务内抛异常零残留；嵌套 savepoint 只回滚内层；`afterCommit` 回滚时丢弃、提交时执行一次 | 多语句必进事务 |
| `21b` 数据效率 | 单段多短码只问目录一次；批量解析 N 段只问一次；查询计数器计数 / 耗时 / 慢查询留样 | 列表必须批量 |
| `21c` 数据出站 | 不含 `ip_hash / path / author_id / deleted_at`；字段集**恰好等于**契约声明；列表与树同一份呈现 | 物理列不出站 |
| `21d` 数据完整性 | 关系登记拒绝 SQL 片段；注入脏行能报出且只报不修；内容未变时冷连接不重放 DDL | 加固与巡检 |
| `20a`（补 1 项） | 父评论被隐藏时回复整枝隐藏、不被提升为根 | 一致性 |

加上原有 226 项，全量 **240 passed, 0 failed**（47 个分组文件）；职责审计 **0 违规 / 0 孤儿协作**。

---

## 8. 一句话

**升级的不是「多了一个系统」，而是「四件事终于有了主人」，且没有为此新增一个系统层、一个核心文件、一张业务表。**

关联文档：[架构第 30 节](ARCHITECTURE.md) · [规矩 S](CONVENTIONS.md) · [接口规范](api/SPEC.md) · [内容互动三件套](CONTENT.md)
