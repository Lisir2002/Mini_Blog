# 主题与元主题

主题包是全站**唯一**有权输出 HTML / CSS / JS 的地方（规矩 J）。框架没有默认主题、没有兜底模板：未启用主题时整站退化为纯 JSON 数据服务；主题未声明某个布局槽位时，该页面同样退化为纯数据，核心绝不补标签。

## 元主题（初始化主题）

**元主题**是随核心出厂分发、无需安装、作为某呈现面初始化基线的第一款主题。它是主题清单 `theme.json` 的一等契约字段，不是新的系统层、接口、规矩字母或激活机制：

| 字段 | 取值 | 含义 |
| --- | --- | --- |
| `meta` | `true` | 出厂元主题；非元主题（示例主题）不带此字段 |
| `surface` | `front` / `back` | 主打呈现面：访客门户 / 后台管理 |
| `family` | `glass` | 材质家族；同家族的两款主题共享**逐字同源**的 `glass.css` |

三问法（与判数据系统、性能系统同一把尺子）：元主题无独立生命周期（随主题系统演化）、不是被其它系统调用的能力（是描述性契约）、不可整体替换（是主题自身的属性）。故不进 `systems/`、不新增第 18 层、不碰 `core/`。

**成对出厂**：前端第一款 `GlassFront` 与后端第一款 `Glass` 同属 `family=glass`「磨砂玻璃材质质感」。两者能力对等——都声明七布局、都可独立作为初始化基线整体启用；区别只在 `surface` 标注的主打呈现面，以及各自侧重的内容（前端门户 / 后台仪表盘）。

**初始化 ≠ 强制启用**：`config/app.php` 的 `theme.active` 默认值仍是 `null`（Headless 根基，规矩 J 有大量守卫支撑）。元主题是「开箱即在、一行启用」的基线，启用权归配置 / 装卸系统裁决，不由框架越权决定。

```bash
php tools/theme.php list      # 枚举出厂主题及 meta / surface / family / 布局
php tools/theme.php verify    # 复核成对 / 同源 / 覆盖面 / 零外链 / 默认 Headless
```

`verify` 是纯静态只读：元主题是「随核心出厂」的事实，不依赖运行时装配或数据库连接。

## 四款主题包

| 主题 | 元主题 | surface | 布局槽位 | 定位 |
| --- | --- | --- | --- | --- |
| `GlassFront` | 是 | `front` | `site / post / admin / tool / install / deny` | 第一款前端主题：磨砂玻璃访客门户 |
| `Glass` | 是 | `back` | `site / post / admin / tool / install / deny` | 第一款后台主题：系统层清单驱动的仪表盘 |
| `Bare` | 否 | — | `site / admin / tool / install` | 示例主题：语义 HTML + 一个 `style.css`，演示样式完全归主题 |
| `BareHtml` | 否 | — | `site` | 裸 HTML、零 CSS / 零 class / 零脚本，证明「裸 HTML 也要靠主题包」 |

四者都只实现 `PackageInterface` 并自行绑定 `RendererInterface`，通过 `theme.layouts` 钩子自报可用槽位。切换主题只改 `config/app.php` 的 `theme.active`，核心与其它系统层零改动。`Bare` / `BareHtml` 非元主题，仅演示布局权归主题。

## 磨砂玻璃设计系统（family=glass）

`GlassFront` 与 `Glass` 的 `assets/glass.css` **逐字同源**（`24a` 守卫断言字节相等）——同一材质质感是可机器验证的事实，不是「长得像」。三个要素：

| 要素 | 落地 |
| --- | --- |
| 半透明玻璃面 | `--g-surface: rgba(255,255,255,.07)` + 1px 高光边 `rgba(255,255,255,.16)` + 内外双层阴影 |
| 背景模糊 | `backdrop-filter: blur(22px) saturate(160%)`（含 `-webkit-` 前缀） |
| 可模糊的内容 | `.glass-aurora` 三层纯 CSS 径向渐变光晕，缓慢漂移；零图片、零外部字体、零框架 |

`glass.css` 是**材质层**，两款元主题共用；`front.css` / `admin.css` 是各自的**结构层**，只负责呈现面的布局，不重复定义玻璃质感。降级与可访问性同为硬要求：

