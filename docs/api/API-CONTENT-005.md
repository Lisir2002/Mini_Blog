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

按字段更新文章标题、正文、状态、置顶与权重；每个字段单独经过字段级权限裁决。

**适用场景**：作者改自己的文章；编辑只能改标题不能改正文这类字段级限制。

**实现备注**：字段白名单只含 title / body / status / pinned / weight，每个字段单独调用一次 decide()，任一字段被拒则整次写入失败，不存在「部分成功」。status 走 ContentStatus 迁移矩阵：拼错的值返回 42201，已知但不可达的迁移返回 40908。定时发布请用 content.transition —— 那里能同时设置 publish_at。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `slug` | body | 是 | `string` | 文章 slug |
| `title` | body | 否 | `string` | 标题 |
| `body` | body | 否 | `string` | 正文，同时重算摘要 |
| `status` | body | 否 | `string` | 目标状态，须满足状态迁移矩阵 |
| `pinned` | body | 否 | `bool` | 是否置顶 |
| `weight` | body | 否 | `int` | 置顶排序权重，-1000~1000 |

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
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `40403` | 404 | 资源不存在 |
| `40908` | 409 | 文章状态迁移不可达 |
| `40909` | 409 | 文章在回收站 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |
| `50101` | 501 | 接口已登记但执行者未实现 |

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

- 0.53.0 页面开始消费 pinned / weight：/console/content 的推荐位表单是本接口第二个消费方，只带这两个字段、绝不夹带正文，以免字段级 ACL 连累正文保存。同版修正 status 改为非 published 时未清 publish_at 的残留（Pdo / Memory 两处同口径）。
- 0.9.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
