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
    // 填 'bare' 用带样式的主题；填 'bare-html' 用零 CSS 的裸 HTML 主题
    'theme' => [
        'active' => null,
    ],

    // 空数组 = 装载目录下全部已启用的包；也可写成白名单 ['hello']
    'plugin' => ['enabled' => []],
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

    // 安全系统：CSP 默认关闭（示例主题使用内联样式，启用时需允许 style-src 'unsafe-inline'）
    'security' => [
        'csp' => '',
        // 页面会话（HttpOnly Cookie 载体）有效期，秒；与 API 令牌共用令牌表、各自可撤销
        'session' => ['ttl' => 7200],
    ],

    // 权限系统（第 12 个系统层）：决策缓存可由站长关闭，关闭后决策语义完全一致，仅性能不同
    'permission' => [
        // driver=file 时基础数据跨请求 / 跨同机进程共享（epoch 失效）；memory 只在单次请求内生效
        'cache' => ['enabled' => true, 'driver' => 'file'],
        // v0.9：默认 true。系统层自身的装配期覆盖会自动登记；第三方插件 / 主题 / 独立页
        // 的覆盖必须先经受保护接口登记授权（需 system.contract.override 权限），否则 fail-closed。
        'contract_override' => ['require_grant' => true],
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
    ],
];
