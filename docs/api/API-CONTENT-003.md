# API-CONTENT-003 · 创建文章

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-CONTENT-003` |
| 接口名称 | 创建文章 |
| 所属系统层 | `content` |
| 请求方法与路径 | `POST /api/v1/content/posts` |
| 绑定操作 | `content.create` |
| 鉴权级别 | `admin` |
| 所需权限 | `content.create` |
| 接口版本 | `v1` |
| 起始版本 | `0.4.0` |
| 操作文档 | `docs/api/API-CONTENT-003.md` |

## 2. 功能与适用场景

经 content.before_save 业务钩子补默认字段后写入文章；slug 缺省时自动生成，非法 slug / 空标题 / 空正文返回 42201。

**适用场景**：控制台或写作客户端发布新文章；批量导入时配合 Idempotency-Key 防重复提交。

**实现备注**：写接口：默认仅本机来源可调用（config.api.expose_write），且必须携带 admin 令牌；建议带 Idempotency-Key，校验失败不入缓存，同键不同参数返回 40902。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `title` | body | 是 | `string` | 文章标题 |
| `body` | body | 是 | `string` | 文章正文 |
| `slug` | body | 否 | `string` | 唯一标识，缺省自动生成 post-时间戳-随机串 |
| `status` | body | 否 | `string` | published / draft，默认 published |
| `author` | body | 否 | `string` | 作者署名 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-CONTENT-003", "version": "v1", "system": "content"}
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
| `id` | `int` | 新记录 ID |
| `slug` | `string` | 最终使用的 slug |
| `created` | `bool` | 固定为 true |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40302` | 403 | 写接口未开放公网访问 |
| `40902` | 409 | 幂等键重放参数不一致 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |
| `40303` | 403 | 权限决策未放行（fail-closed） |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/content/posts  Authorization: Bearer <admin-token>  {"title":"第三篇文章","body":"正文"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "id": 3,
        "slug": "post-20261108-234055-a1b2c3",
        "created": true
    },
    "request_id": "6f708192a3b4c5d6",
    "meta": {
        "api": "API-CONTENT-003",
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

- 0.4.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
