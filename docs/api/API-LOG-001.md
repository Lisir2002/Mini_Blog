# API-LOG-001 · 查询日志

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-LOG-001` |
| 接口名称 | 查询日志 |
| 所属系统层 | `log` |
| 请求方法与路径 | `GET /api/v1/logs/query` |
| 绑定操作 | `logs.query` |
| 鉴权级别 | `admin` |
| 所需权限 | `logs.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.2.0` |
| 操作文档 | `docs/api/API-LOG-001.md` |

## 2. 功能与适用场景

按日期读取日志系统落盘的日志行尾部若干条，并给出可查询日期与文件内总行数。

**适用场景**：在零呈现 / Headless 模式下排查装卸操作与内核 trace；控制台日志页用它做按日期浏览与截断提示。

**实现备注**：auth=admin 已在 v0.4 接入鉴权执行：未认证 40101、令牌失效 40102、角色不足 40301。写接口默认仅本机来源可调用。 返回的是文件尾部而非头部：total 与 returned 必须一起看，前者是文件规模，后者是本次窗口。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `date` | query | 否 | `string` | YYYY-MM-DD，默认当天 |
| `limit` | query | 否 | `int` | 返回条数，1-200，默认 20 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-LOG-001", "version": "v1", "system": "log"}
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
| `date` | `string` | 查询日期 |
| `exists` | `bool` | 该日期是否有日志文件；false 表示那天没有日志，不是空日志 |
| `total` | `int` | 文件内非空行总数（不是本次返回行数） |
| `returned` | `int` | 本次返回行数（≤ limit） |
| `truncated` | `bool` | total > returned 时为 true，表示只看到了尾部 |
| `lines` | `string[]` | 日志行（文件尾部 limit 条，按时间正序） |
| `dates` | `string[]` | 可查询日期，倒序，最多 60 天 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `42201` | 422 | 参数校验未通过 |
| `50001` | 500 | 服务器内部错误 |
| `40303` | 403 | 权限决策未放行（fail-closed） |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/logs/query?limit=5
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "date": "2026-11-08",
        "exists": true,
        "total": 1,
        "returned": 1,
        "truncated": false,
        "lines": [
            "[2026-11-08 23:10:09] INFO    内核装配完成 {\"systems\":[]}"
        ],
        "dates": [
            "2026-11-08",
            "2026-11-07"
        ]
    },
    "request_id": "d8e4a1c0b73f5296",
    "meta": {
        "api": "API-LOG-001",
        "version": "v1",
        "system": "log"
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

- 0.2.0 初次发布
- 0.40.0 total 改为文件内非空行总数（原实现先切片再计数，total 恒等于 limit）；新增 exists / returned / truncated / dates

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
