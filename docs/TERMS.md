# 分类与标签（`/console/terms`）

> v0.45 接通 `content.terms.manage` 与 `content.attach`，补上后台的分类 / 标签管理闭环；
> 同时把 `content.post` / `content.posts` 实际早已返回的 `terms` 字段写进契约 `response_fields`。
> 零新增接口、零新增系统层、`core/` 零改动。

## 1. 为什么后台一直没有入口

`content.terms`（读）此前只被 `pages/Archive/` 的前台归档页消费，用来画分类标签云。
两个写接口 `content.terms.manage`（术语增删改）与 `content.attach`（文章挂术语）长期零消费 ——
不是缺页面容器，是缺一条「选中某篇文章、看清它已经挂了什么」的浏览路径。

三段式路径与附件库、资源 ACL 同构：

```
选文章（content.posts, status=any, 最多 20 篇）
   → 复核文章并取出当前绑定（content.post 的 terms 字段）
   → 提交完整集合（content.attach）
```

术语清单由 `content.terms` 一次取全，页面按 `kind` 切换、最多显示 60 条并声明未列全。

## 2. 提交的是完整集合，不是增量

`content.attach` 做的是 **sync**：把文章的绑定整体替换成请求里的 `terms` 数组，
返回 `added` / `removed`。这和「再加一个标签」是两件事，页面必须把差别写在脸上。

| 操作 | 请求 `terms` | 结果 | 页面文案 |
| --- | --- | --- | --- |
| 首次挂载 | `category:tech` `tag:hot` | `added=2, removed=0` | 新增 2 条、移除 0 条 |
| 改集合 | `category:tech` | `added=0, removed=1` | 新增 0 条、移除 1 条 |
| 幂等重提 | `category:tech` | `added=0, removed=0` | `no-change`：集合本来就是这样 |
| 清空 | `[]` + 手写文章 slug 确认 | `removed=N` | 同时说明清空不是「什么都不做」 |

挂载框预填 `content.post` 返回的当前集合，删掉一行就是解绑。
一次请求里只要有一个引用不存在，整个操作失败，不会出现部分成功 —— 页面因此能在提交前
把「到底哪个引用拼错了」说清楚，而不是丢一个笼统的 40404。

## 3. 接口不校验的输入，页面先挡住

| 输入 | 接口层行为 | 页面层 |
| --- | --- | --- |
| 同 `kind` 下重复 `slug` | 40911，不静默复用 | 预查清单；明说复用会把「新建」变成「挂到别人的术语上」 |
| `tag:typo` 引用 | 40404，绝不自动建新词条 | 按 `content.terms` 逐项核对，拼错当场指出 |
| `update` / `delete` 传不存在的 `id` | 40404 | 按清单 ID 预查，提示「可能刚被删掉，刷新重取」 |
| 超长名称 / 说明 | 存储层按字符拒绝（422） | 名称 60 字、说明 200 字，按字符而非字节计 |
| `kind` / `slug` 想改 | 接口根本不提供改法 | 表单不提供该字段，明说改 slug 会让所有旧链接 404，要换就新建再迁移 |

删除术语会在同一事务里清掉它下面所有文章绑定，没有撤销入口，所以要求**手写术语 slug 确认**，
并把 `relations_removed` 的真实数字回报出来 —— 不写「已删除」了事。

**顺带修掉的两个实现缺陷**：

1. `ContentTermStore::update()` 原先无条件刷新 `updated_at`，同值提交只要跨秒就被
   MySQL 判为「已改变」，`exec()` 返回 1，接口于是报 `updated=true` —— 幂等与否
   竟取决于两次请求隔了几秒。现在先比对当前值，没变就不发 UPDATE；`61b` 组用
   秒翻转确认跨秒仍报 `updated=false`。
2. 页面「只改说明」时不能把空 `name` 传下去：存储层 `assertName('')` 会抛 422。
   现在 `description` 恒传、`name` 只在非空时传，清空说明仍然合法。

## 4. 顺手补上的契约缺口

`content.post` 与 `content.posts` 的实现一直会返回 `terms`（`ContentTermStore::forPost()` /
`forPosts()`），但 `API-CONTENT-001` / `API-CONTENT-002` 的 `response_fields` 里没有它。
页面要靠这个字段预填挂载框，契约却没承诺，属于「消费方在依赖一个没写下来的字段」。
v0.45 把 `terms` 与 `items[].terms` 补进两份契约并重生成 92 份文档与 OpenAPI。

## 5. 四种「看起来像空」的状态必须分开

| 状态 | 来源 | 页面说法 |
| --- | --- | --- |
| `empty` | `content.terms` 正常返回 `items=[]` | 「当前筛选下一个术语都还没有」，写入口可用 |
| `unavailable` | `content.terms` 返回 50101（内存降级） | 「分类与标签功能当前不可用」，**写入口关闭** |
| `failed` | 清单读取抛别的异常 | 显示原因，写入口关闭 |
| `unreadable` | `content.post` 40403 / 40301（回收站、定时未到点、无权） | 「读不到这篇文章」，不写「没有术语」，挂载入口关闭 |

还有一层：`content.post` 响应里若根本没有 `terms` 键，页面标成 `unknown` 并关闭挂载表单 ——
**字段缺失不是空集合**。清单读不到时不做任何写入：不知道有哪些术语，就没法核对引用，
给一个能提交的表单等于让人去撞 40404。

`post_count` 不含回收站文章，读者看到的标签云数字必须和点进去的列表条数一致；页面把口径写在表下。

## 6. 读写权限分离与 PRG

页面声明 `content.read`（能看），两个写动作在 `TermsAction` 里**分别**现场裁决：

| 动作 | 权限点 |
| --- | --- |
| 术语新建 / 改名 / 删除 | `content.manage_terms` |
| 文章挂载 `content.attach` | `content.edit` |

页面直调 `ApiRegistryInterface::execute()` 会绕过 API 分发器，所以这一层不能省；
`61b` 组把两个权限点分别撤掉验证「只读可见、写被 403」。
票据绑 `content.read` 与 `/console/terms`，只证明「谁从哪一页提交」，不替代写裁决。

成功一律 303 并带回 `saved / op / outcome / detail / hint`，失败绝不跳转、按 `form` 回填。
只有 `saved` 参数存在才显示提交结果 —— 普通 `?slug=` / `?kind=` 导航不该凭空报「已保存」。
`updated=false`、整体替换幂等、`changed=false` 一律标 `no-change` 并解释原因。

## 7. 边界

不做批量导入、不做术语合并、不做 slug 迁移工具；术语清单最多 60 条、文章窗口最多 20 篇，
都不是完整快照；内存降级模式下整页只做状态说明，不提供写表单；
`core/` 与系统层零改动，权限点仍 49 个。

## 8. 守卫

`61` 组 9 项覆盖空态、新建与 40911、四类非法输入、`updated=false`、kind 切换与 `post_count`、
整体替换（加 2 / 减 1 / 幂等）、引用预查与去重、清空确认、删除回报 `relations_removed`；
`61b` 组 6 项覆盖读不到时 fail-closed、两个写权限点分别隔离、方法边界与回填、
跨秒同值更新仍报 `updated=false`、50101 降级语义，共 15 项。
两组共用同一个隔离站点 —— 权限隔离必须在同一份已落库的数据上验，另起内核验不出「看得到但改不了」。

`49` 组新增断言：`content.terms.manage` / `content.attach` 离开零消费，且消费方落在 `pages/Terms/` 下。
零消费 **24 → 22**。
