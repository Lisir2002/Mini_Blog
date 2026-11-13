# API-THEME-001 · 列出主题

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-THEME-001` |
| 接口名称 | 列出主题 |
| 所属系统层 | `theme` |
| 请求方法与路径 | `GET /api/v1/themes` |
| 绑定操作 | `theme.list` |
| 鉴权级别 | `admin` |
| 所需权限 | `theme.read` |
| 接口版本 | `v1` |
| 起始版本 | `0.18.0` |
| 操作文档 | `docs/api/API-THEME-001.md` |

## 2. 功能与适用场景

静态枚举 themes/ 下全部主题包及其清单字段，并返回当前启用主题与是否处于零呈现模式。需 admin 令牌与 theme.read 权限（v0.61 对齐其余读接口的鉴权形态，清单不再公开暴露）。

**适用场景**：后台主题管理卡片、安装向导选择主题、运维核对元主题成对出厂；无主题（Headless）时同样可用。

**实现备注**：纯静态读取：不引导主题包、不连库、不触发装配副作用；meta / surface / family 是元主题的一等契约字段。接口归 theme 系统层自报，主题包只消费、不注册接口（规矩 J）。v0.61 起需 admin 令牌与 theme.read 权限：主题清单（slug / version / 布局面）属部署面信息，不再公开暴露，鉴权形态与其余读接口（如 metric.read）对齐；admin 角色经通配符授予 theme.read。

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
  "meta": {"api": "API-THEME-001", "version": "v1", "system": "theme"}
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
| `active` | `string|null` | 当前启用主题；null 表示零呈现 Headless |
| `headless` | `bool` | true 表示未启用任何主题 |
| `total` | `int` | 出厂主题包数量 |
| `items` | `object[]` | slug / version / enabled / meta / surface / family / layouts / active |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/themes
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "active": null,
        "headless": true,
        "total": 4,
        "items": [
            {
                "slug": "glass-front",
                "version": "0.1.0",
                "enabled": true,
                "meta": true,
                "surface": "front",
                "family": "glass",
                "layouts": [
                    "site",
                    "post",
                    "admin",
                    "tool",
                    "install",
                    "deny"
                ],
                "active": false
            }
        ]
    },
    "request_id": "4b8e2c1d9a7f0365",
    "meta": {
        "api": "API-THEME-001",
        "version": "v1",
        "system": "theme"
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

- 0.18.0 初次发布
- 0.61.0 鉴权对齐：auth 由 public 收紧为 admin 并挂载 theme.read 权限（此前该权限声明后从未被任何端点挂载，属死权限）；错误码表补 40101 / 40102 / 40301 / 40303。安装向导若在未认证上下文读取清单，需改走本机 CLI 工具或先建立 admin 会话。

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
