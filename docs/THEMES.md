# 主题与 Glass 后台主题

主题包是全站**唯一**有权输出 HTML / CSS / JS 的地方（规矩 J）。框架没有默认主题、没有兜底模板：未启用主题时整站退化为纯 JSON 数据服务；主题未声明某个布局槽位时，该页面同样退化为纯数据，核心绝不补标签。

## 三个主题包

| 主题 | 布局槽位 | 定位 |
| --- | --- | --- |
| `Bare` | `site / post / admin / tool / install / deny` | 示例主题：语义 HTML + 一个 `style.css`，演示样式完全归主题 |
| `BareHtml` | `site` | 裸 HTML、零 CSS / 零 class / 零脚本，证明「裸 HTML 也要靠主题包」 |
| `Glass` | `site / post / admin / tool / install / deny` | 首个正式后台主题：半透明磨砂玻璃质感，后台仪表盘由系统层清单驱动 |

三者都只实现 `PackageInterface` 并自行绑定 `RendererInterface`，通过 `theme.layouts` 钩子自报可用槽位。切换主题只改 `config/app.php` 的 `theme.active`，核心与其它系统层零改动。

## Glass 的磨砂玻璃设计系统

样式集中在 `assets/glass.css`，后台结构在 `assets/admin.css`，交互在 `assets/admin.js`。三个要素：

| 要素 | 落地 |
| --- | --- |
| 半透明玻璃面 | `--g-surface: rgba(255,255,255,.07)` + 1px 高光边 `rgba(255,255,255,.16)` + 内外双层阴影 |
| 背景模糊 | `backdrop-filter: blur(22px) saturate(160%)`（含 `-webkit-` 前缀） |
| 可模糊的内容 | `.glass-aurora` 三层纯 CSS 径向渐变光晕，缓慢漂移；零图片、零外部字体、零框架 |

降级与可访问性同为硬要求：

- `@supports not (backdrop-filter)` → 玻璃面改为不透明深色 `rgba(22,28,50,.97)`；
- `@media (prefers-reduced-transparency: reduce)` → 关闭模糊、降低光晕不透明度；
- `@media (prefers-reduced-motion: reduce)` → 关闭光晕动画与过渡；
- 状态五态沿用 `role="alert" / "status"`、`aria-live`、`aria-busy`；焦点有 `:focus-visible` 描边。

## 后台仪表盘：数据来自既有系统

主题不新增接口、不新增系统层、不写一条 SQL，也不内置系统清单。所有数据来自 `api/registry.json` 已登记的既有接口：

| 区域 | 来源接口 | 说明 |
| --- | --- | --- |
| 概览指标 | `system.info` / `system.health` / `permission.catalog` / `content.stats` | 系统层、接口、契约、`SHOW TABLES` 原值、权限点、已发布文章数 |
| 系统层卡片 | `system.layers` 的 `order` | 按装配顺序生成；`LAYER_META` 只提供中文名与分组，未知层走 `FALLBACK`，系统层增减时后台自动跟随 |
| 接口探针 | 各系统层自己的 GET 接口 | 按「系统 / 内容 / 身份与权限 / 成员 / 运行」分组；`auth: true` 的接口需 Bearer 令牌 |
| 响应明细 | 点击卡片或探针后的真实响应 | 统一显示 HTTP 状态 + JSON 信封 |

`23b` 守卫逐条比对 `admin.js` 中的 `path` / `probe` 与 `api/registry.json`，确保主题**不臆造接口**；并断言 `LAYER_META` 覆盖运行时返回的全部系统层。

安全边界：令牌只存于页面内存变量，不写任何持久化存储；输出一律走 `textContent`，不注入 HTML；守卫检查脚本中不得出现相关不安全用法。

## 启用与收窄

```php
// config/app.php
'theme' => ['active' => 'glass'],
```

访问 `/console` 即进入磨砂玻璃后台（需登录并具备控制台读权限）。`site / post / tool / install / deny` 共用同一套设计系统，整站视觉一致。

若只想让 Glass 负责后台：把 `GlassTheme::LAYOUTS` 与 `theme.json` 的 `layouts` 收窄为 `["admin", "deny"]` 即可。按规矩 J，未提供的槽位不会被核心兜底，而是自动退化为纯数据——这与 `BareHtml` 只提供 `site` 是同一套机制。

## 明确不做

- 不新增第 18 个系统层、不新增接口、不改 `core/`（仍 24 个 PHP 文件）；
- 不内置业务数据、不复制系统层清单、不把接口路径写进服务端 PHP 模板；
- 不引入 CDN、字体服务、图片资源、前端框架或模板引擎；
- 不做主题内的写操作后台（受保护接口当前只读探测，完整写控制台属路线图）；
- 不用截图作为证据；结论一律由 `php tests/run.php` 与 `php tools/audit-responsibility.php` 复现。

## 验证

```bash
php tests/run.php                    # 23a / 23b 两组守卫
php tools/audit-responsibility.php   # 单文件职责预算（themes/ 150 行）
php -l themes/Glass/*.php
```

关联：[架构第 32 节](ARCHITECTURE.md) · [规矩 J](CONVENTIONS.md) · [接口规范](api/SPEC.md) · [接口索引](api/README.md)
