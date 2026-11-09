# API 接口规范（wx-api-manifest/1.0）

> 本文件是**人写的规范**；`api/registry.json` 是**机器读的清单**；`docs/api/API-*.md` 是**由清单生成的操作文档**；`docs/api/openapi.json` 是**由清单导出的标准 OpenAPI 3.1 描述**。
> 四者关系：规范约束清单 → 清单生成文档与 OpenAPI → 守卫测试校验四者一致。
> 技术栈约束：纯原生 PHP，不引入任何 API 框架、Schema 库、YAML 解析器或 SDK 生成器。

## 1. 借鉴了什么，取舍了什么

| 来源 | 借鉴点 | 本框架的取舍与落地 |
| --- | --- | --- |
| OpenAPI 3.1 / 3.1.1 [^oas] | `info` 元数据、稳定 `operationId`、参数必填性与示例、请求/响应 schema、统一错误 schema、operation 级安全声明 | 用自研 JSON 清单表达同一组字段；`tools/export-openapi.php` 直接导出 3.1 文档（60 条路径 / 67 个 operation），无第三方库 |
| RFC 9457 Problem Details [^rfc9457] | 机器可读错误、稳定 type、字段级明细、扩展字段前向兼容 | 换成更轻的 `code / message / errors / request_id / meta` 信封，语义一一对应但字段更少 |
| Microsoft Azure REST API Guidelines [^ms] | 复数名词资源、路径不放动词、层级要浅、GET/PUT/DELETE 幂等、状态码语义 | 路径统一 `/api/v1/<复数资源>`；装卸这类非 CRUD 操作用 `POST /lifecycle/install` 显式动作资源，并在清单里标注 `operation` |
| Google AIP-190 / AIP-136 / AIP-158 [^aip] | 术语一致、自定义动作显式化、游标分页 | 内容列表采用 `limit + cursor`（base64 编码的 keyset 游标），避免 OFFSET 深翻页 |
| Zalando / 主流工程实践 [^zalando] | kebab-case 路径、429 与 `Retry-After`、废弃期 | 已落地固定窗口限流：429 + `Retry-After` + `X-RateLimit-Limit/Remaining/Reset` |
| Stripe 幂等键 [^stripe] | `Idempotency-Key` 让重试安全；校验失败不入缓存 | 已落地服务端去重：同键同参数重放首次结果（`Idempotency-Replayed: true`），同键不同参数 `40902`，参数校验失败不写缓存，429 不被缓存 |
| WordPress 装卸机制 [^wp] | 状态机、activation / deactivation / uninstall 钩子、路径穿越校验、备份回滚 | 自研 `LifecycleManager`，把生命周期统一到 core / system / theme / plugin / page 五类单元 |

一句话：**借鉴的是约束，不是依赖**。规范全部用原生 PHP + JSON 落地，没有引入任何第三方库。

## 2. 硬性约定

