# 登录与账号自助

v0.34 之前，会话能力是齐的：`auth.session.create/current/revoke`、`auth.register`、
`auth.password.change`、`auth.logout`、`users.me.update` 全部已实现，页面守卫也早已能读
`wx_session`。但 `pages/` 下没有一个认证入口 —— 后台 401 写着「需要登录」，而浏览器里
没有任何页面能完成登录，只能手工构造 `POST /api/v1/auth/session`。

这一版补的是门本身，不是新的身份体系。

## 1. 三个入口

| 路径 | 页面包 | 消费的 operation | 布局 |
| --- | --- | --- | --- |
| `/login` | `pages/Auth` | `auth.session.create`（经安全层接缝） | `site` |
| `/register` | `pages/Auth` | `auth.register`、`auth.session.create` | `site` |
| `/account` | `pages/Account` | `users.me`、`auth.session.current`、`users.me.update`、`auth.password.change` | `site` |

登录与注册共用 `AuthPage`：两条路由的状态机几乎相同，拆成两个包只会让「注册关闭时怎么表现」
出现两套写法。账号页单独成包，因为它的写路径要票据、读路径要补全身份。

## 2. 会话怎么建立

1. 表单 `POST /login`，字段只有 `username` / `password` / `next`；
2. 页面调 `PageSession::login()`：先按来源限流（默认 10 次 / 300 秒），再执行
   `auth.session.create` —— 与 API 登录共用同一个 `attemptLogin()`，锁定、禁用、口令策略完全同源；
3. 成功：`303` + `Set-Cookie: wx_session=…; Path=/; HttpOnly; SameSite=Lax`，正文为空；
4. 失败：`401` / `429`，页面重绘表单并保留用户名，**不写任何 Cookie**，也**绝不 303**。

`next` 只接受站内绝对路径：以 `/` 开头且不以 `//` 开头，且不能是 `/login` / `/register`
本身；其余一律回落到 `/account`。这样既不开放重定向，也不会把「没给 next」当成错误。

## 3. 会话 Cookie 的边界

| 属性 | 值 | 为什么 |
| --- | --- | --- |
| `HttpOnly` | 是 | 脚本读不到令牌，XSS 拿不走会话 |
| `SameSite` | `Lax` | 跨站 POST 不带 Cookie；登录页的正常跳转仍可用 |
| `Path` | `/` | 页面与会话接口同源 |
| `Max-Age` | `security.session.ttl`（默认 7200） | 与会话表过期时间一致 |

Cookie 只用于页面 **GET**。页面的非 GET 请求仍不读 Cookie —— 写路径靠表单票据证明身份，
这条边界从 v0.9 起没有松动。API 写操作仍然只认 `Authorization` 头。

## 4. 失败语义

| 场景 | HTTP | 业务码 | 页面表现 |
| --- | --- | --- | --- |
| 口令错 | 401 | 40105 | 「用户名或口令不正确」，保留用户名，不写 Cookie |
| 账号锁定 | 429 | 42902 | 写明剩余秒数 |
| 账号禁用 | 401 | 40103 | 「账号已被禁用」 |
| 来源限流 | 429 | 42901 | 写明重试秒数 |
| 注册关闭 | 403 | 40304 | GET 阶段就直接显示「注册未开放」，不渲染表单 |
| 用户名占用 | 409 | 40907 | 「用户名已被占用」 |
| 两次口令不一致 | 422 | 42201 | 页面层比对，不浪费一次接口调用 |
| 旧口令错 | 401 | 40105 | 「旧口令不正确」 |
| 数据库未接入 | 503 | 50004 | 「数据库未接入，当前无法登录或注册」，不渲染表单 |

GET `/register` 会先读 `user.registration.enabled`：关闭时直接给「未开放」，不显示一个
提交后必然 403 的表单。POST 仍由接口再判一次 —— 页面判断只是体验，不是边界。

## 5. 账号页的票据

`/account` 没有权限点可挂（看自己的资料不该需要额外授权），但写操作仍要防 CSRF。
票据因此支持一个显式的「无权限点」标记：

- 签发：`permission === ''` 编码为 `p = "-"`；
- 验签：期望值同样换成 `"-"`，与普通票据走同一条 `hash_equals`；
- 空串仍然非法 —— 否则「字段缺失」会与「本页不需要权限」混成同一种状态。

票据绑定 `(user_id, username, role, path=/account, exp)`。它不能拿去提交 `/console/queue`：
路径与权限点两处都对不上。GET 时签不出来（`storage/form-ticket.key` 不可写）就不渲染写表单，
并在页面上写明原因 —— 不渲染一个点了必然 401 的按钮。

## 6. 改密与登出

改密的语义是「止损」：旧口令立即失效，**当前会话保留**，其他设备全部撤销。页面拿到的票据
只证明「谁在提交」，不含 `token_hash`；因此提交前由安全层把当前 Cookie 的摘要补回身份
（`PageSession::withSessionHash()`），否则「保留当前会话」会退化成「把自己也踢下线」。

登出由 `PageSession::logout()` 完成两件事：撤销当前会话令牌（`kind='session'`）并清 Cookie。
令牌不存在于表中时仍然清 Cookie，但不会假装撤销成功。`auth.session.revoke` 接口保留给
API 客户端；页面走的是同一个 `SessionManager::revoke()`，不再多一次「先认证再撤销」。

## 7. 限流

页面登录有独立的文件限流桶 `page-login|<ip>`，默认 10 次 / 300 秒，与 API 的通用限流分开：
API 挡的是「整体请求量」，这里挡的是「同一来源轮着猜很多账号」。账号自身的失败计数与锁定
仍然生效，两层互不替代。限流存储不可用时放行但留痕（`trace`），不把登录整站打死。

## 8. 不做的事

- 不做找回口令 / 邮箱验证 / 验证码：系统没有邮件与短信通道，做了也只能是假入口；
- 不做「记住我」：会话 TTL 由配置统一控制，长短两套寿命会让撤销语义变复杂；
- 不做前端校验脚本：表单零脚本可提交，错误由服务端统一返回；
- 不做头像上传：附件系统还没有「只允许图片」的写路径与配额。

## 9. 验证

```bash
php tests/run.php                       # 50 / 50b 两组守卫
php tools/consumers.php --zero          # auth.register / users.me 等已离开零消费
php tools/facts.php verify              # 文档计数与错误码对账
php tools/audit-responsibility.php      # 职责预算
```
