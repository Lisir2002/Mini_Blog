# API-ATTACH-002 · 修改附件元数据

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-ATTACH-002` |
| 接口名称 | 修改附件元数据 |
| 所属系统层 | `attachment` |
| 请求方法与路径 | `POST /api/v1/attachments/update` |
| 绑定操作 | `attachments.update` |
| 鉴权级别 | `user` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.13.0` |
| 操作文档 | `docs/api/API-ATTACH-002.md` |

## 2. 功能与适用场景

改展示名、可见性或挂载文章；三个字段都可选，至少给一个。

**适用场景**：上传后重命名、把公开附件改为私有、或把附件从草稿挂到正式文章上。

**实现备注**：只改元数据，不动字节与存储路径；因此改可见性不会产生副本，也不会让旧链接失效。三个边界接口层刻意不拦：post_slug 不校验目标文章是否存在（而系统没有 list-all 接口）、name 超过 120 字符会被 displayName 静默截断、visibility 任何非 private 的字符串都会落成 public。0.43.0 起 /console/attachments 在页面层逐条挡住。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `id` | body | 是 | `int` | 附件 id |
| `name` | body | 否 | `string` | 新的展示名，最长 120 字 |
| `visibility` | body | 否 | `string` | public / private |
| `post_slug` | body | 否 | `string` | 改挂到的文章 slug |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-ATTACH-002", "version": "v1", "system": "attachment"}
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
| `id` | `int` | 附件 id |
| `updated` | `array` | 实际更新的字段名 |

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
POST /api/v1/attachments/update  {id:3, visibility:private}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "id": 3,
        "updated": [
            "visibility"
        ]
    },
    "request_id": "3f7c1d9a5b2e8046",
    "meta": {
        "api": "API-ATTACH-002",
        "version": "v1",
        "system": "attachment"
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

- 0.13.0 初次发布
- 0.43.0 由 /console/attachments 消费；页面层补目标文章存在性、名字长度与可见性枚举校验

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
