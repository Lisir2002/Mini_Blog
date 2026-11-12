# API-PERM-011 · 契约覆盖授权台账对账

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-PERM-011` |
| 接口名称 | 契约覆盖授权台账对账 |
| 所属系统层 | `permission` |
| 请求方法与路径 | `GET /api/v1/permission/overrides` |
| 绑定操作 | `permission.overrides` |
| 鉴权级别 | `admin` |
| 所需权限 | `permission.audit.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.8.0` |
| 操作文档 | `docs/api/API-PERM-011.md` |

## 2. 功能与适用场景

把装配期实际发生的契约覆盖与运行时授权台账逐条对账，标出未授权覆盖。

**适用场景**：排查「某个契约为什么被替换了」；开启 require_grant 前先确认现有覆盖是否已登记授权。

**实现备注**：只读接口。require_grant=false 时所有覆盖都标记 authorized=true（保持「只留痕」语义）；拔库回退时 grants 为空数组且不伪造授权。 0.44.0 起 /console/acl 的覆盖台账面板只读展示 require_grant / unauthorized / grants，不提供授权按钮。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

**无请求参数。**

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-PERM-011", "version": "v1", "system": "permission"}
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
| `overrides` | `array` | Container 留痕的覆盖记录（contract/from/to/priority/authorized） |
| `grants` | `array` | 运行时登记的有效 / 已撤销授权台账 |
| `require_grant` | `bool` | 当前是否启用「覆盖必须先授权」 |
| `unauthorized` | `array` | require_grant=true 时未获授权的覆盖清单 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40302` | 403 | 写接口未开放公网访问 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/permission/overrides  Authorization: Bearer <admin-token>
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": [],
    "request_id": "1a2b3c4d5e6f7081",
    "meta": {
        "api": "API-PERM-011",
        "version": "v1",
        "system": "permission"
    }
}
```

## 7. 幂等性与副作用

| 项 | 值 |
| --- | --- |
| 幂等 | 是 |
| 副作用 | 无 |
| 重试建议 | 可安全重试，重复调用结果一致。 |

写操作建议携带 `Idempotency-Key`：服务端已实现去重（同键同参数重放首次结果，同键不同参数返回 `40902`）；参数校验失败不写入缓存，429 响应不被缓存。写接口默认仅本机来源可调用（`config.api.expose_write`）。

## 8. 变更记录

- 0.44.0 由 /console/acl 覆盖台账面板消费（只读）
- 0.8.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