| 维度 | 约定 |
| --- | --- |
| 版本 | URL 路径版本 `/api/v1`；破坏性变更才升主版本，旧版本并行保留并给废弃期 |
| 路径 | kebab-case、复数名词、不在路径中放动词；装卸动作使用显式动作资源 `/lifecycle/install`；同一路径可承载不同方法 |
| 方法 | GET 安全且幂等；POST 用于创建与非幂等动作；DELETE 幂等；禁止用 GET 触发写操作 |
| 状态码 | 200 成功；401/403/404/405/409/422/429 客户端错误；5xx 仅表示服务端故障；**禁止 200 里塞错误** |
| 信封 | 统一 `code / message / data / request_id / meta`；错误额外带 `errors`，可选 `detail` |
| 错误码 | 5 位数字，前两位对齐 HTTP 状态（40401→404、42201→422、50001→500），见下表 |
| 鉴权 | 清单里每个接口必须声明 `auth`：`public` / `user` / `admin`；**v0.4 起为真实执行**，由 `ApiAuthenticatorInterface` 校验令牌与角色。`public` 接口**可选携带身份**：带了合法 Bearer 就注入 `_identity`，不强制认证 |
| 授权 | **v0.6 新增**：需要细粒度授权的接口声明 `permission`（权限点必须在各系统层 `permissions.json` 自报清单内，否则守卫拒绝），由 `AccessControlInterface` 在参数校验之前裁决；未放行返回 `40303`。所有带 `permission` 的接口必须在 `errors` 中列出 `40303` |
| 页面访问（v0.8） | 页面不是 API，但复用同一套身份与授权契约：`page.json` 的 `access.auth` 使用同一组 `public` / `user` / `admin` 级别与同一个 `ApiAuthenticatorInterface`；`access.permission` 由同一个 `AccessControlInterface` 裁决。裁决在 `route.access` 询问点完成并短路，拒绝只产结构化数据 + `deny` 布局槽位（样式归主题），不输出 HTML。详见 `docs/ARCHITECTURE.md` 第 24 节 |
| 写接口边界 | 所有非 GET/HEAD/OPTIONS 操作默认仅本机来源可调用（`config.api.expose_write=false`），公网来源返回 `40302` |
| 二进制通道（v0.13） | `/attach/upload` 与 `/attach/download` **是唯一不经 JSON 信封的路径**：请求体即字节、响应体即字节。守卫与 JSON 接口同一套且顺序相同（限流 → 写边界 → 鉴权 → 权限 → 参数），下载每次请求都重新做授权判定，不得用「拿到过链接」代替授权。响应必须带 `X-Content-Type-Options: nosniff` + `Content-Security-Policy: default-src 'none'; sandbox`，下载用 RFC 5987 `Content-Disposition: attachment; filename*=UTF-8''…`，文件名永不参与落盘路径。失败时才回退 JSON 信封（`Content-Type: application/json`），并**不得**回显字节 |
| 幂等 | 每个接口必须声明 `idempotent` 与 `side_effects`；写操作建议携带 `Idempotency-Key` |
| 限流 | 每次 API 请求计数，响应回带 `X-RateLimit-*`；超阈值返回 `42901` + `Retry-After` |
| 追踪 | 客户端可传 `X-Request-Id`，服务端回填 `request_id` |
| 文档 | **一个接口对应一份文档**，编号即文件名；由清单生成，禁止手工改文档 |

## 3. 统一错误码表

| code | HTTP | 含义 | 典型触发 |
| --- | --- | --- | --- |
| `40401` | 404 | 接口不存在 | 路径未登记 |
| `40402` | 404 | 装卸单元不存在 | activate / verify 目标目录缺失 |
| `40403` | 404 | 资源不存在 | 按 slug 查文章未命中 |
| `40501` | 405 | HTTP 方法不被允许 | 路径存在但方法不匹配 |
| `42201` | 422 | 参数校验未通过 | 缺必填参数、类型错误、非法 slug / 游标、日期格式错误 |
| `40901` | 409 | 状态冲突 | 状态机非法转换、版本未递增、反向依赖未解除 |
| `40902` | 409 | 幂等键重放参数不一致 | 同一 `Idempotency-Key` 携带不同请求体 |
| `40101` | 401 | 未认证 | 受保护接口未携带令牌 |
| `40102` | 401 | 令牌无效或已过期 | 令牌错误 / 过期 / 凭据校验失败 |
| `40301` | 403 | 无权限执行 | 角色不足、源包越界、core 卸载、core 升级未开启 |
| `40302` | 403 | 写接口未开放公网访问 | 非本机来源调用写接口且未开启 `expose_write` |
| `40303` | 403 | 权限决策未放行（fail-closed） | 权限层缺席 / 权限点未声明 / 匿名 / 无策略（abstain）/ 显式 deny |
| `42901` | 429 | 请求过于频繁 | 超出限流阈值 |
| `50001` | 500 | 服务器内部错误 | 文件写入失败、状态落盘失败 |
| `50101` | 501 | 接口已登记但执行者未实现 | 系统层被停用导致 operation 缺失 |

错误码定义在 `systems/Api/Envelope.php`，文档表格与 OpenAPI 响应由生成器直接读取同一张表，避免多处漂移。

## 4. 新增一个接口的 5 步（强制流程）

