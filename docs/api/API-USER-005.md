# API-USER-005 · 管理账号

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-USER-005` |
| 接口名称 | 管理账号 |
| 所属系统层 | `user` |
| 请求方法与路径 | `POST /api/v1/users/manage` |
| 绑定操作 | `users.manage` |
| 鉴权级别 | `admin` |
| 所需权限 | `user.manage` |
| 接口版本 | `v1` |
| 起始版本 | `0.27.0` |
| 操作文档 | `docs/api/API-USER-005.md` |

## 2. 功能与适用场景

对指定账号执行禁用 / 启用 / 改角色 / 重置口令，并在需要时立即吊销其全部令牌。

**适用场景**：离职或异常账号的停用；把某个成员提升为管理员；用户忘记口令时的管理员重置。

**实现备注**：两条防呆不变量都以 40906 拒绝：不能禁用当前登录账号（会把自己关在门外），不能移除最后一个管理员（会让站点再也没人能进管理端）。禁用与重置口令都会调用 AccountRevocation 立即吊销令牌，否则「禁用」只是登录被拒，旧令牌照样能调接口。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `user_id` | body | 是 | `int` | 目标账号 ID |
| `action` | body | 是 | `string` | disable / enable / set_role / reset_password |
| `role` | body | 否 | `string` | action=set_role 时的目标角色 |
| `new_password` | body | 否 | `string` | action=reset_password 时的新口令 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-USER-005", "version": "v1", "system": "user"}
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
| `user` | `object` | 操作后的账号公开字段 |
| `revoked_tokens` | `int` | 被吊销的令牌数（disable / reset_password） |
| `changed` | `bool` | set_role 是否真的改变了角色 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `40403` | 404 | 资源不存在 |
| `40906` | 409 | 会移除最后一个管理员或禁用当前账号 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |
| `50101` | 501 | 接口已登记但执行者未实现 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/users/manage  Authorization: Bearer <admin>  {"user_id":2,"action":"disable"}
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
            "display_name": "爱丽丝",
            "role": "subscriber",
            "status": "disabled",
            "created_at": "2026-11-11 00:58:12",
            "last_login_at": "2026-11-11 00:58:12"
        },
        "revoked_tokens": 1
    },
    "request_id": "a2345678abcdef01",
    "meta": {
        "api": "API-USER-005",
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
