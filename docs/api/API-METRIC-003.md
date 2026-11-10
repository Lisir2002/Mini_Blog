# API-METRIC-003 · 接口操作统计

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-METRIC-003` |
| 接口名称 | 接口操作统计 |
| 所属系统层 | `metric` |
| 请求方法与路径 | `GET /api/v1/stats/operations` |
| 绑定操作 | `stats.operations` |
| 鉴权级别 | `admin` |
| 所需权限 | `metric.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.26.0` |
| 操作文档 | `docs/api/API-METRIC-003.md` |

## 2. 功能与适用场景

按 operation 汇总调用量、失败数与失败率，并给出 p50 / p95 / p99 与平均耗时。

**适用场景**：找出「最忙的接口」「一直在失败的接口」「慢但没报错的接口」；上线后对比操作级水位。

**实现备注**：分位数为桶上界近似（5 / 10 / 25 ... / 10000 / +Inf），响应不含 approx 字段的旧客户端只会看到上界值。失败按业务码非 0 统计，包含 4xx 与 5xx。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `window` | query | 否 | `string` | 时间窗口，默认 24h，最长 30d |
| `limit` | query | 否 | `int` | 返回条数，默认 50，上限 200；超出时 truncated=true |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-METRIC-003", "version": "v1", "system": "metric"}
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
| `total` | `int` | 窗口内出现过的 operation 总数 |
| `truncated` | `bool` | 是否因 limit 截断 |
| `items` | `object[]` | operation / calls / failures / failure_rate / p50 / p95 / p99 / avg_ms |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `42201` | 422 | 参数校验未通过 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/stats/operations?window=24h&limit=20
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "window": {
            "key": "24h",
            "since": "2026-11-09 23:00:00",
            "hours": 24,
            "seconds": 86400
        },
        "total": 1,
        "truncated": false,
        "items": [
            {
                "operation": "system.info",
                "calls": 12,
                "failures": 0,
                "p50": 5,
                "p95": 10,
                "p99": 10,
                "avg_ms": 4.21,
                "failure_rate": 0
            }
        ]
    },
    "request_id": "bc40e1a97d2f6358",
    "meta": {
        "api": "API-METRIC-003",
        "version": "v1",
        "system": "metric"
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

- 0.26.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
