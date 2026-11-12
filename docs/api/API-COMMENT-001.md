# API-COMMENT-001 · 查询文章评论

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-COMMENT-001` |
| 接口名称 | 查询文章评论 |
| 所属系统层 | `comment` |
| 请求方法与路径 | `GET /api/v1/comments` |
| 绑定操作 | `comments.list` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.4.0` |
| 操作文档 | `docs/api/API-COMMENT-001.md` |

## 2. 功能与适用场景

按文章 slug 返回评论树：扁平列表 + 嵌套结构 + 公开条数，按身份过滤不可见项。

**适用场景**：文章详情页渲染评论区；Headless 客户端拉取评论。

**实现备注**：可见性：published 对所有人；pending / hidden 仅作者与有 comment.moderate 者；spam 仅管理员；deleted 保留占位供回复链展示。每条附带 tokens（表情 token，非 HTML）。read_limit 默认 2000：热文被刷到数万条时全量读会占几十 MB，超出只返回最早的 N 条并置 truncated=true，total 不受影响。 v0.29 起 editable 由编辑窗口策略真实判定（不再是恒真的空头支票）；互动计数以三条批量 SQL 补齐，不随条数增长。 0.46.0 起 /console/queue 的「按文章浏览」消费：页面显式传 _identity 才能看到 hidden / spam / deleted 占位；先用 content.post 复核文章存在，读不到时 fail-closed，不把「不知道」渲染成「没有评论」。已删除占位仍带 deletable=true，页面按 status=deleted 判断而非只信该字段。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `post_slug` | query | 是 | `string` | 文章 slug |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-COMMENT-001", "version": "v1", "system": "comment"}
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
| `post_slug` | `string` | 文章 slug |
| `total` | `int` | 公开条数（不含待审 / 隐藏 / 垃圾） |
| `truncated` | `bool` | 是否因 comment.read_limit 被截断；true 时 items 只是最早的 N 条，total 仍为真实总数 |
| `items` | `array` | 扁平评论数组，按 id 升序 |
| `tree` | `array` | 嵌套评论树，每项含 children |
| `likes` | `int` | 点赞数（独立主体去重） |
| `reports` | `int` | 举报数（独立主体去重） |
| `liked` | `bool` | 当前主体是否已点赞 |
| `reported` | `bool` | 当前主体是否已举报 |
| `edit_count` | `int` | 编辑次数，作为乐观锁版本 |
| `edited_at` | `string|null` | 最后编辑时间，未编辑为 null |

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
GET /api/v1/comments?post_slug=first-post
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "post_slug": "first-post",
        "items": [],
        "total": 0
    },
    "request_id": "8192a3b4c5d6e7f8",
    "meta": {
        "api": "API-COMMENT-001",
        "version": "v1",
        "system": "comment"
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

- 0.46.0 由 /console/queue 按文章浏览消费；_identity 传参与 deleted 占位语义写明
- 0.4.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
