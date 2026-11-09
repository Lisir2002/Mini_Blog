# API-AUTH-001 · 签发访问令牌

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-AUTH-001` |
| 接口名称 | 签发访问令牌 |
| 所属系统层 | `user` |
| 请求方法与路径 | `POST /api/v1/auth/token` |
| 绑定操作 | `auth.token` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.4.0` |
| 操作文档 | `docs/api/API-AUTH-001.md` |

## 2. 功能与适用场景

校验用户名与口令后签发落库令牌：库内只存 SHA-256 摘要，明文令牌仅在本次响应中出现一次，并带过期时间与撤销标记。

**适用场景**：管理后台登录、CLI 运维脚本取得 admin 令牌；后续受保护接口以 Authorization: Bearer 携带。

**实现备注**：口令校验用 password_verify()，库里只存 password_hash。演示账号 admin/admin123 仅供本地原型，投产前必须删除或改密。令牌表归安全系统层自持。 v0.9 起清单显式声明 public_write=true：登录端点必须公网可达，写接口默认「仅本机」的边界不为它开隐式例外。

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
  "meta": {"api": "API-AUTH-001", "version": "v1", "system": "user"}
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
| `token` | `string` | 一次性返回的明文令牌（64 位十六进制） |
| `expires_at` | `string` | 过期时间 YYYY-MM-DD HH:MM:SS |
| `role` | `string` | 令牌角色，admin / user |
| `token_type` | `string` | 固定为 Bearer |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40102` | 401 | 令牌无效或已过期 |
| `40302` | 403 | 写接口未开放公网访问 |
| `40902` | 409 | 幂等键重放参数不一致 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50101` | 501 | 接口已登记但执行者未实现 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/auth/token  {"username":"admin","password":"***"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "token": "a1b2...(64 位)",
        "expires_at": "2026-11-09 00:40:55",
        "role": "admin",
        "token_type": "Bearer"
    },
    "request_id": "1a2b3c4d5e6f7081",
    "meta": {
        "api": "API-AUTH-001",
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

- 0.4.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
