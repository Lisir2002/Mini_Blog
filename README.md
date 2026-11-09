# 万象 · 博客框架（v0.11 内核原型）

> **高度自定义，万象皆接口**
> 纯原生 PHP 8.1+ / MySQL 8.0+（PDO）/ 原生 HTML + CSS + JavaScript 自研，**无模板引擎、无第三方框架、无 composer/vendor**。
> 核心只做装配与调度；一切能力是系统层，一切呈现是承载包，一切交互是接口，一切单元的装卸由元层管辖。
>
> 仓库：<https://github.com/Lisir2002/Mini_Blog>（`main`，Public）

## 快速开始

```bash
cd /workspace

# 1) 建库（已装好 MariaDB 的沙箱里已执行过；换环境时需重跑）
mysql -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS wx_blog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wx'@'127.0.0.1' IDENTIFIED BY 'wx_pass_2026';
GRANT ALL PRIVILEGES ON wx_blog.* TO 'wx'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# 2) 启动
php -S 127.0.0.1:8080 -t public public/index.php
```

打开 <http://127.0.0.1:8080/>，你会看到**纯 JSON，一个 HTML 标签都没有**：

```json
{
  "title": "站点首页",
  "items": ["第一篇文章", "第二篇文章", "这一行由插件系统通过钩子注入"],
  "page": "home"
}
```

这是刻意的默认状态：`theme.active = null`，即**未接入任何主题 → 全站零样式、零 HTML**，框架退化为 Headless 数据服务。文章标题来自内容系统层的 `ContentRepositoryInterface`（真实 MySQL），首页包自己不持有内容数据。

**没接 MySQL 也能启动**：把 `config/app.php` 的 `database.dsn` 留空，各系统层自动回退内存 / 会话适配器，站点仍可运行（这是「契约与实现可分离」的验证点）。

**身份与授权是两件事**：头衔、经验、等级由第 13 个系统层 `member` 管理（共 14 层，第 14 层 `release` 管版本），站长可自定义头衔样式与自动门槛、经验规则、等级名称与经验区间；它们**不产生任何权限**，授权入口仍只有权限系统一个（见规矩 N）。

**本机环境、SSH 接入与浏览器配置**见 [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)：PHP 8.3 / MariaDB 10.11 / Node 22 已就绪，SSH 仅公钥登录（`wx-ssh-setup` 一键恢复），模型操作用 `agent-browser`（Chrome 151），脚本回归用 Playwright（Chromium 145），真实 Chrome 环境用系统 Chrome 144 + 同版 chromedriver。

### 接入主题

```php
'theme' => ['active' => 'bare'],       // 带样式，原生 PHP 模板，CSS 逐字来自 themes/Bare/style.css
'theme' => ['active' => 'bare-html'],  // 零 CSS 的裸 HTML 主题包
```

### 独立页（只产数据，布局一律由主题提供）

**规矩 J**：后台的布局样式同样过主题系统。独立页包零 HTML 模板、零 CSS、零 JS，只在 `page.json` 声明 `layout` 槽位并产出结构化数据；主题包持有全部布局模板与资源。

| 路径 | 包 | 布局槽位 | 说明 |
| --- | --- | --- | --- |
| `/console` | `pages/Console` | `admin` | 站点控制台，只读 + 令牌查询；样式与脚本来自当前主题的 admin 布局 |
| `/toolbox` | `pages/Toolbox` | `tool` | 文本小工具，交互脚本来自主题的 tool 布局 |
| `/install` | `pages/Install` | `install` | 标准化五步安装向导（环境 → 数据库 → 管理员 → 迁移基线 → 加锁），装完自动 404 |
| `/links` | `pages/Links` | `site` | 复用站点布局的普通独立页 |

无主题时以上全部返回纯 JSON；主题未提供对应布局（如 `bare-html` 只有 `site`）时同样退化为纯数据，**框架绝不兜底输出标签或样式**。

### 调用规范化接口

```bash
curl -s http://127.0.0.1:8080/api/v1/system/info
curl -s http://127.0.0.1:8080/api/v1/system/health
curl -s 'http://127.0.0.1:8080/api/v1/content/posts?limit=1'

# 受保护接口：先用演示凭据换令牌
TOKEN=$(curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin123"}' \
  http://127.0.0.1:8080/api/v1/auth/token | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["data"]["token"] ?? "";')
curl -s -H "Authorization: Bearer $TOKEN" http://127.0.0.1:8080/api/v1/lifecycle/units
```

