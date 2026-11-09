# Mini Blog _(Mini_Blog)_

![version](https://img.shields.io/badge/version-0.11.0-1f6f54)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL%208.0%2B%20%2F%20MariaDB%2010.6%2B-4479A1?logo=mysql&logoColor=white)
![tests](https://img.shields.io/badge/tests-181%20passing-2ea44f)
![dependencies](https://img.shields.io/badge/dependencies-0-2ea44f)
![license](https://img.shields.io/badge/license-MIT-blue)
![PRs](https://img.shields.io/badge/PRs-welcome-brightgreen)

零依赖、全自研的 PHP 博客框架：核心只做装配与调度，能力、呈现、接口全部可插拔。

Mini Blog 不是又一个 CMS。它把「博客」拆成四层——系统核心、系统层、承载包、主题/插件/独立页——层与层之间只通过契约、事件、装配清单和数据接口通信。框架本身不输出一个 HTML 标签、不含一行 CSS；默认状态下整站就是一个纯 JSON 服务，接入主题之后才成为你看到的博客。

它用原生 PHP 8.1+ 写成，没有 Composer、没有 vendor、没有模板引擎、没有 ORM。所有能力都是可装卸的系统层，所有样式都归主题包，所有交互都是登记在册的接口。**新增一个系统层不需要改内核一行代码。**

> 设计信条：**高度自定义，万象皆接口**。
> 仓库：<https://github.com/Lisir2002/Mini_Blog>（`main`，Public）

---

## 目录

- [安全](#安全)
- [背景](#背景)
- [安装](#安装)
- [使用](#使用)
- [架构](#架构)
- [质量与验证](#质量与验证)
- [工程规矩](#工程规矩)
- [项目结构](#项目结构)
- [文档地图](#文档地图)
- [路线图](#路线图)
- [贡献](#贡献)
- [维护者](#维护者)
- [致谢](#致谢)
- [许可](#许可)

---

## 安全

Mini Blog 是一个**可运行的内核原型**，不是开箱即用的生产 CMS。部署前请至少完成：

- **删除或改密演示账号** `admin / admin123`，它只用于本地验证。
- **数据库口令、签名密钥由环境注入**，不要提交到仓库。
- **写接口默认只允许本机来源**（`40302`），公网部署须显式配置并配合鉴权。
- 真实配置写入 `config/local.php`（已被 `.gitignore` 忽略），不要改带注释的主配置。

发现安全问题请不要开公开 Issue，直接通过 GitHub 私信维护者。

---

## 背景

现成 CMS 的扩展点通常绑死在模板语法或插件 API 上：想改后台布局要动主题引擎，想加一种内容类型要装插件再写钩子，想替换存储要顺着框架的 ORM 走。扩展越多，内核越重，最后没人敢升级。

Mini Blog 的出发点是相反的：**内核只保留「装配 + 调度」两件事**，其余全部下沉为可替换的单元。

| 决策 | 做法 |
| --- | --- |
| 能力不是内核的一部分 | 日志、用户、安全、内容、评论、权限、成员、版本都是 `systems/` 下的系统层 |
| 呈现不是框架的一部分 | 零主题时输出纯 JSON；HTML/CSS/JS 只允许存在于 `themes/` |
| 扩展不是改代码 | 新建目录 + `system.json`，内核零改动；接口在清单登记后自动生成文档 |
| 升级不是重装 | 迁移台账 + checksum 校验 + 版本快照 + 健康门禁，可升级也可回退 |
| 质量不是口头承诺 | 181 项自动化守卫 + 单文件职责审计，违规即失败 |

**它不做什么**：不内置页面构建器，不绑定某个前端框架，不提供多站点与云托管，不在核心里写 SQL。

> 命名空间 `Wx\` 是项目早期（「万象」）的缩写，为兼容已部署实例而保留；品牌与包名已统一为 **Mini Blog** / `miniblog-core`，不影响自动加载。

---

## 安装

### 依赖

| 组件 | 最低版本 | 说明 |
| --- | --- | --- |
| PHP | 8.1+ | 需 `pdo_mysql`、`mbstring`、`openssl`、`json` |
| MySQL / MariaDB | 8.0+ / 10.6+ | InnoDB、`utf8mb4`；无库时可回退内存适配器 |
| Web 服务器 | 任意 | Nginx / Apache / Caddy，文档根指向 `public/` |
| Node.js | 18+ | **仅**生成文档与截图时需要，运行时不依赖 |

没有 Composer 安装步骤，也没有 `vendor/` 目录。

### 开发模式（3 步）

```bash
git clone https://github.com/Lisir2002/Mini_Blog.git
cd Mini_Blog

# 1) 建库
mysql -uroot <<'SQL'
CREATE DATABASE IF NOT EXISTS wx_blog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wx'@'127.0.0.1' IDENTIFIED BY 'wx_pass_2026';
GRANT ALL PRIVILEGES ON wx_blog.* TO 'wx'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# 2) 启动（public/index.php 同时作为内置服务器路由脚本）
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

这是刻意的默认状态：`theme.active = null`，框架退化为 Headless 数据服务。文章标题来自内容系统层的 `ContentRepositoryInterface`（真实 MySQL），首页包自身不持有任何内容数据。

### 生产模式：五步安装向导

把 Web 根指向 `public/` 后访问 `/install`，向导会依次完成：**环境检查 → 数据库连接 → 管理员账号 → 建表与迁移基线 → 完成并加锁**。

| 约束 | 表现 |
| --- | --- |
| 只写覆盖层 | 生成 `config/local.php`（0600），删除即回默认，主配置一字不动 |
| 一次性令牌 | `storage/install.token`（0600），写动作必须携带，且只对本机来源返回 |
| 装完即锁 | 生成 `storage/install.lock`，此后 `/install` 整体返回 404 |

### 不重装升级与回退

版本系统是第 14 个系统层 `release`，文件替换委托给元层 `lifecycle`：

```bash
# 预检：会执行哪些迁移、升级哪些单元
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"source":"/path/to/miniblog-package"}' http://127.0.0.1:8080/api/v1/release/plan

# 升级：迁移 → 系统层 → core → 健康门禁（失败自动逆向回退）
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"source":"/path/to/miniblog-package"}' http://127.0.0.1:8080/api/v1/release/upgrade

# 回退：先预检 down 脚本是否齐备，缺则拒绝且不动任何文件
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"version":"0.10.0"}' http://127.0.0.1:8080/api/v1/release/rollback
```

---

## 使用

### 接入主题

```php
'theme' => ['active' => 'bare'],       // 带样式，原生 PHP 模板，CSS 逐字来自 themes/Bare/style.css
'theme' => ['active' => 'bare-html'],  // 零 CSS 的裸 HTML 主题包
```

| 主题包 | 输出 | 用途 |
| --- | --- | --- |
| `themes/Bare` | 语义 HTML + 一个 `style.css` | 演示「样式完全归主题」 |
| `themes/BareHtml` | 语义 HTML，零样式声明 | 演示「框架不兜底任何 CSS」 |

### 独立页只产数据

**呈现权完全归主题**（规矩 J）：独立页包零 HTML 模板、零 CSS、零 JS，只在 `page.json` 声明 `layout` 槽位并产出结构化数据。

| 路径 | 包 | 布局槽位 | 说明 |
| --- | --- | --- | --- |
| `/` | `pages/Home` | `site` | 首页，数据来自内容层 |
| `/links` | `pages/Links` | `site` | 普通独立页 |
| `/console` | `pages/Console` | `admin` | 控制台，只读 + 令牌查询 |
| `/toolbox` | `pages/Toolbox` | `tool` | 文本小工具，交互脚本来自主题 |
| `/install` | `pages/Install` | `install` | 五步安装向导，装完 404 |

无主题、或主题缺少对应布局时，全部退化为纯 JSON——**框架绝不兜底输出标签或样式**。

### 调用接口

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

统一信封 `code / message / data / request_id / meta`，错误额外带 `errors`。**55 个接口 = 55 份文档 = 55 个 operation**，全部由 `api/registry.json` 单向生成，不手写。

### 两个扩展动作

**新增系统层**：建 `systems/<X>/`，写 `system.json`（`name / class / requires / priority`）和一个 `extends AbstractSystem` 的类。内核零改动——`Database`、`Lifecycle`、`Api`、`Content`、`Release` 都是这样接进来的。

**新增接口**：在 `api/registry.json` 追加定义 → 系统层 `boot()` 里 `registerOperation()` → 跑 `tools/gen-api-docs.php` 与 `tools/export-openapi.php` → `php tests/run.php`。缺任何一环，守卫会失败。

---

## 架构

```mermaid
flowchart TB
    REQ[HTTP 请求] --> R[Router]
    R --> ACC{访问裁决<br/>Authentication + AccessControl}
    ACC -->|页面| PG[独立页包<br/>只产数据 + 声明 layout]
    ACC -->|接口| API[Api Dispatcher<br/>鉴权 → 限流 → 幂等 → 执行]
    PG --> SYS[系统层 ×14]
    API --> SYS
    SYS --> CT[契约 ×13<br/>Container 绑定实现]
    CT --> DATA[(MySQL / 内存回退)]
    SYS -.事件.-> BUS[HookBus]
    THEME[主题包<br/>HTML + CSS + JS] --> PG
    BUS -.装配期订阅.-> SYS
```

### 四层模型

| 层 | 内容 | 约束 |
| --- | --- | --- |
| **L0 系统核心** | `Kernel / Container / HookBus / SystemRegistry / PackageLoader / Router / Config` | 24 个 PHP，已达上限；0 个 CSS/JS、0 条 SQL |
| **L1 系统层** | 日志、数据库、安全、装卸、用户、权限、接口、插件、成员、内容、版本、主题、评论、页面 | 每层自带 `system.json`，按 `priority` 装配 |
| **L2 承载包** | 主题包、插件包、独立页包 | 全部资源（CSS/JS/模板）只允许出现在这里 |
| **L3 数据与呈现** | MySQL 表、主题布局、主题资源 | 表归各系统层自持；呈现归主题 |

### 四张接口网

| 网 | 载体 | 作用 |
| --- | --- | --- |
| 契约面 | `core/Contracts/*`（13 个） | 系统层之间唯一可见的类型；容器运行时绑定 10 个实现 |
| 事件面 | `HookBus` | 优先级 + 可中断；插件只能改数据，不能改装配 |
| 装配面 | `*/system.json`、`page.json`、`theme.json` | 声明依赖、优先级、路由、权限、布局槽位 |
| 数据面 | `DatabaseInterface` | 核心不认识 MySQL；无 DSN 时各层自动回退内存实现 |

实测装配顺序：

```
log → database → security → lifecycle → user → permission → api → plugin
    → member → content → release → theme → comment → page
```

---

## 质量与验证

所有结论都是**可重跑的文本证据**，不依赖截图。

![守卫数量随版本增长](docs/assets/guards-growth.svg)

| 版本 | 守卫数 | 增量 | 主题 |
| --- | ---: | ---: | --- |
| v0.6 | 83 | — | 呈现权归主题 + 权限三层模型 |
| v0.7 | 98 | +15 | 角色继承、决策缓存、字段级权限 |
| v0.8 | 121 | +23 | 页面身份清单化、契约覆盖鉴权 |
| v0.9 | 144 | +23 | 用户组、页面会话、可换缓存后端 |
| v0.10 | 170 | +26 | 头衔 / 经验 / 等级，身份与授权解耦 |
| v0.11 | 181 | +11 | 五步安装向导、不重装升级与回退 |

| 指标 | 当前值 | 验证方式 |
| --- | ---: | --- |
| 守卫 / 分组 | **181 / 38** | `php tests/run.php` |
| PHP 文件 / 有效行 | **201 / 12,160** | `php tools/audit-responsibility.php` |
| 最大单文件 | **185 行** | 同上（预算 190，超限即失败） |
| 职责违规 / 孤儿协作 | **0 / 0** | 同上，非 0 退出 |
| 系统层 | **14** | 装配顺序断言 |
| 接口 / 文档 / operation | **55 / 55 / 55** | 清单四方 1:1 比对 |
| 数据表 | **21** | 各系统层 migrations |
| 权限点 | **31** | 各层 `permissions.json` 自报 |
| 运行时依赖 | **0** | 无 composer / vendor |

```bash
php tests/run.php                    # 181 项守卫，38 个文件
php tools/audit-responsibility.php   # 单文件职责审计
php tools/gen-api-docs.php           # 重新生成 55 份接口文档
php tools/export-openapi.php         # 导出 OpenAPI 3.1
php tools/sign-package.php <目录> <密钥>   # 源包签名
```

守卫覆盖六个面：**存在性**（文件 / 契约 / 清单是否齐）、**一致性**（四方 1:1、文档与码表同源）、**运行时贯通**（真实 HTTP + MySQL 读写）、**故障注入**（拔 DSN、撤销许可、无效令牌、篡改包）、**协作矩阵**（C1–C9）、**项目树纪律**（脚本 / 临时文件 / 日志不入库）。

---

## 工程规矩

16 条规矩全部配有可执行守卫，完整文本见 [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md)。核心几条：

| 规矩 | 内容 |
| --- | --- |
| **B · 纯原生栈** | 禁 Twig / Blade / Smarty / Composer / vendor / ORM；模板是原生 `.php` |
| **D · 核心轻量** | `core/` PHP ≤ 24，当前 24，新增能力只能进系统层或走钩子 |
| **G / J · 呈现归主题** | CSS/JS 只允许在 `themes/`；后台布局同样过主题系统，缺布局退化为纯 JSON |
| **H · 单文件不臃肿** | 行数 / 主体数 / 公开方法 / 扇出 / 职权跨度五指标分层预算，静态审计 |
| **I · 协作全方位验证** | 8 类协作矩阵 + 存在性 / 一致性 / 贯通 / 故障注入四维 |
| **K · 权限三层** | 用户 RBAC + 文章 ACL + 系统自报权限点；三态决策、fail-closed、不可提权 |
| **N · 身份与授权解耦** | 头衔 / 等级 / 经验只描述「是谁」，零权限；授权入口只有权限系统一个 |
| **O · 验证脚本不入项目** | 探针脚本只写 `/tmp`；项目内只允许 `tests/` 与 `tools/` |
| **P · 安装与版本可逆** | 安装五步 + 一次性令牌 + `install.lock`；升级必留快照，缺 down 拒绝回退 |

<details>
<summary>其余规矩（A / C / E / F / L / M）</summary>

| 规矩 | 内容 |
| --- | --- |
| **A** | 验证性截图不存活三轮对话，复核一律用可重跑文本 |
| **C** | 一接口一文档，清单驱动生成，禁止手写 |
| **E** | 新规矩必须同时提交条目 + 守卫 + 全绿记录 |
| **F** | 写接口必须鉴权，且默认不暴露公网 |
| **L** | 页面访问分级在 `page.json` 声明，裁决统一走 `route.access`，策略只能收紧 |
| **M** | 主体可扩展但凭据单一来源；页面会话与 API 令牌同表同撤销 |

</details>

---

## 项目结构

```
Mini_Blog/
├── public/index.php          唯一入口：引导 + run()
├── core/                     系统核心 24 个 PHP：Kernel / Container / HookBus / Router / 13 个契约
├── systems/                  14 个系统层，每层自带 system.json + migrations + lifecycle
│   ├── Log/ Database/ Security/ User/ Permission/     （能力）
│   ├── Content/ Comment/ Member/ Release/             （业务与版本）
│   ├── Theme/ Plugin/ Page/                           （承载）
│   ├── Api/                                           （接口面）
│   └── Lifecycle/                                     （元层：装卸 / 快照 / 版本切换）
├── themes/                   Bare（带样式）· BareHtml（零 CSS）
├── plugins/Hello/            插件示例：只通过钩子改数据
├── pages/                    Home · Links · Console · Toolbox · Install
├── api/registry.json         接口清单（唯一事实源，55 条）
├── config/app.php            装配清单
├── docs/                     架构 / 规矩 / 环境 / 55 份接口文档 / OpenAPI
├── tests/                    run.php + 夹具 + 38 个守卫文件
├── tools/                    文档生成 / OpenAPI 导出 / 签名 / 职责审计
└── storage/                  运行时产物（仅骨架入库）
```

| 目录 | 职责 |
| --- | --- |
| `core/` | 只做装配与调度；0 CSS/JS、0 SQL、24 个 PHP |
| `systems/` | 一切能力；新增目录即新增能力，内核零改动 |
| `themes/` | 一切呈现；HTML / CSS / JS 的唯一合法住所 |
| `pages/` | 路由 + 数据 + 布局槽位声明，不含样式 |
| `docs/` | 架构设计与接口契约；`docs/api/` 全部由清单生成 |
| `tests/` | 可重跑的守卫，不是示例测试 |

---

## 文档地图

| 文档 | 内容 |
| --- | --- |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | 27 章架构设计与每个版本的决策记录 |
| [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) | 工程规矩 A–P 全文与运行记录 |
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | 本机环境、SSH 接入、浏览器配置 |
| [`docs/api/SPEC.md`](docs/api/SPEC.md) | 接口规范：信封、错误码、鉴权、分页、幂等 |
| [`docs/api/README.md`](docs/api/README.md) | 55 个接口索引 |
| [`docs/api/openapi.json`](docs/api/openapi.json) | OpenAPI 3.1（49 路径 / 55 操作） |
| [`docs/architecture.html`](docs/architecture.html) | 可视化架构总览 |

---

## 路线图

**v0.11 已完成**：五步安装向导、迁移台账、checksum 校验、安装基线、健康门禁、按版本快照回退。

**v1.0 之前**：

- [ ] 在线源包下载与包仓库协议（当前 `source` 必须是已就位目录）
- [ ] 升级并发锁与灰度 / 分批发布
- [ ] 匿名评论验证码与内容审核
- [ ] 多实例共享的限流与幂等存储（当前为文件实现）
- [ ] 控制台从只读升级为完整写操作（走受保护 API）
- [ ] 经验流水归档、排行榜游标翻页、等级变更通知

**明确不做**：内置页面构建器、绑定前端框架、核心内写 SQL、多站点托管。

---

## 贡献

欢迎 Issue 与 PR。

1. Fork 并以 `feat/`、`fix/`、`docs/` 前缀开分支。
2. 改代码必须同时补守卫：新增规矩要提交条目 + 断言；新增接口要改清单并重新生成文档。
3. 提交前本地必须全绿：

```bash
php -l 每个改动文件
php tests/run.php
php tools/audit-responsibility.php
```

4. 提交信息用 `type: 说明`（`feat` / `fix` / `docs` / `test` / `chore`）。
5. 探针脚本写在 `/tmp`，不要提交进仓库（规矩 O）。

**好 PR 的样子**：说明「改了什么、为什么、怎么验证」，附上守卫运行结果；不夹带无关格式化。

问题讨论请开 Issue，不要私信堆料。

---

## 维护者

- [@Lisir2002](https://github.com/Lisir2002)

---

## 致谢

- [Standard Readme](https://github.com/RichardLitt/standard-readme)：本 README 的结构依据。
- [Flyway](https://documentation.red-gate.com/flyway)：迁移台账、checksum 校验、baseline 的实践来源。
- [Deployer](https://deployer.org/)：多版本目录与保留策略的启发（本项目用「快照 + 整体替换」实现同等效果）。
- [Shields.io](https://shields.io/)：徽章。

---

## 许可

[MIT](LICENSE) © 2026 Lisir2002

MIT 是 OSI 认可的最宽松许可之一：可商用、可修改、可闭源分发、可再许可，唯一义务是保留版权与许可声明。选它而不是 Apache-2.0，是因为后者附加专利与变更声明义务；比 MIT 更彻底的只有公有领域奉献（Unlicense / CC0），但那会放弃全部著作权且与部分组织的合规政策不兼容。**如果要的是「最开放且仍能被正常采用」，MIT 就是答案。**
