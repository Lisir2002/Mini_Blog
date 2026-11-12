# API-STATE-001 · 查询长任务状态

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-STATE-001` |
| 接口名称 | 查询长任务状态 |
| 所属系统层 | `state` |
| 请求方法与路径 | `GET /api/v1/state/task` |
| 绑定操作 | `state.task.status` |
| 鉴权级别 | `admin` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.12.0` |
| 操作文档 | `docs/api/API-STATE-001.md` |

## 2. 功能与适用场景

按任务 id 读取跨请求的长任务状态：五态相位、持有者、进度、可执行动作与追踪编号。

**适用场景**：后台轮询安装、迁移、发布等长任务的进度；自动化脚本在提交异步任务后确认它卡在系统还是人手里。

**实现备注**：只读接口。任务不存在返回 40403；鉴权与权限仍由 api / permission 层裁决，本接口不自行判断身份。状态存储无数据库时退化为内存，跨进程不可见。 按任务 id 读取跨请求的长任务状态：五态相位、持有者、进度、可执行动作与追踪编号。 v0.48 起被 /console/system 消费：运维可按任务 id 查相位，不存在的任务与格式非法的 id 在页面上分别说明。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `id` | query | 是 | `string` | 任务 id，1–64 位字母、数字、下划线或短横线 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-STATE-001", "version": "v1", "system": "state"}
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
| `phase` | `string` | pending / running / waiting / done / failed |
| `held_by` | `string|null` | waiting 时取 system（等待态）或 user（交互态）；其余相位为 null |
| `scope` | `string` | 固定为 task |
| `id` | `string` | 任务 id |
| `code` | `int` | 失败时的业务码；非失败态恒为 0 |
| `key` | `string` | 文案键，供主题覆写与模型分支 |
| `title` | `string` | 面向人的标题 |
| `message` | `string` | 发生了什么 |
| `hint` | `string` | 下一步该怎么做 |
| `progress` | `object|null` | {done,total,unit,label}；无进度时为 null |
| `actions` | `array` | 可执行动作，最多 4 个：{key,label,kind,href,method} |
| `evidence` | `object` | {trace_id,at,retryable}：追踪编号、发生时间、能否重试 |
| `revision` | `int` | 乐观锁版本，每次推进 +1 |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40101` | 401 | 未认证 |
| `40102` | 401 | 令牌无效或已过期 |
| `40301` | 403 | 无权限执行该操作 |
| `40403` | 404 | 资源不存在 |
| `42201` | 422 | 参数校验未通过 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/state/task?id=install-20261109
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "phase": "running",
        "held_by": null,
        "scope": "task",
        "id": "install-20261109",
        "code": 0,
        "key": "state.running",
        "title": "正在处理",
        "message": "操作正在进行，请不要重复提交。",
        "hint": "完成后本页会自动更新。",
        "progress": {
            "done": 3,
            "total": 5,
            "unit": "步",
            "label": "建表与迁移基线"
        },
        "actions": [],
        "evidence": {
            "trace_id": "9f1c2a7b4e6d8013",
            "at": "2026-11-09T18:30:00+08:00",
            "retryable": false
        },
        "revision": 2
    },
    "request_id": "9f1c2a7b4e6d8013",
    "meta": {
        "api": "API-STATE-001",
        "version": "v1",
        "system": "state"
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

- 0.12.0 初次发布
- 0.48.0 被 /console/system 消费（按任务 id 查相位）。

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