- `@supports not (backdrop-filter)` → 玻璃面改为不透明深色 `rgba(22,28,50,.97)`；
- `@media (prefers-reduced-transparency: reduce)` → 关闭模糊、降低光晕不透明度；
- `@media (prefers-reduced-motion: reduce)` → 关闭光晕动画与过渡；
- 状态五态沿用 `role="alert" / "status"`、`aria-live`、`aria-busy`；焦点有 `:focus-visible` 描边。

## 第一款前端元主题 GlassFront：访客门户

`front` 呈现面以门户为重心：顶部品牌导航、Hero、站点概览条、文章卡片流、文章正文（正文 token → 表情）、评论树与五态。数据全部来自既有公开接口，主题不新增接口、不新增系统层、不写一条 SQL、不内置业务数据。

| 区域 | 来源 | 说明 |
| --- | --- | --- |
| 文章卡片流 | `content.posts`（public GET） | 标题 / 摘要 / 作者 / 日期 / `slug` 链接均取自接口字段 |
| 站点概览 | `content.stats` / `system.info` / `emojis`（public GET） | 已发布文章数、系统层、接口数、可用表情数 |
| 文章正文 / 评论 / 表情 | 页面 payload（`post` / `comments` / `state`） | 服务端原生 PHP 模板直出，无脚本也能评论 |
| 五态反馈 | 页面 payload `state` | 与后台共用同一套状态片段协议 |

**渐进增强**：服务端模板先用结构化数据直出可读内容，`front.js` 再把文章流与概览换成接口真实数据；无脚本、接口不可用时页面依然可读，不会产生空白门户。友链页等复用 `site` 布局但无门户容器，脚本据此不发请求。

`24b` 守卫逐条比对 `front.js` 的 `path` 与 `api/registry.json` 的**公开** GET 接口，确保主题不臆造端点、无写操作。安全边界：输出一律 `textContent` / `createElement`，不注入 HTML、不落盘、不用 `eval`；`body` 上的 `data-surface` / `data-family` / `data-meta` 是元主题身份的机器可验证标记。

## 第一款后端元主题 Glass：后台仪表盘

`back` 呈现面以控制台为重心，数据来自 `api/registry.json` 已登记的既有接口：

| 区域 | 来源接口 | 说明 |
| --- | --- | --- |
| 概览指标 | `system.info` / `system.health` / `permission.catalog` / `content.stats` | 系统层、接口、契约、`SHOW TABLES` 原值、权限点、已发布文章数 |
| 系统层卡片 | `system.layers` 的 `order` | 按装配顺序生成；`LAYER_META` 只提供中文名与分组，未知层走 `FALLBACK`，系统层增减时后台自动跟随 |
| 接口探针 | 各系统层自己的 GET 接口 | 按「系统 / 内容 / 身份与权限 / 成员 / 运行」分组；`auth: true` 的接口需 Bearer 令牌 |
| 响应明细 | 点击卡片或探针后的真实响应 | 统一显示 HTTP 状态 + JSON 信封 |

`23b` 守卫逐条比对 `admin.js` 中的 `path` / `probe` 与 `api/registry.json`，确保主题**不臆造接口**；令牌只存于页面内存变量，输出一律走 `textContent`。

前端元主题 `GlassFront` 覆盖七布局以求「初始化即可用」，但其 `admin / tool / install` 只做通用区块 / 状态协议的门户一致外观，不复制后台仪表盘——仪表盘是 `back` 元主题的职责。

## 启用与收窄

```php
// config/app.php —— 二选一，都是可独立承载全站的初始化基线
'theme' => ['active' => 'glass-front'],  // 访客门户为主
// 'theme' => ['active' => 'glass'],     // 后台仪表盘为主
```

若只想让某主题负责单一呈现面：把其 `LAYOUTS` 与 `theme.json` 的 `layouts` 收窄为对应槽位即可。按规矩 J，未提供的槽位不会被核心兜底，而是自动退化为纯数据——这与 `BareHtml` 只提供 `site` 是同一套机制。

## 主题接口与可视化操作（v0.18）

审计发现主题在五张接口网里只占契约面 / 事件面 / 装配面，**接口面是空的**：审计当时 67 个 operation 无一属于主题，`system.info` 只顺带返回一个 `theme_active` 标量；后台也没有任何主题可视化操作，切换主题只能手改 `config/app.php`。三问法判定：主题切换无独立生命周期（不新增第 18 层）、是被后台 / 安装向导 / CLI 共同调用的能力（应进 `systems/Theme`）、写入策略应可替换（用容器契约）。故补齐**系统层自报接口 + 主题包消费接口**，两边职责不混。