统一信封 `code / message / data / request_id / meta`，错误额外带 `errors`。**55 个接口 / 55 份操作文档 / OpenAPI 3.1**，见 [`docs/api/SPEC.md`](docs/api/SPEC.md)、[`docs/api/README.md`](docs/api/README.md) 与 [`docs/api/openapi.json`](docs/api/openapi.json)。

> 写接口默认仅本机来源可调用（`40302`），受保护接口需 `admin` 令牌。演示账号 `admin/admin123` 只用于本地原型，投产前必须删除或改密。

## 「建议全数采纳」已落地什么

| 原决策 / 建议 | 落地结果 |
| --- | --- |
| 缺「文章 / 内容系统」 | **第 11 个系统层 `content`**：契约 + MySQL 实现 + 自有 migrations + 生命周期钩子 + 4 个接口；内核零改动 |
| 用户 / 评论是内存占位 | **MySQL 全量实现**：`PdoUserProvider` / `PdoCommentStore` / `PdoContentRepository`，5 张系统层自建表（v0.4 当时，v0.8 增至 11 张），真实读写 |
| `auth` 只是声明式标记 | **真实鉴权执行**：令牌落库（只存 SHA-256）、带过期与撤销、角色判定；40101 / 40102 / 40301 |
| 写接口不应暴露公网 | **`config.api.expose_write`** 默认关闭，公网写操作 `40302`；本机 / 显式开启才放行 |
| 会话令牌落库 + 过期 | CSRF 令牌落库、过期、一次性使用；CSP 可按配置注入 |
| 后台 UI 写进核心会破坏 H1 | **可卸载控制台独立页包** `pages/Console` |
| 独立页允许自带 CSS/JS | **`standalone` + `routes` + `assets` 清单声明**，通过 `render.should_render` 裁决点绕过主题 |
| 想要裸 HTML | **`themes/BareHtml`**：零 CSS 主题包，输出语义标签但无任何样式声明 |
| 幂等键 / 429 / 游标分页 / OpenAPI | 全部落地：`Idempotency-Key` 去重、`429 + Retry-After`、keyset 游标、`tools/export-openapi.php` |
| 包签名验签 | HMAC-SHA256（纯原生 `hash_hmac`），`tools/sign-package.php` 生成，篡改即拒绝 |
| 安装向导 | 独立页包 `pages/Install`：五步流程 + 一次性令牌（0600）+ `install.lock`；只写 `config/local.php` 覆盖层，删掉即回默认 |
| 不重装升级 / 回退 | 第 14 系统层 `release`：迁移台账 + checksum 校验 + 安装基线 + 健康门禁；快照按版本归档，可回退到任意装过的版本 |
| PHP 版本 / 命名 / 冲突策略 | 定案 **PHP >= 8.1**、**万象 / `Wx\`**、保留「显式 priority + fail-fast + 留痕」 |

## 立规矩

完整文本见 [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md)，每条都配可执行守卫：

| 规矩 | 口径 |
| --- | --- |
| **A · 验证性截图不存活三轮对话** | 第 4 轮起视为过期，**不得引用、判断或用于新旧对比**；复核改用可重跑的文本证据。截图不入库、不写进文档 |
| **B · 纯原生栈** | 禁 Twig / Blade / Smarty / composer / vendor / 框架 / ORM；模板是原生 `.php`，转义走 `SecurityGuardInterface::escape()` |
| **C · 一接口一文档** | 清单驱动生成，禁止手写；新增接口必须改清单 → 重新生成 → 跑守卫 |
| **D · 核心轻量** | `core/` PHP ≤ 24（**当前 24，已达上限**；新增能力须进系统层或走钩子） |
| **E · 规矩必须有守卫** | 新增规矩须同时提交条目 + 测试断言 + 全绿运行记录 |
| **F · 写接口必须鉴权** | 鉴权先于参数校验；写接口默认不暴露公网 |
| **G · 样式脚本归属** | v0.6 由 J 收紧：**CSS/JS 只允许在 `themes/`**，`core/` `systems/` `pages/` `plugins/` 一律零资源 |
| **H · 单文件不允许职责职权过重** | 行数 / 责任主体 / 扇出 / 职权跨度四指标分层预算；`php tools/audit-responsibility.php` 静态审计，违规即非 0 退出 |
| **I · 相互协作必须全方位验证** | 8 类协作矩阵（C1–C8）+ 存在性 / 一致性 / 运行时贯通 / 故障注入四维 + C9 覆盖守卫 |
| **J · 呈现权完全归主题** | 后台不例外；页面只产数据 + 声明 `layout` 槽位；主题缺布局 → 纯数据，绝不兜底 |
| **K · 权限三层模型** | 用户(RBAC + 角色继承) / 文章(ACL+状态 + `field` 维度) / 系统(自报权限点)；三态决策、fail-closed、扩展点不得提权、决策留痕；决策缓存只缓存基础数据、不缓存最终决策 |
| **L · 页面身份权限清单化** | 每个页面在 `page.json` 声明 `access`（`auth` / `permission` / `local_only`）；裁决统一走 `ApiAuthenticatorInterface` + `AccessControlInterface`，在 `route.access` 短路；策略钩子只能收紧；拒绝页样式同样归主题 `deny` 布局；契约覆盖授权须经运行时身份 |
| **M · 主体可扩展 / 凭据单一来源** | 用户组只是角色来源（`自身 role ∪ 组 role`），不新增决策层；页面会话与 API 令牌同表同撤销，只换 HttpOnly 载体且只用于页面 GET；缓存可换后端但绝不缓存最终决策；字段级写复用同一 `decide(..., field)`，任一字段被拒则整次拒绝；读写两条路径必须认同一个资源标识（统一为 `slug`） |
| **N · 身份与授权解耦** | 头衔 / 等级 / 经验只描述「是谁」，不描述「能做什么」，授权入口仍只有权限系统；经验流水 append-only + `event_key` 幂等 + 每日上限 + 事务；等级 = 经验 + 门槛的解释结果，可重算、只升不降；头衔自动授予不写表、手动授予须显式撤销；系统层内部契约不进容器 |
| **O · 验证脚本不入项目** | 调试 / 冒烟 / 探针脚本只写在 `/tmp`；项目内只允许 `tests/` 守卫与 `tools/` 正式工具。后缀黑名单（`.py` `.sh` `.bak` `.tmp` `.log` `.sqlite` `.dump` …）、根目录与 `tools/` 白名单、守卫命名 `NN[x]-kebab-case.php` 全部由 17 组强制执行；`.log` 仅在 `storage/logs/`、`.sql` 仅在 `systems/*/migrations/` |
| **P · 安装与版本必须可逆** | 安装只走五步（环境 → 数据库 → 管理员 → 迁移 → 加锁），只写 `config/local.php` 与 `install.lock`，写动作须带一次性令牌且仅本机；版本变更必须「迁移有台账、回退有 down、升级前留快照」——checksum 冲突拒绝升级，缺 down 拒绝回退，健康门禁失败自动逆向回退，`keep_releases` 只裁最旧 |

本项目所有验证均为文本证据（`php tests/run.php`、`php -l`、`curl -i` 响应体、`tools/audit-responsibility.php` 输出），**未使用截图**。

## 验证第一性原理

```bash
php tests/run.php                 # 181 项守卫，38 个守卫文件；自动刷新职责/协作审计报告
php tools/audit-responsibility.php # 单文件职责审计：0 违规 / 0 孤儿协作，非 0 退出即失败
php tools/gen-api-docs.php        # 重新生成 55 份接口文档与索引
php tools/export-openapi.php      # 导出 OpenAPI 3.1
php tools/sign-package.php <源包目录> <密钥>   # 生成完整性清单 + 签名
```

| 组 | 覆盖 |
| --- | --- |
| 1–3 | 无主题零样式（运行时 + 静态）、核心零 CSS/JS、有主题时样式 100% 来自主题目录 |
| 4 | 钩子优先级、契约覆盖与冲突拒绝、**14 系统层**拓扑装配、新增系统层自动接入、独立页路由、安全头、日志链路 |
| 5 | 原生栈守卫 + **CSS/JS 只存在于承载包** |
| 6 | **55 个接口** 1:1 文档、统一信封、404/405/422/501 错误码 |
| 7 | 装卸全链路、版本对账、三类安全拒绝 |
| 8 | **MySQL 真实读写**：21 张表、用户/评论/内容绑定、回退适配器、游标分页、文章/评论写入读回、令牌落库、CSRF 一次性 |
| 9 | **鉴权执行与写边界**：40101/40102/40301/40302、CSP 注入 |
| 10 | 幂等键重放与冲突、**429 限流**、OpenAPI 3.1 与清单一致 |
| 11 | bare-html 零样式、布局权归主题、白名单卸载、**包签名验签**、命名定案 |
| 12 | **协作全方位验证**：职责审计可重跑（C0）、契约 / 装配 / 事件 / 接口 / 装卸 / 呈现 / 数据 / 编排 8 类矩阵（C1–C8）、故障注入、**矩阵覆盖守卫**（C9） |
| 13 | **权限三层模型与协作**：三态决策、fail-closed、不可提权、决策留痕、API / 内容 / 装配决策点全覆盖 |
| 14 | **角色继承**：许可并集、祖先链展开、自环 / 成环 / 深度裁决、撤销传播、继承图接口贯通 |
| 15 | **决策缓存与字段级权限**：命中与显式失效、不缓存最终决策、开关一致性、`field` 精确 deny、协作对账 |
| 16 | **页面访问分级**：5 页清单显式声明、公开页零门槛、受保护页短路、Bearer / `X-Api-Token` / Cookie 同源、级别 + 权限双重裁决、`local_only`、撤销许可 fail-closed |
| 17 | **页面策略与呈现**：`page.access.policy` 只能收紧、`extra_permission` 追加、拒绝页 `deny` 布局归主题、缺布局退化纯 JSON、裁决留痕、身份贯通 |
| 18 | **契约覆盖运行时鉴权**：授权台账、无令牌 401 / 无权限 40303、执行者纵深防御、`require_grant` 下未授权覆盖 fail-closed、拔库不破窗、卸载清理 9 张表 |
| 19 | **身份载体同源**：无效令牌与无令牌同为 401、三种载体走同一认证器 |
| 20 | **用户组**：组角色并集、撤销与删组立即失效、显式 deny 优先、策略钩子仍可收紧、分组接口贯通 |
| 21 | **页面会话**：HttpOnly + SameSite、响应体无明文、只用于页面 GET、会话令牌不得当 Bearer、登出与过期、元数据不回显 |
| 22 | **缓存后端**：file 跨实例命中、epoch 失效、开关 / 后端与决策逐项一致、不绕过策略钩子 |
| 23 | **字段级写**：`body` deny 不误伤 `title`、越权整次拒绝、不做部分写入、白名单与状态校验、不带 field 不触发 |
| 24 | **契约覆盖默认安全**：默认 `require_grant=true`、只自动登记已注册系统层、第三方未授权拒绝、撤销后复发拒绝 |
| 25 | **站长自定义权限组**：任意 key / 名称 / 颜色 / 排序、组角色并集、移出与删组立即失效、展示属性不改变决策 |
| 26 | **头衔**：站长定义、手动授予与撤销、等级自动授予、来源标记、**授予前后权限决策逐项一致**、越权与未知头衔 |
| 27 | **经验**：`event_key` 幂等重放、每日上限截断、规则停用、内容事件自动发放、匿名评论跳过 |
| 28 | **等级**：门槛边界、升级判定、只升不降、门槛重复拒绝、删除等级后按相邻等级重解释 |
| 29 | **成员层贯通**：`users.me` 装配快照、停用后退化为 50101、无库回退、权限点自报与撤销后 fail-closed |
| 30 | **项目树纪律**：根目录白名单、脚本 / 临时文件 / 日志 / 转储不入库、`tools/` 只放正式工具、守卫文件统一编号命名 |
| 31 | **版本升级 / 回退**：隔离站点真实走「迁移 → 换单元 → 换 core → 健康门禁」，回退撤销迁移并再次留快照 |
| 32 | **版本安全边界**：校验和冲突 `40904`、缺 down 拒绝回退 `40903`、多快照与 `keep_releases` 裁剪、无库时台账降级为内存且写入明确拒绝 |
| 33 | **安装窗口**：五步结构、令牌仅本机返回且 0600、缺 / 错令牌 403、公网来源整体拒绝、装完向导 404 |
| 34 | **安装步骤**：只写 `config/local.php`（0600，删掉即回默认）、管理员可登录且重复提交幂等、迁移台账登记、加点锁后不可再装 |

实测装配顺序：`log → database → security → lifecycle → user → permission → api → plugin → member → content → release → theme → comment → page`。

## 目录速览

| 路径 | 职责 |
| --- | --- |
| `public/index.php` | 唯一入口，只做引导 + `run()` |
| `core/` | 系统核心：24 个 PHP（已达规矩 D 上限）（Kernel / Container / HookBus / SystemRegistry / PackageLoader / Router + **13 个契约接口文件**，容器运行时绑定 10 个）；0 个 CSS/JS、0 条 SQL |
| `systems/` | **14 个系统层**：Log、User、Security、Comment、Database、Content、Permission、Member（能力）；Theme、Plugin、Page（承载）；Api（接口）；Lifecycle（元层）；Release（版本） |
| `themes/Bare` · `themes/BareHtml` | 主题包：全部 CSS/HTML 归主题；后者零 CSS |
| `plugins/Hello` | 插件包：只通过钩子改数据 |
| `pages/Home` `Links` `Console` `Toolbox` `Install` | 独立页包：自带路由，只产数据 + 声明 layout 槽位，样式全部由主题提供 |
| `api/registry.json` | 接口清单（唯一事实源，55 条） |
| `docs/api/` | `SPEC.md` + 55 份 `API-*.md` + `_TEMPLATE.md` + 索引 + `openapi.json` |
| `docs/CONVENTIONS.md` | 工程规矩 A–P |
| `docs/responsibility-report.json` | 职责与协作审计报告（每次测试自动刷新） |
| `tools/` | 文档生成器、OpenAPI 导出器、源包签名工具、职责审计器（含 `audit/Policy.php`、`CollaborationGraph.php`、`Auditor.php`、`concerns.json`） |
| `tests/` | `run.php`（引导）+ `helpers.php`（断言辅助）+ `fixtures.php`（站点 / 安装夹具）+ `fixtures-release.php`（版本观测）+ `groups/*.php`（38 个守卫文件，181 项守卫） |

## 两个常用扩展动作

**新增系统层**：建 `systems/<X>/` + `system.json`（`name/class/requires/priority`）+ 一个 `extends AbstractSystem` 的类；内核零改动。`Database`、`Lifecycle`、`Api`、`Content` 都是这么接进来的。

**新增接口**：在 `api/registry.json` 追加定义 → 在系统层 `boot()` 里 `registerOperation()` → 跑两个生成脚本 → `php tests/run.php`。

## 当前边界（明确列出，不假装完成）

代码全数自研，是**可运行的内核原型**，不是成品 CMS：

- 控制台目前是**只读 + 令牌查询**的原型，完整后台写操作走受保护 API；匿名评论仍需验证码与内容审核。
- 演示账号 `admin/admin123`、配置里的数据库口令与空签名密钥仅用于本地原型；生产必须改密并由环境注入。
- 限流与幂等缓存基于文件存储，多实例部署需换成共享存储（替换实现即可，核心不动）。
- 权限系统已支持**角色继承**、**用户组（可自定义名称 / 颜色 / 排序）**、**决策缓存（跨请求共享）**、**字段级读写**、**页面会话**与**契约覆盖默认安全**（`require_grant=true`，系统层自动登记、第三方必须授权）。仍**未做组嵌套、跨主机缓存共享、会话多设备管理、字段级写作用于其它业务层**。`abstain` 时已发布文章仍公开可读（如需「未登录一律不可读」，改 `ContentVisibility` 一处即可）。
- 成员系统已支持**头衔（手动 + 等级自动）**、**经验（幂等 / 每日上限 / 事件自动发放）**、**等级（可改门槛、只升不降）**。仍**未做**：经验流水归档、排行榜深翻页游标、等级变更回溯通知、成员独立页与自带样式（展示权归主题）。头衔不参与权限是刻意设计，若要让等级解锁能力，应把等级作为「角色来源」而不是在标签上加判断。
- 安装与版本已支持**五步安装向导**（`config/local.php` + `install.lock` + 一次性令牌）、**不重装升级**（迁移台账 + 文件快照 + 健康门禁）、**回退到指定历史版本**（`keep_releases` 保留 N 份）。仍**未做**：在线下载源包（当前 `source` 必须是已就位的目录）、灰度 / 分批发布、迁移的并发锁（同一时刻只应有一次升级）、跨版本跳版的自动补齐（要求逐版递增）。
- 包仓库协议、多站点、契约版本协商、上传校验、灰度发布列入 v1.0。

完整设计与决策记录见 [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)。
