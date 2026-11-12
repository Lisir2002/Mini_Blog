# API-PERM-006 · 设置资源 ACL

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-PERM-006` |
| 接口名称 | 设置资源 ACL |
| 所属系统层 | `permission` |
| 请求方法与路径 | `POST /api/v1/permission/acl` |
| 绑定操作 | `permission.acl.set` |
| 鉴权级别 | `admin` |
| 所需权限 | `permission.manage` |
| 接口版本 | `v1` |
| 起始版本 | `0.6.0` |
| 操作文档 | `docs/api/API-PERM-006.md` |

## 2. 功能与适用场景

对单个资源（或资源的某个字段）设置显式放行或显式拒绝，实现客体级权限。

**适用场景**：把某篇草稿设为私有、禁止某角色删除某篇文章。

**实现备注**：显式 deny 优先级最高，压过角色通配符与继承链；多条 ACL 按插入顺序判定，deny 先扫描。field 非空时只对同名字段的请求生效，不会把整篇文章从列表剔除。 0.44.0 起 /console/acl 在页面层补三类接口不校验的边界：主体前缀（parseSubject() 把未知前缀静默解释成角色）、能力名（写错永不命中）、资源 id 存在性（content.post 预查）；整资源级「全部主体 + 全部能力」显式 deny 要求手写确认。页面明说这是追加 INSERT，不是编辑或撤销，新加的 allow 不会抵消已有 deny。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `type` | body | 否 | `string` | 资源类型，默认 post |
| `id` | body | 是 | `string` | 资源标识，如文章 slug |
| `subject` | body | 否 | `string` | 主体：* / role:admin / user:3，默认 * |
| `capability` | body | 否 | `string` | 能力或权限点，默认 * |
| `allow` | body | 否 | `bool` | true 放行 / false 显式拒绝 |
| `field` | body | 否 | `string` | 字段级维度（如 body）；留空表示整资源级，对任意请求生效 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-PERM-006", "version": "v1", "system": "permission"}
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
| `changed` | `bool` | ACL 记录是否写入成功 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40302` | 403 | 写接口未开放公网访问 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |
| `40902` | 409 | 幂等键重放参数不一致 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/permission/acl  Authorization: Bearer <admin-token>
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": [],
    "request_id": "1a2b3c4d5e6f7081",
    "meta": {
        "api": "API-PERM-006",
        "version": "v1",
        "system": "permission"
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

- 0.44.0 由 /console/acl 消费；页面层补主体 / 能力 / 资源存在性与高危 deny 确认
- 0.6.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
