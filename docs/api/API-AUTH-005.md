# API-AUTH-005 · 注册账号

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-AUTH-005` |
| 接口名称 | 注册账号 |
| 所属系统层 | `user` |
| 请求方法与路径 | `POST /api/v1/auth/register` |
| 绑定操作 | `auth.register` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.27.0` |
| 操作文档 | `docs/api/API-AUTH-005.md` |

## 2. 功能与适用场景

在站点开放注册时创建账号，校验用户名与口令强度，成功后直接签发令牌（注册即登录）。

**适用场景**：开放注册的站点让读者自助开户；或初始化后创建第二个账号，避免全站只有一个种子管理员。

**实现备注**：注册默认关闭（config user.registration.enabled=false），关闭时返回 40304 而不是 404 —— 「没开这个功能」与「没有这个接口」必须能区分。用户名冲突返回 40907，不是 50001：名字被占用是客户端能修正的状态。口令超过 72 字节会被直接拒绝，因为 bcrypt 会静默截断，后半段任意输入都能通过校验。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `username` | body | 是 | `string` | 3~32 位字母、数字或下划线，不能是纯数字 |
| `password` | body | 是 | `string` | 至少 8 位、不超过 72 字节，不能等于用户名或常见弱口令 |
| `display_name` | body | 否 | `string` | 显示名称，缺省等于用户名，最长 64 字 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-AUTH-005", "version": "v1", "system": "user"}
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
| `user` | `object` | id / username / display_name / role / status / created_at |
| `token` | `string` | 一次性返回的明文令牌 |
| `expires_at` | `string` | 令牌过期时间 |
| `token_type` | `string` | 固定为 Bearer |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40302` | 403 | 写接口未开放公网访问 |
| `40304` | 403 | 注册未开放 |
| `40907` | 409 | 用户名已被占用 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50101` | 501 | 接口已登记但执行者未实现 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/auth/register  {"username":"alice","password":"***","display_name":"Alice"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "user": {
            "id": 2,
            "username": "alice",
            "display_name": "Alice",
            "role": "subscriber",
            "status": "active",
            "created_at": "2026-11-11 00:58:12"
        },
        "token": "c3d4...(64 位)",
        "expires_at": "2026-11-11 01:58:12",
        "token_type": "Bearer"
    },
    "request_id": "4c5d6e7f80912345",
    "meta": {
        "api": "API-AUTH-005",
        "version": "v1",
        "system": "user"
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

- 0.27.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
