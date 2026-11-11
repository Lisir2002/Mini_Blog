# 角色授权页（`/console/roles`）

> 把「改授权只能靠 curl」变成能在站点上完成的事，同时不把权力放大。

## 为什么需要它

`permission.roles` / `permission.grant` / `permission.revoke` / `permission.role.parent`
在接口层早就齐了，站点上却没有任何入口。结果是：站长能看到「编辑」这个角色，
但想知道它到底能做什么、想给某个角色补一个许可，只能自己拼 `POST /api/v1/permission/grant`
并手写 Bearer 令牌。

这一页只做授权本身，不做角色生命周期。角色由系统层播种（`PermissionSeeder`），
页面不新建、不删除 —— 理由见下面「不存在的角色」一节。

## 读什么

| 接口 | 用途 |
| --- | --- |
| `permission.roles` | 角色、直接许可、父角色、祖先、有效许可 |
| `permission.catalog` | 可授权的权限点清单（48 个，由各系统层 `permissions.json` 自报） |

页面把**直接许可**、**继承自**、**有效许可**分成三行显示。这不是排版偏好：
继承语义是「许可并集」，在子角色上撤销撤不掉父角色给的许可。只显示一个合并集合，
管理员会以为撤销生效了，实际决策时那条许可还在。

## 写什么

| 动作 | 接口 | 说明 |
| --- | --- | --- |
| `grant` | `permission.grant` | 授予直接许可 |
| `revoke` | `permission.revoke` | 删除直接许可（不影响继承） |
| `set_parent` | `permission.role.parent` | 设父角色，写入前裁决自环 / 成环 / 深度 |
| `remove_parent` | `permission.role.parent`（`remove=true`） | 解除继承 |

页面只声明 `permission.read`。写操作在 `RolesAction` 里再问一次 `permission.manage`：
页面能直接调执行者，绕过了 API 分发器的权限检查，不补这一道就等于「能看授权」=「能改授权」。
没有 `permission.manage` 时只渲染表格，不渲染必然 403 的按钮。

## 页面挡住的三类脏数据

这三类接口本身都不报错，只在页面上拦：

1. **清单外的权限点**。`PdoPermissionStore::grant()` 直接 `INSERT IGNORE`，不查清单。
   写进 `content.nope` 会返回 `changed=true`，页面显示「已授予」，但决策时永远不命中。
   页面先在 `permission.catalog` 里查名字，查不到就 422 并说明「写错的授权不会报错，但永远不命中」。
   通配符 `*` 是唯一例外 —— 它是 `AclMatcher` 的合法语义，不在清单里。
2. **不存在的角色**。授权给 `nosuch` 会写出一条孤儿许可：`permission_grants` 有行，
   `permission_roles` 没有这个角色，列表里看不见它，也没有任何入口能清理。
   页面先比对角色表，不存在就 422。
3. **成环的继承**。`RoleGraph::canSetParent()` 会拒，但异常消息是 `继承关系被拒绝：cycle`。
   页面把 `self` / `cycle` / `depth` 翻成人话，并保留原始 reason 供对账。

## 高危操作要手写角色名

改 `admin` 角色、或动通配符 `*`，都要求把角色名手写进「确认角色名」输入框。

原因很直接：撤销 `admin` 的 `permission.manage`（或 `*`）之后，**这个页面自己也进不去了**。
这不是理论风险 —— `53` 组守卫最后一条用例就是先确认、再撤销，然后断言同一个会话
访问 `/console/roles` 变成 403。确认框不是装饰，它对应的后果是真实存在的。

确认仍然允许执行，不硬性禁止：站长确实可能想换掉整套授权模型，
禁止会逼他去改库，那更危险。

## 「没有变化」要说清是哪一种

`permission.grant` / `revoke` 返回的 `changed` 是布尔事实，不是成功与否。
页面把 `changed=false` 分成三种文案：

| 场景 | 文案 |
| --- | --- |
| 重复授予 | 「editor 本来就有直接许可 X，没有变化。」 |
| 撤销继承来的许可 | 「editor 本来就没有直接许可 X —— 它来自继承或通配符。撤销只删直接许可，要收回得改父角色。」 |
| 解除不存在的继承 | 「editor 本来就没有继承 X，没有变化。」 |

一句笼统的「没有变化」会让管理员分不清是重复提交、还是被继承挡住了 —— 后者需要去改父角色，
是完全不同的下一步。

## 边界

- 不新建 / 删除角色：角色是权限模型的骨架，增删会牵动所有持有该角色的账号；
  目前没有 `permission.role.create` 接口，页面也不该造一个隐式约定。
- 不编辑资源 ACL（`permission.acl.set` / `permission.acl.list` 仍零消费）：
  字段级、单篇级的授权需要先有「选中某篇文章」的浏览路径，属于独立一版。
- 不做用户组（`permission.groups` / `permission.group` 零消费）：组是角色来源的扩展，
  和「谁能进后台」不是同一个问题。
- 不做决策轨迹：`permission.decisions` 需要 `permission.audit.read`，
  是只读审计面，单独一页。
- 页面仍只产区块数据；表单样式、布局全部来自主题。

## 守卫

`tests/groups/53-roles-runtime.php` 从 HTTP 面验证 14 条：匿名 401、
管理员可见、清单展开与 `permission.catalog` 一致、授权/撤销真的改库、重复提交标零变化、
清单外权限点被挡（并证明接口本身不挡）、不存在角色被挡、继承生效、成环被拒且关系不变、
撤销继承来的许可说明来源、高危确认、确认后自锁真实发生、普通账号 403。