| 接口 | 方法 / 鉴权 | 职责 |
| --- | --- | --- |
| `theme.list` | `GET /api/v1/themes` · public | 静态枚举各主题包的 `theme.json`：slug / version / enabled / meta / surface / family / layouts，以及当前 `active` 与 `headless`。不引导主题包、不连库 |
| `theme.activate` | `POST /api/v1/themes/active` · admin + `theme.manage` | 把启用态原子写入 `config/local.php`；留空 `slug` 停用主题回到 Headless |

**写入只碰本地覆盖层**：`core/Config` 早已支持 `config/local.php`（「安装器 / 运维只写 local.php，删掉即回到默认」），主配置 `theme.active` 恒为 `null`，Headless 根基不动，删除 local.php 天然回退。写入是临时文件 + `rename` 的原子替换，失败时原文件不动；写入前经既有 `package.enabled` 钩子询问装卸系统准入，切到被停用主题返回 `40901`，不硬依赖 `lifecycle` 层。两个能力各有一个契约（`ThemeCatalogInterface` / `ThemeActivationInterface`），注册进容器后可被更高优先级替换；`core/` 仍 24 个 PHP 文件。

**双台账对齐**：`lifecycle.activate` 只恢复装配准入（写 `storage/lifecycle/state.json`），不改变当前启用主题；`theme.activate` 是唯一启用源写入口。`lifecycle.deactivate` 当前启用主题会被拒绝（`40901`），避免静默退化 Headless。三处对「哪个主题生效」不再各说各话。

**生效时机诚实可验证**：当前请求的 `Config` 已缓存，新主题在**下次请求**装配时生效，响应以 `effective=next_request` 明示，不假装即时切换。

**可视化操作在 Glass 后台**：导航新增「主题」分区，卡片由 `theme.list` 的清单生成（元主题 / 示例主题标识、`surface / family / version`、布局槽位、启用态高亮），「启用」与「停用主题（回到 Headless）」走 `theme.activate`，按钮标注需令牌，结果实时显示 HTTP 状态与 JSON 信封。输出一律 `textContent`，令牌只存页面内存，CSS/JS 仍只在 `themes/`，主题包不注册接口（规矩 J）。`25a` / `25b` 两组守卫逐条核对清单字段、鉴权、幂等、白名单、双台账与 UI 路径。

## 明确不做

- 不新增第 18 个系统层、不改 `core/`（仍 24 个 PHP 文件）；接口增量归已存在的 `theme` 系统层自报，主题包只消费、不注册；
- 不改 `theme.active` 默认值（仍 `null` / Headless）；启用写 `config/local.php` 覆盖层，不强制启用元主题；
- 不内置业务数据、不复制系统层清单、不把接口路径写进服务端 PHP 模板；
- 不让两款元主题跨包引用（各自自包含），`glass.css` 以「逐字同源」而非共享文件保证材质一致；
- 不引入 CDN、字体服务、图片资源、前端框架或模板引擎；
- 不做主题内的写操作后台：启用 / 停用是 `theme.activate` 的职责，主题包只呈现操作界面、不自行写配置；
- 不做请求级主题预览（会引入第二套启用态，破坏「`theme.active` 是唯一事实源」）；
- 不用截图作为证据；结论一律由 `php tests/run.php`、`php tools/theme.php verify` 与 `php tools/audit-responsibility.php` 复现。

## 验证

```bash
php tools/theme.php verify            # 元主题出厂契约（默认 Headless 未改）
php tests/run.php                    # 23a/23b（Glass）、24a/24b（元主题 / GlassFront）、25a/25b（接口 / 可视化操作）
php tools/journey.php                # 22 步全链路旅程：装完 → 建文章/评论/附件/表情 → 前后台主题 → 停用回 Headless
php tools/facts.php verify           # 计数唯一真源对账（文档数字 == 事实）
php tools/audit-responsibility.php   # 单文件职责预算（themes/ 150 行）
php -l themes/Glass/*.php
```

关联：[架构第 33 节](ARCHITECTURE.md) · [规矩 J](CONVENTIONS.md) · [接口规范](api/SPEC.md) · [接口索引](api/README.md)
