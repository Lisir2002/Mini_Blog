# API-ATTACH-001 · 列出文章附件

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-ATTACH-001` |
| 接口名称 | 列出文章附件 |
| 所属系统层 | `attachment` |
| 请求方法与路径 | `GET /api/v1/attachments` |
| 绑定操作 | `attachments.list` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.13.0` |
| 操作文档 | `docs/api/API-ATTACH-001.md` |

## 2. 功能与适用场景

按文章 slug 列出未删除的附件元数据；私有附件只对上传者与管理员可见。

**适用场景**：文章编辑页展示已上传的附件列表，读者页展示可下载的资料；需要过滤掉别人上传的私有文件。

**实现备注**：只返回元数据，不返回字节；url 指向二进制通道，下载时仍会二次鉴权。

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
  "meta": {"api": "API-ATTACH-001", "version": "v1", "system": "attachment"}
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
| `total` | `int` | 可见附件数 |
| `items` | `array` | 附件数组：id/name/mime/ext/bytes/sha256/visibility/url |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `42201` | 422 | 参数校验未通过 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/attachments?post_slug=first-post
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "post_slug": "first-post",
        "total": 1,
        "items": [
            {
                "id": 3,
                "name": "cover.png",
                "mime": "image/png",
                "ext": "png",
                "bytes": 20480,
                "sha256": "9f2c…",
                "visibility": "public",
                "post_slug": "first-post",
                "created_at": "2026-11-09 18:30:00",
                "url": "/attach/download?id=3"
            }
        ]
    },
    "request_id": "3f7c1d9a5b2e8046",
    "meta": {
        "api": "API-ATTACH-001",
        "version": "v1",
        "system": "attachment"
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
