# API-COMMENT-007 · 举报评论

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-COMMENT-007` |
| 接口名称 | 举报评论 |
| 所属系统层 | `comment` |
| 请求方法与路径 | `POST /api/v1/comments/report` |
| 绑定操作 | `comments.report` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.29.0` |
| 操作文档 | `docs/api/API-COMMENT-007.md` |

## 2. 功能与适用场景

举报已发布评论；独立举报人数达到阈值时自动把评论打回 pending 待审。

**适用场景**：读者标记垃圾 / 人身攻击 / 跑题内容；多人举报后评论自动下架，等版主复核。

**实现备注**：阈值按「独立举报人数」算，同一主体重复举报不累加，因此不会把阈值一次次重新触发（auto_hidden 只在 changed=true 且达到阈值时为 true）。只对 published 状态生效：已经是 pending 的评论不重复下架，hidden / spam / deleted 也不动 —— 否则会打断版主的审核结论。举报过的人仍在台账里，重装时随主表一起清理。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `id` | body | 是 | `int` | 评论 id |
| `reason` | body | 否 | `string` | spam / abuse / offtopic / other，默认 other |
| `visitor_id` | body | 否 | `string` | 匿名主体的稳定标识 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-COMMENT-007", "version": "v1", "system": "comment"}
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
| `changed` | `bool` | 本次是否是新举报（重复举报为 false） |
| `reports` | `int` | 独立举报人数 |
| `threshold` | `int` | 自动下架阈值 |
| `auto_hidden` | `bool` | 本次是否触发自动打回 pending |
| `status` | `string` | 操作后的评论状态 |
| `actor` | `string` | 主体来源：user / ip / visitor |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40302` | 403 | 写接口未开放公网访问 |
| `40403` | 404 | 资源不存在 |
| `40914` | 409 | 评论当前状态不允许该互动 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/comments/report  {"id":12,"reason":"spam","visitor_id":"v-8f3a"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "id": 12,
        "changed": true,
        "reports": 1,
        "threshold": 3,
        "auto_hidden": false,
        "status": "published",
        "actor": "visitor"
    },
    "request_id": "b2c3d4e5f6071829",
    "meta": {
        "api": "API-COMMENT-007",
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

- 0.29.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
