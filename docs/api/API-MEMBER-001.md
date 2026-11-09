# API-MEMBER-001 · 读取成员档案

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-MEMBER-001` |
| 接口名称 | 读取成员档案 |
| 所属系统层 | `member` |
| 请求方法与路径 | `GET /api/v1/member/profile` |
| 绑定操作 | `member.profile` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.10.0` |
| 操作文档 | `docs/api/API-MEMBER-001.md` |

## 2. 功能与适用场景

返回指定用户的经验余额、等级、升级进度与头衔；省略 user_id 时读取当前身份。

**适用场景**：主题在文章页展示作者等级徽章与头衔；控制台展示「我的成长」。

**实现备注**：档案是只读投影：经验来自流水，等级按当前门槛表解释，头衔是手动授予与等级自动授予的并集。头衔不参与任何权限决策。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `user_id` | query | 否 | `string` | 用户 ID 或用户名；匿名请求必填，携带身份时可省略 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-MEMBER-001", "version": "v1", "system": "member"}
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
| `username` | `string` | 用户名 |
| `display_name` | `string` | 显示名 |
| `xp` | `int` | 经验余额 |
| `level` | `int` | 当前等级 |
| `level_name` | `string` | 等级名称 |
| `level_color` | `string` | 等级颜色（由站长定义） |
| `next_level` | `int|null` | 下一等级；已满级为 null |
| `next_level_name` | `string|null` | 下一等级名称 |
| `xp_to_next` | `int` | 距离下一级还差多少经验 |
| `progress` | `int` | 当前等级进度 0–99，满级为 100 |
| `titles` | `array` | 头衔列表，含 source=manual|level |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `40403` | 404 | 资源不存在 |
| `42201` | 422 | 参数校验未通过 |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/member/profile?user_id=1
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "request_id": "1a2b3c4d5e6f7081",
    "data": {
        "user_id": "1",
        "username": "admin",
        "display_name": "站点管理员",
        "xp": 150,
        "level": 2,
        "level_name": "常驻读者",
        "level_color": "#4a9eff",
        "next_level": 3,
        "next_level_name": "活跃作者",
        "xp_to_next": 350,
        "progress": 12,
        "titles": [
            {
                "title_key": "founder",
                "name": "站长",
                "color": "#e0413e",
                "priority": 100,
                "visible": true,
                "min_level": 0,
                "source": "manual"
            }
        ]
    },
    "meta": {
        "api": "API-MEMBER-001",
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

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