1. 在 `api/registry.json` 追加一条定义，**必须**包含 `id / name / system / method / path / operation / auth / idempotent / side_effects / since / summary / scenario / params / response_fields / errors / example_request / example_response / notes / changelog / doc`。
2. `id` 全局唯一、`method + path` 唯一、`operation` 唯一——注册表构造时即强校验，重复会直接报错。
3. 在对应系统层的 `boot()` 里 `registerOperation()` 绑定唯一执行者；重复注册报错。
4. 运行 `php tools/gen-api-docs.php` 与 `php tools/export-openapi.php`，生成 `docs/api/<ID>.md`、索引与 `openapi.json`。
5. 运行 `php tests/run.php`，守卫测试会检查 1:1、章节齐全、无孤儿文档、OpenAPI 与清单一致；不通过不得合入。

> 规矩：**先改清单，再生成文档，最后跑守卫**。直接手写文档会被视为破坏单一事实源。

## 5. 兼容性与废弃策略

- **兼容变更**：新增接口、新增可选响应字段、新增可选过滤值 → 沿用当前版本。
- **破坏性变更**：删字段、改类型或含义、把可选参数改为必填、收紧枚举、改鉴权、改副作用 → 升主版本（`/api/v2`），必须同时给出迁移说明、旧版本并行期与明确退役日期。
- 弃用接口必须在清单里标注替代接口与退役日期，并在响应头给出迁移链接；只标「deprecated」而不给日期不算迁移计划 [^compat]。

## 6. v0.4 已关闭的缺口（原 v0.2 待办）

| 原缺口 | 当前状态 |
| --- | --- |
| `auth` 仅声明、未执行 | **已执行**：`ApiAuthenticatorInterface` + MySQL 令牌表（只存 SHA-256 摘要、带过期与撤销），40101 / 40102 / 40301 语义完整 |
| `Idempotency-Key` 服务端去重 | **已落地**：`IdempotencyStore`，重放 / 冲突 / 校验失败不缓存 / 429 不缓存 |
| 429 限流与 `Retry-After` | **已落地**：`RateLimiter`，固定窗口 + 三个 `X-RateLimit-*` 头 |
| 列表接口的游标分页 | **已落地**：`content.posts` 的 `limit + cursor`（keyset，id > cursor） |
| 导出标准 OpenAPI 3.1 文档 | **已落地**：`tools/export-openapi.php` → `docs/api/openapi.json` |
| 请求签名与包仓库协议 | 包签名验签已落地（装卸系统 HMAC-SHA256）；远程仓库协议仍属 v1.0 |

**仍需注意的边界**（不假装完成）：

- 匿名评论 `POST /api/v1/comments` 仍是 public 写接口。v0.13 起不再只有限流与写边界兜底：`comment.auto_publish=false` 时新评论落 `pending`，由 `comments.moderate` 走白名单状态机放行（`pending/published/hidden/spam/deleted`，`deleted` 为终态）。但**频率型滥用仍未拦住**——验证码与敏感词/模型审核列入 v1.0。
- 演示账号 `admin/admin123` 仅用于本地原型，投产前必须删除或改密；数据库口令与签名密钥应由环境注入。
- 限流与幂等缓存当前基于文件存储，多实例部署需换成共享存储（可通过替换对应实现完成，不影响核心）。
- 附件已落地四道校验（字节上限 / 扩展名白名单 / 魔数比对 / 单作者配额）与内容寻址落盘，但**不含病毒扫描与图片二次编码**——`image/*` 仍可能携带畸形负载，生产化应在网关上补一层杀毒与重编码。
- 契约版本协商、灰度发布列入 v1.0。

## 7. 分发执行顺序（安全口径）

```
匹配接口 → 429 限流 → 写接口公网边界 → 鉴权执行 → 参数校验 → 幂等去重 → 唯一 operation → 统一信封
```

鉴权刻意排在参数校验之前：未认证者不应探知接口的参数要求。身份以 `_identity` 注入 `input`，业务执行者不解析请求头。`ApiOperationException` / `LifecycleException` 携带 5 位业务码，`InvalidArgumentException` 统一映射 `42201`。

## 8. 二进制通道的例外契约（v0.13）

正文接口全部走 JSON 信封。**只有附件上传与下载两条路径例外**，因为把字节塞进 JSON（base64）会让体积膨胀 33%、把流式读取变成全量驻留内存，且无法用 `Content-Disposition` 交回文件名。方向也不对称：上传的**请求体是字节、响应仍是 JSON 元数据**（要拿回 `id` 与 `url`）；下载的**响应体是字节**。例外被严格限界：

