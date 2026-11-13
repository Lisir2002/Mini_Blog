# API-CONTENT-010 · 回收站列表

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-CONTENT-010` |
| 接口名称 | 回收站列表 |
| 所属系统层 | `content` |
| 请求方法与路径 | `GET /api/v1/content/trash` |
| 绑定操作 | `content.trash` |
| 鉴权级别 | `admin` |
| 所需权限 | `content.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.28.0` |
| 操作文档 | `docs/api/API-CONTENT-010.md` |

## 2. 功能与适用场景

分页列出已软删除的文章，含删除时间与删除前状态。

**适用场景**：后台回收站页面；确认哪些内容可以彻底清掉。

**实现备注**：回收站是唯一能读到已删除文章的地方；普通列表与详情一律看不到。status 字段保留删除前的值，恢复时据此回到原位。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `limit` | query | 否 | `int` | 每页条数，默认 20，上限 100 |
| `cursor` | query | 否 | `string` | 上一页返回的 next_cursor |
| `keyword` | query | 否 | `string` | 在标题与正文中做 LIKE 匹配 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-CONTENT-010", "version": "v1", "system": "content"}
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
| `items` | `object[]` | 文章字段 + deleted_at |
| `next_cursor` | `string|null` | 下一页游标 |
| `total` | `int` | 回收站总数 |

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
| `50101` | 501 | 接口已登记但执行者未实现 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/content/trash?limit=20  Authorization: Bearer <admin>
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "items": [
            {
                "id": 7,
                "slug": "old-post",
                "title": "旧文",
                "status": "archived",
                "deleted_at": "2026-11-11 09:00:00"
            }
        ],
        "next_cursor": null,
        "total": 1
    },
    "request_id": "a5e6f708192a3b4c",
    "meta": {
        "api": "API-CONTENT-010",
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

- 0.55.0 页面接入 cursor 深翻页：/console/content?view=trash 透传游标、渲染下一页 / 回到第一页链接，恢复与彻底删除后 PRG 留在原页；非法游标由后端拒绝并翻成显式 422。此前接口早已支持 cursor / next_cursor（Pdo 与 Memory 两处 keyset 分页），页面却只取第一页并在提示里标「未列全」。
- 0.28.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
