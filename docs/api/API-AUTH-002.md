# API-AUTH-002 · 创建页面会话

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-AUTH-002` |
| 接口名称 | 创建页面会话 |
| 所属系统层 | `security` |
| 请求方法与路径 | `POST /api/v1/auth/session` |
| 绑定操作 | `auth.session.create` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.9.0` |
| 操作文档 | `docs/api/API-AUTH-002.md` |

## 2. 功能与适用场景

校验用户名与口令后签发会话令牌，并以 HttpOnly + SameSite=Lax 的 Cookie 下发；响应体不返回令牌明文。

**适用场景**：浏览器登录后台：一次登录后页面 GET 自动携带会话，无需前端保存 Bearer 令牌。

**实现备注**：会话令牌与 API 令牌共用 wx_user_tokens（kind=session 区分），同一套过期与撤销语义。令牌明文只进 HttpOnly Cookie，响应体被安全层移除，脚本读不到。Cookie 仅用于页面 GET，API 写操作仍要求 Authorization 头，因此无 CSRF 面。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `username` | body | 是 | `string` | 用户名 |
| `password` | body | 是 | `string` | 明文口令，仅本次请求出现，不落日志 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-AUTH-002", "version": "v1", "system": "security"}
}
```

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `code` | int | 业务码，`0` 为成功；非 0 为可分支的机器可读错误码 |
| `message` | string | 面向人的简要说明 |
| `data` | any | 成功时的业务数据；失败时恒为 `null` |
| `request_id` | string | 请求标识，来自 `X-Request-Id` 或服务端生成 |
| `errors` | array | 字段级校验明细，仅错误响应出现 |
| `detail` | string | 仅当 `config.api.expose_errors=true` 时出现，禁止在生产开启 |
| `meta` | object | 接口编号、版本、所属系统层 |

**data 字段说明**：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `expires_at` | `string` | 会话过期时间 YYYY-MM-DD HH:MM:SS |
| `role` | `string` | 会话角色 |
| `kind` | `string` | 固定为 session |
| `max_age` | `int` | Cookie 有效期（秒），与令牌过期一致 |
| `cookie_name` | `string` | 固定为 wx_session，由安全层写入 Set-Cookie |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40102` | 401 | 令牌无效或已过期 |
| `40302` | 403 | 写接口未开放公网访问 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50101` | 501 | 接口已登记但执行者未实现 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/auth/session  {"username":"admin","password":"***"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "request_id": "1a2b3c4d5e6f7081",
    "data": {
        "expires_at": "2026-11-09 14:30:00",
        "role": "admin",
        "kind": "session",
        "max_age": 7200,
        "cookie_name": "wx_session"
    },
    "meta": {
        "api": "API-AUTH-002",
        "version": "v1",
        "system": "security"
    }
}
```

## 7. 幂等性与副作用

| 项 | 值 |
| --- | --- |
| 幂等 | 否 |
| 副作用 | 无 |
| 重试建议 | 不可盲目重试；请先查询单元状态，或携带幂等键后由服务端去重。 |

写操作建议携带 `Idempotency-Key`：服务端已实现去重（同键同参数重放首次结果，同键不同参数返回 `40902`）；参数校验失败不写入缓存，429 响应不被缓存。写接口默认仅本机来源可调用（`config.api.expose_write`）。

## 8. 变更记录

- 0.9.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
