# 决策轨迹页（`/console/decisions`）

> 解释「这一次请求为什么被允许」，并明确说自己不是审计日志。

## 为什么需要它

权限系统一直在做决策：`AccessController::decide()` 每次都会产出一条
`actor / action / resource / decision / reason`，写进 `permission_audit` 表，
同时留在进程内的 `recent` 环里。`permission.decisions` 与 `permission.check`
两个接口也早就存在，但站点上没有任何地方能看到这些结果。

结果是：站长遇到「为什么这个人能改那篇文章」时，只能去翻库或加日志。

## 读什么

| 接口 | 返回 | 性质 |
| --- | --- | --- |
| `permission.decisions` | 进程内最近 100 条决策 + 缓存统计 | **快照，不是历史** |
| `permission.check` | 对单个 `(action, resource, field)` 的现场裁决 | 只读，不写库 |

### 为什么必须先 check 再 decisions

`permission.check` 自己也会产生一条决策。如果先取快照再裁决，页面上就看不到
「刚才这次裁决」——而它正是用户点按钮想看的那一条。所以顺序固定为：
`check` → `decisions`，决策链最后一行就是刚才的结果。

## 三态必须分开

`decide()` 的返回值有三种，不是两种：

| 结果 | 含义 | 页面呈现 |
| --- | --- | --- |
| `allow` | 有角色或 ACL 明确允许 | 允许（ok） |
| `deny` | 显式 deny 或策略钩子收紧 | 拒绝（warn） |
| `abstain` | 没有任何角色或 ACL 命中 | 不表态（info） |

`abstain` 不是拒绝。它通常意味着**动作名写错了**，或这个动作根本不在
`permissions.json` 清单里 —— 也就是说「你问了一个系统不认识的问题」。
把它渲染成拒绝，排查就失去了入口：管理员会去查授权，而真正的问题在拼写。

页面对 `abstain` 明确写出：「常见原因：动作名写错，或这个动作不在权限清单里」。

## 「不是历史」要说清

这是本页最重要的一句边界：

> `permission.decisions` 返回当前进程内最近 100 条。PHP-FPM 下一个请求一个进程，
> 所以它通常只包含你这一次请求的判定；常驻进程（测试、CLI）下会跨请求累积。

历史留痕在 `permission_audit` 表里，由 `PdoPermissionStore::audit()` 写入，
本页**不查库**（页面只调接口），所以没有历史查询入口。

不写这句的后果很具体：页面只显示两三条时，看起来像「站点没人用过权限」，
而实际上每一次请求都在写留痕。零状态的两种含义（真的没有 / 只看得到一部分）
必须分开，这是全仓「不能静默出错」的同一条规矩。

## 一个已知的错配（本轮不修）

页面声明 `auth: admin` + `permission.audit.read`。但默认角色许可里
`editor` 持有 `permission.read`、`user.read`、`content.*`、`page.console.read` ——
看上去编辑应该能进后台，实际上进不去：**页面级 `auth: admin` 比权限点更严**，
它只认 `role === 'admin'`。

于是「给 editor 授权」目前只影响接口调用与决策结果，不影响页面可达性。
这是两套边界的叠加，不是 bug，但会让人误解：

- 接口层：权限点说了算（`editor` 能调 `users.list`）；
- 页面层：先过 `auth=admin`，再过权限点（`editor` 进不了 `/console/users`）。

要不要把后台页统一改成 `auth: user` + 权限点控制，是一次涉及全部后台页面
与守卫断言的安全边界调整，应该独占一版做，不在补页面的版本里顺手改。

`54` 组守卫里有一条用例专门记录这个现状，注释写明「这是现状，不是期望」。

## 边界

- 不做历史查询：需要新接口（或 `logs.query` 支持按表查），本页不查库。
- 不替别人裁决：`permission.check` 用的是当前身份的 `_identity`，
  问「另一个用户能不能做 X」需要模拟身份，那会变成一条越权通道。
- 不改授权：本页只读。改授权在 `/console/roles`。
- 不做决策的图形化时间线：数据只有一行一条的环，表格足够。

## 守卫

`tests/groups/54-decisions-runtime.php` 从 HTTP 面验证 9 条：匿名 401、
非管理员 403（并记录 `auth=admin` 的边界）、决策链含本次 route.access 判定、
允许 / 拒绝 / 不表态三态可分（拒绝用 `post:99` 的显式 deny 证明压过通配符）、
空动作不 500、超长动作有提示、边界文案在位。
