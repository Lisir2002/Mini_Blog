# API-COMMENT-004 · 删除评论（软删）

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-COMMENT-004` |
| 接口名称 | 删除评论（软删） |
| 所属系统层 | `comment` |
| 请求方法与路径 | `POST /api/v1/comments/delete` |
| 绑定操作 | `comments.delete` |
| 鉴权级别 | `user` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.13.0` |
| 操作文档 | `docs/api/API-COMMENT-004.md` |

## 2. 功能与适用场景

软删一条评论：清空正文与昵称、保留行与路径以维持回复链，重复删除幂等。

**适用场景**：作者撤回自己的评论，或版主清理攻击性内容；不能让「删了父评论导致整串回复消失」。

**实现备注**：授权顺序：先看 comment.delete.any，再看「本人 + comment.delete.own」。匿名评论没有主人，只能由前者删除。软删保留 path / parent_id / root_id，因此子回复仍能正常展示为「该评论已删除」下的对话。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `id` | body | 是 | `int` | 评论 id |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-COMMENT-004", "version": "v1", "system": "comment"}
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
| `deleted` | `bool` | 本次是否真的删除了 |
| `already` | `bool` | 是否此前已删除 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `40403` | 404 | 资源不存在 |
| `42201` | 422 | 参数校验未通过 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/comments/delete  {id:12}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "id": 12,
        "deleted": true,
        "already": false
    },
    "request_id": "3f7c1d9a5b2e8046",
    "meta": {
        "api": "API-COMMENT-004",
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

- 0.13.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
