# API-MEMBER-002 · 经验排行榜

> 本文件由 `tools/gen-api-docs.php` 依据 `api/registry.json` 生成，**请勿手工编辑**。
> 接口清单是唯一事实源；新增或修改接口请先改清单，再重新生成，`tests/run.php` 会阻断文档漂移。

## 1. 接口标识

| 项 | 值 |
| --- | --- |
| 接口编号 | `API-MEMBER-002` |
| 接口名称 | 经验排行榜 |
| 所属系统层 | `member` |
| 请求方法与路径 | `GET /api/v1/member/leaderboard` |
| 绑定操作 | `member.leaderboard` |
| 鉴权级别 | `public` |
| 所需权限 | `无（仅按鉴权级别裁决）` |
| 接口版本 | `v1` |
| 起始版本 | `0.10.0` |
| 操作文档 | `docs/api/API-MEMBER-002.md` |

## 2. 功能与适用场景

按经验降序返回成员排行，含名次、等级与公开身份字段。

**适用场景**：站点侧边栏「活跃成员」、独立页的贡献榜。

**实现备注**：只暴露公开字段，不含角色、邮箱、口令摘要。排序为 xp 降序 + user_id 升序，保证同分时名次稳定。榜单只包含「有成长档案」的人：从没拿过经验的注册用户不会出现，所以空榜的意思是「还没有人获得经验」，不是「站点没有成员」。total 是有档案的成员总数、returned 是本页条数、has_more 决定还有没有下一页；三者分开才能回答「共多少人、看到第几页」。offset 分页在榜单变动时会漏行或重行，需要严格快照请按 xp 游标翻页。

## 3. 请求规范

- 路径版本前缀：`/api/v1`；路径使用 kebab-case 复数资源名，不在路径中放动词。
- 请求体为 JSON（`Content-Type: application/json`）；GET / DELETE 使用查询参数。
- 建议携带 `X-Request-Id`，服务端会原样回填 `request_id`，用于链路追踪。

| 参数 | 位置 | 必填 | 类型 | 说明 |
| --- | --- | --- | --- | --- |
| `limit` | query | 否 | `int` | 返回条数，默认 20，最大 100 |
| `offset` | query | 否 | `int` | 偏移量，默认 0 |

## 4. 响应规范

统一信封（自研结构，对齐 RFC 9457 的机器可读错误思想）：

```json
{
  "code": 0,
  "message": "ok",
  "data": {},
  "request_id": "16 位十六进制",
  "meta": {"api": "API-MEMBER-002", "version": "v1", "system": "member"}
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
| `items` | `array` | 排行条目：rank / user_id / username / display_name / orphan / xp / level / level_name |
| `total` | `int` | 有成长档案的成员总数（不是本页条数） |
| `returned` | `int` | 本页返回条数 |
| `has_more` | `bool` | 后面还有没有下一页 |
| `next_offset` | `int|null` | 下一页的 offset；没有下一页为 null |

## 5. 错误码

| 业务码 | HTTP 状态 | 说明 |
| --- | --- | --- |
| `42901` | 429 | 请求过于频繁 |
| `50001` | 500 | 服务器内部错误 |

错误响应形态：`code` 非 0、`data` 为 `null`、`errors` 给出字段级明细。HTTP 状态与业务码严格对应，禁止「200 里塞错误」。

## 6. 调用示例

**请求**

```http
GET /api/v1/member/leaderboard?limit=2
```

**成功响应**

```json
{
    "code": 0,
    "message": "ok",
    "request_id": "1a2b3c4d5e6f7081",
    "data": {
        "items": [
            {
                "rank": 1,
                "user_id": "1",
                "username": "admin",
                "display_name": "站点管理员",
                "orphan": false,
                "xp": 150,
                "level": 2,
                "level_name": "常驻读者"
            }
        ],
        "total": 37,
        "returned": 1,
        "has_more": true,
        "next_offset": 1
    },
    "meta": {
        "api": "API-MEMBER-002",
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
- 0.41.0 total 改为有档案的成员总数（原实现返回本页条数），补 returned / has_more / next_offset 与条目 orphan

> 破坏性变更（删字段、改类型、改鉴权、改副作用）必须升接口主版本并给出迁移说明与废弃期；
> 追加可选字段、新增接口、新增可选过滤值属于兼容性变更，沿用当前版本。
