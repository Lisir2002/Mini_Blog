# API-CONTENT-005 · 按字段更新文章

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-CONTENT-005` |
| 接口名称 | 按字段更新文章 |
| 所属系统层 | `content` |
| 请求方法与路径 | `POST /api/v1/content/post/update` |
| 绑定操作 | `content.update` |
| 鉴权级别 | `user` |
| 所需权限 | `content.edit` |
| 接口版本 | `v1` |
| 起始版本 | `0.9.0` |
| 操作文档 | `docs/api/API-CONTENT-005.md` |

## 2. 功能与适用场景

按 slug 更新 title / body / status；每个字段单独做一次字段级授权，任一字段非 allow 则整次写入被拒。

**适用场景**：作者改自己的文章；编辑只能改标题不能改正文这类字段级限制。

**实现备注**：字段级权限是资源 ACL 的 field 维度，不是第四层权限：ACL 条目 field=body 只影响 body，不会把整篇文章从列表剔除。决策输入为 content.edit + {type:post, id:文章 slug, field:字段名}，资源标识与读路径（content.read）统一为 slug，避免同一条 ACL 只对一侧生效；权限层缺席或任一字段非 allow 一律 40303（fail-closed），不做部分写入。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `slug` | body | 是 | `string` | 文章唯一标识 |
| `title` | body | 否 | `string` | 新标题 |
| `body` | body | 否 | `string` | 新正文（同时刷新摘要） |
| `status` | body | 否 | `string` | published / draft / private / pending |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-CONTENT-005", "version": "v1", "system": "content"}
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
| `slug` | `string` | 被更新的文章 |
| `updated` | `string[]` | 实际写入的字段名 |

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
| `40403` | 404 | 资源不存在 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/content/post/update  {"slug":"first-post","title":"新标题"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "request_id": "1a2b3c4d5e6f7081",
    "data": {
        "slug": "first-post",
        "updated": [
            "title"
        ]
    },
    "meta": {
        "api": "API-CONTENT-005",
        "version": "v1",
        "system": "content"
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

- 0.9.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
