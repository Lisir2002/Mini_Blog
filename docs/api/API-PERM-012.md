# API-PERM-012 · 登记或撤销契约覆盖授权

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-PERM-012` |
| 接口名称 | 登记或撤销契约覆盖授权 |
| 所属系统层 | `permission` |
| 请求方法与路径 | `POST /api/v1/permission/override/grant` |
| 绑定操作 | `permission.override.grant` |
| 鉴权级别 | `admin` |
| 所需权限 | `system.contract.override` |
| 接口版本 | `v1` |
| 起始版本 | `0.8.0` |
| 操作文档 | `docs/api/API-PERM-012.md` |

## 2. 功能与适用场景

以运行时身份登记「允许某 owner 覆盖某契约」的授权，或将其撤销；授权是装配期覆盖放行的前提。

**适用场景**：站长在启用 require_grant 前，先把主题 / 插件的合法契约替换逐条登记，避免升级后启动失败。

**实现备注**：写接口，默认仅本机来源可调用（40302）；建议携带 Idempotency-Key。授权本身需要 system.contract.override 权限（40303），且执行者会再次用同一个决策器复核，绕过 RequestGuard 直调执行者也不会变成无主授权。upsert 语义：重复登记只更新，不产生脏数据。 0.44.0 起覆盖台账面板刻意不接写入口：装配期动作只应经本机来源（RequestGuard 40302），页面直调执行者会绕过来源限制；它保留在零消费清单里是设计，不是遗漏。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `contract` | body | 是 | `string` | 契约接口全限定名，如 Wx\Contracts\LoggerInterface |
| `owner` | body | 是 | `string` | 覆盖者标识，必须与 Container::bind() 的 owner 一致 |
| `reason` | body | 否 | `string` | 登记原因，写入台账供审计 |
| `remove` | body | 否 | `bool` | true 表示撤销该授权（active=0，保留记录） |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-PERM-012", "version": "v1", "system": "permission"}
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
| `changed` | `bool` | 是否写入 / 更新了一条台账记录 |
| `contract` | `string` | 契约名 |
| `owner` | `string` | 覆盖者标识 |
| `active` | `bool` | 当前授权是否生效 |

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
| `40901` | 409 | 状态冲突 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/permission/override/grant  {"contract":"Wx\\Contracts\\LoggerInterface","owner":"plugin:audit","reason":"审计插件替换日志实现"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": [],
    "request_id": "1a2b3c4d5e6f7081",
    "meta": {
        "api": "API-PERM-012",
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

- 0.44.0 明确不接网页写入口，保留命令行 / 本机来源通道
- 0.8.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
