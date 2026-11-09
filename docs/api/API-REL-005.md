# API-REL-005 · 回退到指定版本

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-REL-005` |
| 接口名称 | 回退到指定版本 |
| 所属系统层 | `release` |
| 请求方法与路径 | `POST /api/v1/release/rollback` |
| 绑定操作 | `release.rollback` |
| 鉴权级别 | `admin` |
| 所需权限 | `release.rollback` |
| 接口版本 | `v1` |
| 起始版本 | `0.11.0` |
| 操作文档 | `docs/api/API-REL-005.md` |

## 2. 功能与适用场景

把站点程序回退到历史版本：先校验迁移可逆，再换回旧文件，最后逆序执行 down 脚本。

**适用场景**：新版本上线后发现兼容性问题，回到上一个已知可用版本，且数据库一起回退。

**实现备注**：缺少 down 脚本的迁移会让整批回退被拒（40903），绝不出现「代码退了库没退」。回退本身也会先留一份当前版本快照，可再次升回。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `version` | body | 是 | `string` | 目标版本（必须存在快照） |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-REL-005", "version": "v1", "system": "release"}
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
| `version` | `string` | 回退后的版本 |
| `from` | `string` | 回退前的版本 |
| `reverted` | `array` | 已撤销的迁移 id |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40303` | 403 | 权限决策未放行（fail-closed） |
| `40901` | 409 | 状态冲突 |
| `40903` | 409 | 迁移不可逆，拒绝回退 |
| `42201` | 422 | 参数校验未通过 |
| `50003` | 500 | 升级后健康检查未通过 |
| `50004` | 500 | 数据库不可用 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
POST /api/v1/release/rollback  {"version":"0.11.0"}
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "version": "0.11.0",
        "from": "0.12.0",
        "reverted": [
            "member/002_badges.sql"
        ],
        "health": {
            "ok": true,
            "checks": []
        }
    },
    "request_id": "4d5e6f7a8b9c0d1e",
    "meta": {
        "api": "API-REL-005",
        "version": "v1",
        "system": "release"
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

- 0.11.0 初次发布

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
