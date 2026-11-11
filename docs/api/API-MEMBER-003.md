# API-MEMBER-003 · 发放经验

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-MEMBER-003` |
| 接口名称 | 发放经验 |
| 所属系统层 | `member` |
| 请求方法与路径 | `POST /api/v1/member/xp/award` |
| 绑定操作 | `member.xp.award` |
| 鉴权级别 | `admin` |
| 所需权限 | `member.xp.award` |
| 接口版本 | `v1` |
| 起始版本 | `0.10.0` |
| 操作文档 | `docs/api/API-MEMBER-003.md` |

## 2. 功能与适用场景

为指定用户发放经验：按 event_key 幂等、按动作每日上限截断、跨门槛自动升级。

**适用场景**：插件在用户完成业务动作后回调发经验；站长手动补发或调整。

**实现备注**：event_key 是本接口的业务幂等键，与 HTTP 层 Idempotency-Key 互补：前者防「同一业务事实被两个请求各发一次」，后者防「同一请求被重试两次」。每日上限按自然日与动作统计，超出部分截断而不是报错。applied 才是本次实际入账值：规则停用 / 达上限 / 幂等重放时都是 0，部分截断时小于请求值，调用方不能一律显示「发放成功」—— 0.42.0 起 /console/member 按 reason 分五种 outcome 回报。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `user_id` | body | 是 | `string` | 用户 ID 或用户名 |
| `action` | body | 是 | `string` | 经验动作，如 post.publish；需匹配经验规则 |
| `event_key` | body | 是 | `string` | 业务事件唯一键，重放不会重复加分 |
| `amount` | body | 否 | `int` | 显式经验值；省略时取规则配置，仍受每日上限约束 |
| `note` | body | 否 | `string` | 备注，写入流水 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-MEMBER-003", "version": "v1", "system": "member"}
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
| `user_id` | `string` | 用户标识 |
| `applied` | `int` | 本次实际增加的经验 |
| `total` | `int` | 发放后的经验余额 |
| `level` | `int` | 发放后的等级 |
| `level_up` | `bool` | 本次是否升级 |
| `duplicate` | `bool` | 是否为幂等重放 |
| `reason` | `string` | 未加分原因：disabled / daily_cap / duplicate，空表示正常发放 |

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
POST /api/v1/member/xp/award  {"user_id":"1","action":"post.publish","event_key":"content:42"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "request_id": "1a2b3c4d5e6f7081",
    "data": {
        "user_id": "1",
        "applied": 50,
        "total": 200,
        "level": 2,
        "level_up": false,
        "duplicate": false,
        "reason": ""
    },
    "meta": {
        "api": "API-MEMBER-003",
        "version": "v1",
        "system": "member"
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

- 0.10.0 初次发布
- 0.42.0 由 /console/member 消费；页面按 reason / duplicate / applied 区分五种结果，不再把「加了 0 分」说成成功

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
