# API-CONTENT-012 · 管理分类与标签

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-CONTENT-012` |
| 接口名称 | 管理分类与标签 |
| 所属系统层 | `content` |
| 请求方法与路径 | `POST /api/v1/content/terms/manage` |
| 绑定操作 | `content.terms.manage` |
| 鉴权级别 | `admin` |
| 所需权限 | `content.manage_terms` |
| 接口版本 | `v1` |
| 起始版本 | `0.28.0` |
| 操作文档 | `docs/api/API-CONTENT-012.md` |

## 2. 功能与适用场景

创建 / 重命名 / 删除术语；删除会一并清理它的全部文章绑定。

**适用场景**：站长整理站点结构：新增「随笔」分类，给「php」改名，删掉一个只用过一次的错标签。

**实现备注**：同 kind 下 slug 重复返回 40911，而不是静默复用已有术语 —— 复用会让「新建分类」变成「把文章挂到别人的分类上」。kind 与 slug 不可修改：改 slug 会让所有旧链接 404，需要改就新建再迁移。删除在事务里同时清关系，不留孤儿行。 0.45.0 起 /console/terms 在页面层补接口不校验的边界：slug 格式与长度、名称按字符计 60 字上限、说明 200 字上限、update / delete 的 ID 存在性预查（接口返回 40404）、同 kind 下重复 slug 预查（40911 不复用）；改 slug / kind 的入口刻意不提供；删除要求手写 slug 确认并回报 relations_removed；updated=false 单独说「本来就是这个值」。 0.45.0 另修正存储层幂等：update 原先无条件刷新 updated_at，同值提交只要跨秒就被 MySQL 判为已改变、误报 updated=true；现在先比对当前值，未变则不发 UPDATE。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `action` | body | 是 | `string` | create / update / delete |
| `kind` | body | 否 | `string` | action=create 时必填：category / tag |
| `slug` | body | 否 | `string` | action=create 时必填：小写字母、数字与连字符 |
| `name` | body | 否 | `string` | 显示名称，最长 60 字（create 必填） |
| `description` | body | 否 | `string` | 可选说明 |
| `id` | body | 否 | `int` | update / delete 时的术语 ID |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-CONTENT-012", "version": "v1", "system": "content"}
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
| `id` | `int` | 术语 ID |
| `kind` | `string` | category / tag |
| `slug` | `string` | 术语 slug |
| `relations_removed` | `int` | 删除时清理的绑定条数 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `40404` | 404 | 分类或标签不存在 |
| `40911` | 409 | 分类或标签已存在 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |
| `50101` | 501 | 接口已登记但执行者未实现 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/content/terms/manage  {"action":"create","kind":"category","slug":"essay","name":"随笔"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "id": 5,
        "kind": "category",
        "slug": "essay",
        "name": "随笔",
        "created": true
    },
    "request_id": "c708192a3b4c5d6e",
    "meta": {
        "api": "API-CONTENT-012",
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

- 0.45.0 修正 update 幂等：updated_at 不再让同值提交跨秒误报已改变
- 0.45.0 由 /console/terms 消费；页面层补格式、长度、存在性与确认边界
- 0.28.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