| 维度 | 约定 | 反例（禁止） |
| --- | --- | --- |
| 路径 | 固定为 `/attach/upload`、`/attach/download`；由 `systems/Attachment/AttachmentRoute` 在 `route.resolve`（优先级 6）认领 | 在 `/api/v1/*` 下开二进制端点；为新需求再开第三条字节路径 |
| 守卫 | 复用 `RequestGuard`：限流 → 写边界 → 鉴权 → 权限 → 参数，与 JSON 接口**同一套、同一顺序** | 为了「简单」绕过守卫直读 `$_FILES` |
| 请求 | 上传：`multipart/form-data` 或原始字节体，元数据走查询参数 / 表单字段；下载：`?id=<n>`，图片类可加 `disposition=inline`。不要求 `Content-Type: application/json` | 用 GET 触发上传；允许客户端指定落盘名 |
| 响应头 | `X-Content-Type-Options: nosniff`、`Content-Security-Policy: default-src 'none'; sandbox`、`Content-Disposition: attachment; filename*=UTF-8''<编码名>`（仅 `image/*` 且显式 `inline` 才给 `inline`）；缓存按可见性分流：公开 `public, max-age=3600`，私有 `private, no-store` | 省略 `nosniff`（浏览器可把 `text/plain` 嗅探成 HTML）；对非图片给 `inline` |
| 布局 | 一律 `setLayout('binary')`，`render.should_render` 见 `binary` 即跳过渲染。**失败响应同样如此**，否则错误页会被主题包成整页 HTML | 成功走 binary、失败回 site |
| 失败 | 才回退 JSON 信封（`code/message/errors`），`Content-Type: application/json`，**不回显任何字节** | 用 200 + HTML 错误页代替 4xx |
| 授权 | 每次下载都重读元数据（软删即 `40403`）。公开附件直接给字节；**私有附件必须登录**（否则 `40101`），本人直通，他人需 `attachment.read`（否则 `40303`）。签名 URL 不在此版本引入 | 把「链接已发出」当作授权结果（私有附件会因此永久泄漏）；用不可猜的 id 冒充访问控制 |
| 落盘 | 元数据入库，字节按 SHA-256 内容寻址存 `storage/attachments/<aa>/<bb>/<sha256>`；**文件名永不进路径** | 用原始文件名拼路径（`../../` 直接穿越） |

一句话：**不给文件开第二条守卫路径**。二进制通道是同一套守卫的第二种载荷，不是第二套规则。

---

[^oas]: OpenAPI Specification v3.1.1，`info` / `paths` / `components` / `security` / `operationId` 等固定字段。<https://spec.openapis.org/oas/v3.1.1>；中文版 <https://openapi.apifox.cn/>
[^rfc9457]: RFC 9457 Problem Details：`type / title / status / detail / instance` 与扩展字段，机器可读错误体。参见 <https://www.digitalapplied.com/blog/rest-api-design-2026-engineering-reference-best-practices>
[^ms]: Microsoft Azure Web API Design Best Practices：复数名词、避免路径动词、无状态、HTTP 动词语义。<https://docs.microsoft.com/en-us/azure/architecture/best-practices/api-design>
[^aip]: Google AIP-190 命名一致性、AIP-136 自定义动作、AIP-158 游标分页，汇编见 <https://github.com/Cristhianzl/claude-skills-czl/blob/main/configs/agnostic/skills/api-design/references/rest-conventions.md>
[^zalando]: Zalando RESTful API Guidelines 与 2026 REST 工程实践汇编：kebab-case、幂等、429、6–12 个月废弃期。<https://github.com/Cristhianzl/claude-skills-czl/blob/main/configs/agnostic/skills/api-design/references/rest-conventions.md>
[^stripe]: Stripe `Idempotency-Key` 与「校验失败不入缓存」语义，参见 <https://www.digitalapplied.com/blog/rest-api-design-2026-engineering-reference-best-practices>
[^wp]: WordPress 插件生命周期与 upgrader 钩子、`validate_file` 防目录穿越。<https://deepwiki.com/WordPress/wordpress-develop/3.1-plugin-lifecycle>；<https://holdmywp.com/en/documents/hook-and-filter-reference/>
[^compat]: 兼容性与破坏性变更策略参考 <https://macmyths.com/modern-api-design-for-2026-a-practical-standards-first-guide>
