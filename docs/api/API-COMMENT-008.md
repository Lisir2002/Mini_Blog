# API-COMMENT-008 · 编辑自己的评论

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-COMMENT-008` |
| 接口名称 | 编辑自己的评论 |
| 所属系统层 | `comment` |
| 请求方法与路径 | `POST /api/v1/comments/edit` |
| 绑定操作 | `comments.edit` |
| 鉴权级别 | `user` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.29.0` |
| 操作文档 | `docs/api/API-COMMENT-008.md` |

## 2. 功能与适用场景

作者本人在发布后的时间窗口内修改正文，需携带列表返回的 edit_count 作乐观锁版本。

**适用场景**：刚发完发现错别字 / 链接写错，趁窗口内修正；并发编辑时后提交者收到冲突而不是静默覆盖。

**实现备注**：窗口从 created_at 起算，编辑不续期：否则每改一次就延长一次，「10 分钟内可编辑」会变成永久可编辑。只有作者本人可改（40301），管理员也不例外 —— 编辑是作者修正自己的话，不是审核权限。deleted / hidden / spam / 非 pending|published 返回 40914；窗口关闭 / 时间戳异常返回 40912；版本被别人推进返回 40913。正文必须过 CommentGuard，编辑接口不能成为绕过长度 / 链接 / 控制字符检查的后门。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `id` | body | 是 | `int` | 评论 id |
| `body` | body | 是 | `string` | 新正文，与发表共用同一套内容守卫 |
| `expected_edit_count` | body | 是 | `int` | 取自列表的 edit_count；不匹配返回 40913 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-COMMENT-008", "version": "v1", "system": "comment"}
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
| `id` | `int` | 评论 id |
| `body` | `string` | 规范化后实际落库的正文 |
| `edit_count` | `int` | 编辑后的版本号 |
| `edited` | `bool` | 固定为 true |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40403` | 404 | 资源不存在 |
| `40912` | 409 | 评论编辑窗口已关闭 |
| `40913` | 409 | 评论已被其他编辑修改 |
| `40914` | 409 | 评论当前状态不允许该互动 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/comments/edit  Authorization: Bearer <token>  {"id":12,"body":"修正后的内容","expected_edit_count":0}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "id": 12,
        "body": "修正后的内容",
        "edit_count": 1,
        "edited": true
    },
    "request_id": "c3d4e5f60718293a",
    "meta": {
        "api": "API-COMMENT-008",
        "version": "v1",
        "system": "comment"
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

- 0.29.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
