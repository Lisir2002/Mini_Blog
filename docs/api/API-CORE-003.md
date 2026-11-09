# API-CORE-003 · 查询系统层装配顺序

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-CORE-003` |
| 接口名称 | 查询系统层装配顺序 |
| 所属系统层 | `core` |
| 请求方法与路径 | `GET /api/v1/system/layers` |
| 绑定操作 | `system.layers` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.2.0` |
| 操作文档 | `docs/api/API-CORE-003.md` |

## 2. 功能与适用场景

返回本次启动的拓扑装配顺序与已发现的系统层清单。

**适用场景**：新增系统层后验证依赖排序是否生效；定位「契约未实现」类故障。

**实现备注**：装配分 register / boot 两阶段，所有契约绑定完成后才进入 boot，因此顺序可预测。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

**无请求参数。**

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-CORE-003", "version": "v1", "system": "core"}
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
| `order` | `string[]` | 按依赖拓扑排序后的装配顺序 |
| `manifests` | `string[]` | 被发现的系统层名称 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/system/layers
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "order": [
            "log",
            "database",
            "security",
            "lifecycle",
            "api",
            "user",
            "plugin",
            "theme",
            "comment",
            "page"
        ],
        "manifests": [
            "log",
            "database",
            "security",
            "lifecycle",
            "api",
            "user",
            "plugin",
            "theme",
            "comment",
            "page"
        ]
    },
    "request_id": "c72b91e0a4d5f83",
    "meta": {
        "api": "API-CORE-003",
        "version": "v1",
        "system": "core"
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

- 0.2.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
