# API-COMMENT-009 · 审核队列

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-COMMENT-009` |
| 接口名称 | 审核队列 |
| 所属系统层 | `comment` |
| 请求方法与路径 | `GET /api/v1/comments/queue` |
| 绑定操作 | `comments.queue` |
| 鉴权级别 | `admin` |
| 所需权限 | `comment.moderate` |
| 接口版本 | `v1` |
| 起始版本 | `0.29.0` |
| 操作文档 | `docs/api/API-COMMENT-009.md` |

## 2. 功能与适用场景

按举报数降序列出待审与被举报的评论，附点赞 / 举报计数，供版主按优先级处理。

**适用场景**：后台审核台首屏：先看到被多人举报的那条，而不是按 id 顺序从最旧的一条翻起。

**实现备注**：队列口径 = pending 待审 + 有任何举报，排除已删除；两种存储实现（MySQL / 内存降级）逐字一致，降级后不会少掉纯待审那一批。排序按 reports 降序、id 升序。台账里指向不存在评论的孤儿行不会被渲染成幽灵条目。total 与 items 同口径。 0.46.0 起 /console/queue 明确口径边界：本接口不含已发布的普通评论，删除任意一条评论需要另走 comments.list 的按文章浏览路径；队列视图与文章视图共用同一份文章窗口。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `limit` | query | 否 | `int` | 每页条数，默认 50，上限 200 |
| `offset` | query | 否 | `int` | 偏移量，默认 0 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-COMMENT-009", "version": "v1", "system": "comment"}
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
| `items` | `object[]` | 评论公开字段 + likes / reports |
| `total` | `int` | 队列总数 |
| `limit` | `int` | 实际生效的每页条数 |
| `offset` | `int` | 实际生效的偏移量 |
| `truncated` | `bool` | 是否还有后续页 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/comments/queue?limit=20  Authorization: Bearer <admin>
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "items": [
            {
                "id": 12,
                "post_slug": "first-post",
                "author": "甲",
                "body": "待复核内容",
                "status": "pending",
                "created_at": "2026-11-11 06:00:00",
                "likes": 4,
                "reports": 3
            }
        ],
        "total": 1,
        "limit": 20,
        "offset": 0,
        "truncated": false
    },
    "request_id": "d4e5f60718293a4b",
    "meta": {
        "api": "API-COMMENT-009",
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

- 0.46.0 明确队列口径不含已发布普通评论，需配合 comments.list 的按文章浏览
- 0.29.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
