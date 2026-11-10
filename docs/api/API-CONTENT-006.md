# API-CONTENT-006 · 变更文章状态

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-CONTENT-006` |
| 接口名称 | 变更文章状态 |
| 所属系统层 | `content` |
| 请求方法与路径 | `POST /api/v1/content/post/transition` |
| 绑定操作 | `content.transition` |
| 鉴权级别 | `admin` |
| 所需权限 | `content.publish` |
| 接口版本 | `v1` |
| 起始版本 | `0.28.0` |
| 操作文档 | `docs/api/API-CONTENT-006.md` |

## 2. 功能与适用场景

在 draft / review / published / archived 之间迁移文章状态，可同时指定定时发布时间。

**适用场景**：写完稿提交审核；审核通过后发布；下线旧文归档；排一篇明天早上八点自动上线的公告。

**实现备注**：迁移矩阵是穷举的：draft→review/published/archived，review→draft/published/archived，published→draft/archived，archived→draft/published；同状态重复提交是幂等成功（changed 字段不参与判断）。未知状态值返回 42201（拼写问题），已知但不可达返回 40908（顺序问题）——两者处置不同，不能压成一个码。publish_at 只对 published 有意义，改成其他状态时会被清空，避免留下「改回草稿却还排着未来时间」的残留。迁移是「比较并设置」：两个人同时改同一篇，后到的返回 40908 而不是静默覆盖。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `slug` | body | 是 | `string` | 文章 slug |
| `to` | body | 是 | `string` | 目标状态：draft / review / published / archived |
| `publish_at` | body | 否 | `string` | 仅当 to=published 时生效；未来时间表示定时发布，留空表示立即 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-CONTENT-006", "version": "v1", "system": "content"}
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
| `slug` | `string` | 文章 slug |
| `from` | `string` | 迁移前状态 |
| `to` | `string` | 迁移后状态 |
| `publish_at` | `string|null` | 生效的发布时间 |
| `scheduled` | `bool` | 是否排在未来 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `40403` | 404 | 资源不存在 |
| `40908` | 409 | 文章状态迁移不可达 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |
| `50101` | 501 | 接口已登记但执行者未实现 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/content/post/transition  {"slug":"hello","to":"published","publish_at":"2026-11-12 08:00:00"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "slug": "hello",
        "from": "review",
        "to": "published",
        "publish_at": "2026-11-12 08:00:00",
        "scheduled": true
    },
    "request_id": "c1a2b3d4e5f60718",
    "meta": {
        "api": "API-CONTENT-006",
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

- 0.28.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
