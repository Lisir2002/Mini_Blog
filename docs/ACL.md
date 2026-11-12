# 资源 ACL 与用户组（`/console/acl`）

> v0.44 接通 `permission.acl.set` / `permission.acl.list` / `permission.role.tree` /
> `permission.overrides` / `permission.groups` / `permission.group` / `users.view`，
> 并补上「选中某篇文章」的浏览路径。契约覆盖授权 `permission.override.grant` 刻意不接网页。

## 1. 为什么必须先选文章

`permission.acl.set` 挂在具体资源上（`type` + `id`），而 `permission.acl.list` 既没有按资源过滤的参数，
也只返回最近 100 条。所以「这篇文章有哪些规则」不是接口能给的答案，页面必须自己筛：

```
选文章（content.posts） → 复核文章存在（content.post） → 从 acl.list 按 type+id 筛出这一篇的条目
```

因此默认面板给的是文章清单，不是一张空的 ACL 表；页面也不写「这篇文章没有规则」，
而写「没有筛出这篇文章的 ACL 条目」，并明说三种可能：确实没有、条目被 100 条上限截掉、接口读不到。
本页不是完整快照，这一句话必须留在页面上。

## 2. 四个面板

| `?panel=` | 看什么 | 数据来源 |
| --- | --- | --- |
| `acl` | 某篇文章的 ACL 条目 + 追加表单 | `permission.acl.list` / `content.posts` / `content.post` / `permission.catalog` / `permission.roles` / `users.view` |
| `groups` | 用户组及角色 / 成员 + 六个动作 + 查账号 | `permission.groups` / `permission.roles` / `users.view` |
| `tree` | 角色继承树（parents / ancestors / effective_permissions） | `permission.role.tree` |
| `overrides` | 契约覆盖台账（require_grant / unauthorized / grants） | `permission.overrides` |

切换面板保留 `slug` / `user` / `group`：查完账号再回分组填成员，不该把刚查到的 ID 丢掉。
面板切换是 GET 链接，不依赖脚本，可收藏、可回退。

## 3. 追加不是编辑

`permission.acl.set` 是纯 INSERT，系统没有编辑或删除单条 ACL 的接口。
显式 deny 优先级最高，压过角色通配符与继承链 —— 新加一条 allow 不会把已有的 deny 抵消。
页面把「追加」写进标题和结果文案，不提供「撤销」「修改」按钮。
整资源级、对所有人、所有能力的显式 deny 会让管理员也进不来，必须手写文章 slug 确认。

`field` 非空只对同名字段生效，写错的字段名不会报错，只是永远不命中；页面把它限制在
`body / title / status / pinned / weight` 与留空（整资源级）之间。

## 4. 接口不校验的四类输入，页面先挡住

| 输入 | 接口层行为 | 页面层 |
| --- | --- | --- |
| `bogus:foo` 这类主体 | `parseSubject()` 把未知前缀静默解释成 `role`，写成永不命中的规则 | 严格枚举 `* / role:<已存在角色> / user:<已存在账号>` |
| `role:nobody` | 不校验角色是否存在 | 预查 `permission.roles` |
| `content.no-such-perm` | 不校验能力名，永不命中 | 预查 `permission.catalog`，另允许合法前缀通配 |
| `user:999999` | 不校验账号是否存在 | 预查 `users.view` |

`users.view` 因此被消费两次：`?user=` 查账号是读路径，写前校验是另一条路径。
`allow` 只能由「放行 / 拒绝」两个按钮给出，直接构造 POST 一律 422。

## 5. 用户组只是角色来源

组不复制授权表、不新增决策层：成员通过组获得的角色与自身角色取并集，
颜色与排序只是展示属性。页面在写前校验角色名与 `user:<id>` 是否真实存在，
删除组要求手写标识确认（会一并清理成员与角色绑定，且没有撤销入口）。

`changed=false` 分场景回报：重复绑定说「本来就已经绑定」，
移除本来就没有的绑定说「本来就没有这条绑定」—— 都不是「成功」，也不是「失败」。

## 6. 为什么覆盖台账没有授权按钮

`permission.override.grant` 是装配期动作，默认仅本机来源可调用（RequestGuard `40302`），
且需要 `system.contract.override`。页面直调 `ApiRegistryInterface::execute()` 会绕过 API 分发器，
把本机来源限制一并绕掉。所以这一版只读展示 `permission.overrides`，
写入口留在命令行；它继续列在零消费清单里是设计，不是遗漏。

## 7. 读写权限分离与 PRG

页面声明 `permission.read`，写动作在 `AclAction` 现场问 `permission.manage` ——
页面直调执行者会绕过分发器的裁决，票据只证明「谁从哪一页提交」，不替代写权限。
成功一律 303 回到原面板并保留 `panel/slug/group/user`，失败绝不跳转并回填表单；
只有 `saved` 参数存在时才显示提交结果，普通 `?panel=` 导航不会误报「已保存」。

## 8. 边界

不做批量导入、不做 ACL 影响面预演、不提供单条 ACL 的编辑或删除；
`acl.list` 的 100 条上限意味着本页不是完整快照；
组带来的角色仍受显式 deny 与策略收紧压制；覆盖授权不进网页。

## 9. 守卫

`60` 组 12 项：匿名 / 默认文章清单、选中文章空态与 100 条上限、追加字段级 deny、
四类脏输入、`user:1` 真实账号、`allow` 由按钮给出 + 高危确认、角色树 / 覆盖台账只读、
查账号两处消费、用户组落库与写错被挡、`changed=false` 分场景与删除确认、
读写权限分离（只留 read 时 GET 可读 POST 403）、方法边界与失败回填。
