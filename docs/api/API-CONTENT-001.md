# API-CONTENT-001 · 查询文章列表（游标分页）

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-CONTENT-001` |
| 接口名称 | 查询文章列表（游标分页） |
| 所属系统层 | `content` |
| 请求方法与路径 | `GET /api/v1/content/posts` |
| 绑定操作 | `content.posts` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.4.0` |
| 操作文档 | `docs/api/API-CONTENT-001.md` |

## 2. 功能与适用场景

按 keyset 游标分页返回文章列表，支持状态过滤与关键词检索；数据来自 ContentRepositoryInterface（MySQL 或回退适配器）。

**适用场景**：博客首页 / 归档页取数据；Headless 站点对外提供文章 API；深翻页用 next_cursor 而非 OFFSET。

**实现备注**：游标为 base64 编码的自增 ID，采用 id > cursor 的 keyset 分页，避免 OFFSET 深翻页性能塌陷；非法游标返回 42201。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `limit` | query | 否 | `int` | 每页条数 1-100，默认取 config.content.per_page |
| `cursor` | query | 否 | `string` | 上一页返回的 next_cursor（base64 编码的记录 ID） |
| `status` | query | 否 | `string` | published / draft / any，默认 published |
| `keyword` | query | 否 | `string` | 标题或正文模糊匹配 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-CONTENT-001", "version": "v1", "system": "content"}
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
| `items` | `object[]` | 文章对象：id / slug / title / excerpt / body / status / author / created_at |
| `next_cursor` | `string|null` | 下一页游标；null 表示已到末尾 |
| `total` | `int` | 当前状态过滤下的总数 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/content/posts?limit=1
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "items": [
            {
                "id": 1,
                "slug": "first-post",
                "title": "第一篇文章",
                "excerpt": "这是内容系统提供的第一篇示例文章。",
                "status": "published",
                "author": "admin"
            }
        ],
        "next_cursor": "MQ==",
        "total": 2
    },
    "request_id": "4d5e6f708192a3b4",
    "meta": {
        "api": "API-CONTENT-001",
        "version": "v1",
        "system": "content"
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

- 0.4.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
