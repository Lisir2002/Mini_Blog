# API-METRIC-001 · 站点统计概览

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-METRIC-001` |
| 接口名称 | 站点统计概览 |
| 所属系统层 | `metric` |
| 请求方法与路径 | `GET /api/v1/stats/overview` |
| 绑定操作 | `stats.overview` |
| 鉴权级别 | `admin` |
| 所需权限 | `metric.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.26.0` |
| 操作文档 | `docs/api/API-METRIC-001.md` |

## 2. 功能与适用场景

返回窗口内的请求量、5xx/4xx、错误率、耗时分位与接口调用概览，并附带文章 / 评论 / 账号存量与采集器健康状态。

**适用场景**：后台仪表盘首屏、值班巡检、故障后判断「影响面多大」；窗口支持 1h / 6h / 24h / 7d / 30d。

**实现备注**：只读业务数据；唯一副作用是把 metric.collect 的存量写成 gauge 采样。统计按小时聚合、非单请求明细。durable=false 表示库不可用、数据只在本进程内有效，不能当历史账本。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `window` | query | 否 | `string` | 时间窗口，形如 1h / 24h / 7d，最长 30d，默认 24h |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-METRIC-001", "version": "v1", "system": "metric"}
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
| `window` | `object` | 实际生效的窗口：key / since / hours / seconds |
| `traffic` | `object` | requests / errors / client_errors / error_rate / rps / latency（p50·p95·p99·avg·samples·approx） |
| `api` | `object` | calls / failures / failure_rate / top（调用量前十的 operation） |
| `data` | `object[]` | 业务存量采样：name / labels / value，由各系统层通过 metric.collect 自报 |
| `collector` | `object` | durable / driver / degraded / last_error / buffered / rejected / bucket |

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
GET /api/v1/stats/overview?window=24h
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
        "traffic": {
            "requests": 1280,
            "errors": 3,
            "client_errors": 42,
            "error_rate": 0.0023,
            "rps": 0.0148,
            "latency": {
                "p50": 10,
                "p95": 50,
                "p99": 100,
                "avg": 18.42,
                "samples": 1275,
                "approx": true
            }
        },
        "api": {
            "calls": 1102,
            "failures": 6,
            "failure_rate": 0.0054,
            "top": [
                {
                    "operation": "content.posts",
                    "calls": 320,
                    "failures": 0
                }
            ]
        },
        "data": [
            {
                "name": "content_posts",
                "labels": {
                    "status": "published"
                },
                "value": 12
            }
        ],
        "collector": {
            "durable": true,
            "driver": "mysql",
            "degraded": false,
            "last_error": "",
            "buffered": 0,
            "rejected": 0,
            "bucket": "hour"
        }
    },
    "request_id": "3f1c9a2b7d4e8061",
    "meta": {
        "api": "API-METRIC-001",
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
