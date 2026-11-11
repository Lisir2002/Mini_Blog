# API-MEMBER-005 · 定义经验规则

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-MEMBER-005` |
| 接口名称 | 定义经验规则 |
| 所属系统层 | `member` |
| 请求方法与路径 | `POST /api/v1/member/xp/rule` |
| 绑定操作 | `member.xp.rule` |
| 鉴权级别 | `admin` |
| 所需权限 | `member.xp.rule.manage` |
| 接口版本 | `v1` |
| 起始版本 | `0.10.0` |
| 操作文档 | `docs/api/API-MEMBER-005.md` |

## 2. 功能与适用场景

新增或修改一条经验规则（op=upsert），或删除规则（op=delete）。

**适用场景**：站长把「发布文章」从 50 调到 80，或关闭评论经验。

**实现备注**：规则只影响「以后发放」的经验，不回溯已入账流水；调低上限也不会扣减历史经验。接口对 action 只校验非空，页面层（0.42.0 起 /console/member）另校验格式与数值范围，并要求删除时手写动作名确认。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `op` | body | 否 | `string` | upsert（默认）/ delete |
| `action` | body | 是 | `string` | 经验动作标识 |
| `amount` | body | 否 | `int` | 单次经验，upsert 时必填且 > 0 |
| `daily_cap` | body | 否 | `int` | 每日上限，0 表示不限 |
| `enabled` | body | 否 | `bool` | 是否启用，默认 true |
| `description` | body | 否 | `string` | 规则说明 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-MEMBER-005", "version": "v1", "system": "member"}
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
| `action` | `string` | 动作标识 |
| `op` | `string` | 执行的操作 |
| `changed` | `bool` | 是否发生了变更 |

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

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/member/xp/rule  {"action":"post.publish","amount":80,"daily_cap":240}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "request_id": "1a2b3c4d5e6f7081",
    "data": {
        "action": "post.publish",
        "op": "upsert",
        "changed": true
    },
    "meta": {
        "api": "API-MEMBER-005",
        "version": "v1",
        "system": "member"
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

- 0.10.0 初次发布
- 0.42.0 由 /console/member 消费；页面层补格式、数值范围与删除确认

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
