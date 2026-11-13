<?php
declare(strict_types=1);

/**
 * 全局装配配置。核心不解释这些键的含义，含义由对应系统层自己定义。
 * 生产环境请把数据库口令、令牌密钥改为环境注入，不要提交明文到版本库。
 */
return [
    'site' => [
        'name' => 'Mini Blog',
    ],

    // 停用某个系统层：把名字写进这里即可，无需删代码
    'systems' => [
        'disabled' => [],
    ],

    // 默认「无主题」：全站零样式、零 HTML，框架退化为纯数据服务
    // 两款出厂元主题（初始化主题，见 docs/THEMES.md）：同属 family=glass 磨砂玻璃材质
    //   'glass-front' 前端面（访客门户）· 'glass' 后端面（后台仪表盘），glass.css 逐字同源
    // 另：'bare' 示例主题 · 'bare-html' 零 CSS 裸 HTML（非元主题，仅演示布局权归主题）
    // 元主题是「开箱可选」的出厂基线，不强制启用；php tools/theme.php list/verify 可复核
    // 主题可只声明部分布局槽位；未声明的槽位退化为纯数据，核心绝不兜底（规矩 J）
    'theme' => [
        'active' => null,
    ],

    // 插件装配唯一真源是 lifecycle 状态机（active 才装载，v0.61 退役配置白名单）
    'page'   => ['enabled' => []],

    // 装卸系统（元层）
    'lifecycle' => [
        'backup_keep'       => 3,
        // 版本快照保留份数：按版本归档，超出只裁最旧的，当前版本永不裁
        'keep_releases'     => 5,
        'allow_core_update' => false,
        'require_checksum'  => false,
        'require_signature' => false,  // true 时源包必须带 signature.json（HMAC-SHA256 验签）
        'signature_secret'  => '',     // 生产环境由环境注入；为空时仅支持校验哈希清单
    ],

    // API 接口系统
    'api' => [
        'prefix'        => '/api/v1',
        'version'       => 'v1',
        'expose_errors' => false,
        // 写接口公网边界：false 时仅本机来源可调用写接口，公网默认拒绝（40302）
        'expose_write'  => false,
        // 静态令牌仅用于本地演示 / 测试；生产请使用 MySQL 令牌表（POST /api/v1/auth/token 签发）
        'tokens'        => [],
        'rate_limit'    => ['enabled' => true, 'max' => 120, 'window' => 60],
        'idempotency'   => ['enabled' => true, 'ttl' => 3600],
    ],

    // 内容系统（第 11 个系统层）
    'content' => [
        'per_page' => 10,
    ],

    // 用户系统（第 5 个系统层）：账号生命周期。
    // 注册默认关闭 —— 开放注册是一个需要站点主明确决定的安全决策。
    'user' => [
        'registration' => ['enabled' => false],
        'login'        => ['max_attempts' => 5, 'lock_seconds' => 900],
        // 下限 8 位在 CredentialPolicy 内锁死，这里只能调高。
        'password'     => ['min_length' => 8],
    ],

    // 安全系统：CSP 默认关闭（示例主题使用内联样式，启用时需允许 style-src 'unsafe-inline'）
    'security' => [
        'csp' => '',
        // 页面会话（HttpOnly Cookie 载体）有效期，秒；与 API 令牌共用令牌表、各自可撤销
        'session' => ['ttl' => 7200],
    ],

    // 性能治理（v0.15）：可选增强包 plugins/Performance 的开关，默认关闭。
    // 只做传输经济 / 可观测 / 预算留痕；CPU 亲和性、线程、进程池属于部署层，不在应用里配置。
    // 关闭时该包一个钩子都不注册，业务语义与未安装完全一致（见规矩 T）。
    'performance' => [
        'enabled' => false,
        'economy' => ['min_bytes' => 256, 'gzip' => true, 'max_age' => 60],
        'budget'  => ['max_queries' => 40, 'max_ms' => 120.0],
    ],

    // 权限系统（第 12 个系统层）：决策缓存可由站长关闭，关闭后决策语义完全一致，仅性能不同
    'permission' => [
        // driver=file 时基础数据跨请求 / 跨同机进程共享（epoch 失效）；memory 只在单次请求内生效
        'cache' => ['enabled' => true, 'driver' => 'file'],
        // v0.9：默认 true。系统层自身的装配期覆盖会自动登记；第三方插件 / 主题 / 独立页
        // 的覆盖必须先经受保护接口登记授权（需 system.contract.override 权限），否则 fail-closed。
        'contract_override' => ['require_grant' => true],
    ],

    // 评论系统：审核与嵌套策略。生产建议把 auto_publish 置 false，先审后发。
    'comment' => [
        'auto_publish' => true,
        'max_depth'    => 5,
        'max_length'   => 5000,
        'max_links'    => 3,
        // 单篇一次最多读多少条：热文被刷到数万条时，全量读会占掉几十 MB（v0.25 实测 5 万条 = 90 MB）。
        // 0 = 不限制（旧行为）。截断时响应带 truncated=true，总数仍是真的 —— 不静默丢数据。
        'read_limit'   => 2000,
        // 互动与治理（v0.29）
        // 编辑窗口从 created_at 起算，编辑不续期；0 = 关闭编辑能力。
        'edit_window_minutes' => 10,
        // 独立举报人数达到该值，自动把 published 打回 pending；0 = 不自动下架。
        'report_threshold'    => 3,
        // 按互动主体（登录 user_id / IP 指纹）节流，与全站 API 限流分桶互不挤占。
        // 默认关闭：阈值是站点主的业务决策（是否开放匿名评论、站点规模），
        // 框架默认开启会悄悄改变既有写入行为。生产确认阈值后再显式打开。
        'throttle' => [
            'enabled' => false,
            'max'     => 8,
            'window'  => 60,
        ],
    ],

    // 统计系统（第 18 个系统层）：按小时聚合，不存单请求明细。
    // 四条采集通道可分别关闭；关掉后业务语义不变，只是对应指标不再增长。
    'metric' => [
        'enabled'  => true,
        'channels' => ['http' => true, 'api' => true, 'db' => true, 'business' => true],
        // 历史保留天数：由 `php tools/metrics.php prune` 按此默认值裁剪（需配定时任务）
        'retention_days' => 30,
    ],

    // 附件系统：超过 stream_threshold 字节的下载走分块输出，避免整文件读进内存。
    // 0 表示关闭流式（一律按字节返回，与 v0.19 行为一致）。
    'attachment' => [
        'max_per_post'     => 20,
        'stream_threshold' => 1048576,
    ],

    // 状态系统（第 15 个系统层）：长任务状态的保留时长，秒；过期由 sweep 清理
    'state' => [
        'task_ttl' => 86400,
    ],

    // 版本系统（第 14 个系统层）：不重装即可升级、可回退
    'release' => [
        // 与 lifecycle.allow_core_update 各自独立：版本系统走自己的开关，两边都开才升级 core
        'allow_core_update' => false,
        // 已执行过的迁移脚本一旦被改动即拒绝升级（历史不可改写）；仅排障时临时关闭
        'strict_checksum'   => true,
    ],

    // MySQL：连接失败且 auto_fallback=true 时，各系统层自动回退到内存 / 会话适配器，
    // 保证「删光数据库也能启动」；核心始终不认识 MySQL。
    'database' => [
        'dsn'           => 'mysql:host=127.0.0.1;dbname=wx_blog;charset=utf8mb4',
        'user'          => 'wx',
        'pass'          => 'wx_pass_2026',
        'prefix'        => 'wx_',
        'auto_fallback' => true,
        // 连接超时（秒）：库不可达时不让 worker 无限等；0 = 沿用驱动 / 系统默认（旧行为）。
        // 语句级读超时（max_execution_time / innodb_lock_wait_timeout）属部署层，见 docs/PERFORMANCE.md。
        'timeout'       => ['connect' => 2],
    ],
];
