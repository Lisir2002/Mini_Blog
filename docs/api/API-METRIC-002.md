# API-METRIC-002 · 查询指标序列

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-METRIC-002` |
| 接口名称 | 查询指标序列 |
| 所属系统层 | `metric` |
| 请求方法与路径 | `GET /api/v1/stats/series` |
| 绑定操作 | `stats.series` |
| 鉴权级别 | `admin` |
| 所需权限 | `metric.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.26.0` |
| 操作文档 | `docs/api/API-METRIC-002.md` |

## 2. 功能与适用场景

读取单个指标的时间序列；支持按维度精确过滤，返回按小时排列的 points。

**适用场景**：画趋势图、定位拐点（例如发布后 api_calls 陡增、某 operation 的 p95 上升）。

**实现备注**：name 不在字典内返回 42201，不会静默返回空序列 —— 空序列与「指标拼错」必须能区分。histogram 的 value 是样本数、avg 是桶内均值。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `name` | query | 是 | `string` | 指标名，可用值见 stats.catalog |
| `dimension` | query | 否 | `string` | 维度过滤，精确匹配：须带齐该指标在 stats.catalog 声明的全部标签（键按字母序），形如 code=0,operation=content.posts 或 method=get,status=200；只写 operation=content.posts 命中不到真实采样 |
| `window` | query | 否 | `string` | 时间窗口，默认 24h，最长 30d |
| `limit` | query | 否 | `int` | 最多返回点数，默认 500，上限 2000；超出时 truncated=true |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-METRIC-002", "version": "v1", "system": "metric"}
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
| `name` | `string` | 指标名 |
| `kind` | `string` | counter / gauge / histogram |
| `unit` | `string` | 指标单位 |
| `dimension` | `string` | 规范化后的维度串 |
| `points` | `object[]` | at / hits / value / avg |
| `truncated` | `bool` | 是否因 limit 被截断 |

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
GET /api/v1/stats/series?name=api_calls&dimension=operation=content.posts&window=7d
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "window": {
            "key": "7d",
            "since": "2026-11-04 00:00:00",
            "hours": 168,
            "seconds": 604800
        },
        "name": "api_calls",
        "kind": "counter",
        "unit": "次",
        "dimension": "operation=content.posts",
        "points": [
            {
                "at": "2026-11-09 00:00:00",
                "hits": 42,
                "value": 42,
                "avg": 0
            }
        ],
        "truncated": false
    },
    "request_id": "8a2d1c4f6b3e9071",
    "meta": {
        "api": "API-METRIC-002",
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

- 0.56.0 页面接入 dimension 维度下钻：/console/stats 按 stats.catalog 的标签白名单校验「带齐全部标签」，从 stats.operations 排行反推完整可命中的 code=0,operation=... 串；缺标签 / 非法键 / 未知指标显式 422，合法但无采样的维度明说「该维度没有采样」而非伪装成站点无流量。契约旧的 operation=content.posts 示例因缺 code 标签命中不了真实采集，本版一并改正口径。
- 0.26.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
