# API-REL-002 · 版本历史

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-REL-002` |
| 接口名称 | 版本历史 |
| 所属系统层 | `release` |
| 请求方法与路径 | `GET /api/v1/release/history` |
| 绑定操作 | `release.history` |
| 鉴权级别 | `admin` |
| 所需权限 | `release.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.11.0` |
| 操作文档 | `docs/api/API-REL-002.md` |

## 2. 功能与适用场景

返回最近的升级与回退记录，含每次应用的迁移、批次号与健康检查结果。

**适用场景**：排查「哪次升级改了什么」；回退前确认目标版本对应的批次。

**实现备注**：历史文件是 append-only 的 storage/releases/history.json；回退时用它确定要撤销哪些迁移。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `limit` | query | 否 | `int` | 返回条数，默认 20 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-REL-002", "version": "v1", "system": "release"}
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
| `history` | `array` | 按时间倒序的版本操作记录 |
| `history[].migrations` | `array` | 该次操作应用或回退的迁移 id |
| `history[].result` | `string` | ok / failed |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `42201` | 422 | 参数校验未通过 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/release/history?limit=5
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "history": [
            {
                "at": "2026-11-09T14:00:00+08:00",
                "action": "upgrade",
                "from": "0.10.0",
                "to": "0.11.0",
                "batch": 1,
                "migrations": [
                    "release/001_schema_migrations.sql"
                ],
                "result": "ok"
            }
        ]
    },
    "request_id": "1a2b3c4d5e6f7a8b",
    "meta": {
        "api": "API-REL-002",
        "version": "v1",
        "system": "release"
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

- 0.11.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
