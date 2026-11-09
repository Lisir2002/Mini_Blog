# API-LC-002 · 安装单元

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-LC-002` |
| 接口名称 | 安装单元 |
| 所属系统层 | `lifecycle` |
| 请求方法与路径 | `POST /api/v1/lifecycle/install` |
| 绑定操作 | `lifecycle.install` |
| 鉴权级别 | `admin` |
| 所需权限 | `lifecycle.install` |
| 接口版本 | `v1` |
| 起始版本 | `0.2.0` |
| 操作文档 | `docs/api/API-LC-002.md` |

## 2. 功能与适用场景

把源包经暂存区校验后安装到对应类型目录，状态由 absent 转为 installed。

**适用场景**：上传并接入一个新插件 / 主题 / 独立页 / 系统层；安装后还需调用 activate 才会装载。

**实现备注**：执行链：路径穿越校验 → 依赖校验 → 哈希清单校验 → 暂存区复制 → 目标写入 → on_install 钩子 → 状态落盘；任一步失败即清理目标目录并回滚。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `type` | body | 是 | `string` | system | theme | plugin | page（core 不可安装，只能升级） |
| `source` | body | 是 | `string` | 源包目录，必须位于站点根目录内 |
| `slug` | body | 否 | `string` | 单元标识；缺省时取包清单的 slug |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-LC-002", "version": "v1", "system": "lifecycle"}
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
| `type / slug / version / state` | `string` | 安装后的单元记录 |
| `installed_at` | `string` | ISO 8601 安装时间 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40301` | 403 | 无权限执行该操作 |
| `40901` | 409 | 状态冲突 |
| `42201` | 422 | 参数校验未通过 |
| `50001` | 500 | 服务器内部错误 |
| `40303` | 403 | 权限决策未放行（fail-closed） |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/lifecycle/install  {"type":"plugin","source":"storage/packages/demo","slug":"demo"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "type": "plugin",
        "slug": "demo",
        "version": "1.0.0",
        "state": "installed",
        "installed_at": "2026-11-08T23:10:09+08:00",
        "updated_at": "2026-11-08T23:10:09+08:00",
        "backup": null
    },
    "request_id": "f3b8d2e6a1c74095",
    "meta": {
        "api": "API-LC-002",
        "version": "v1",
        "system": "lifecycle"
    }
}
```

## 7. 幂等性与副作用

| 项 | 值 |
| --- | --- |
| 幂等 | 否 |
| 副作用 | 写文件系统（安装 / 升级 / 卸载 / 回滚） |
| 重试建议 | 不可盲目重试；请先查询单元状态，或携带幂等键后由服务端去重。 |

写操作建议携带 `Idempotency-Key`：服务端已实现去重（同键同参数重放首次结果，同键不同参数返回 `40902`）；参数校验失败不写入缓存，429 响应不被缓存。写接口默认仅本机来源可调用（`config.api.expose_write`）。

## 8. 变更记录

- 0.2.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
