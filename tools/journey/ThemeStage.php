<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

/**
 * 阶段三 · 主题可视化操作的端到端：清单 → 启用 → 前台门户 → 后台主题 → 停用回 Headless。
 *
 * 这里专门验证三个「只有拼起来才会暴露」的点：
 *   1. effective=next_request 不是口号：切完必须用不带覆盖的新内核，才会真的换皮；
 *   2. 安装器与 theme.activate 共用 config/local.php，主题写入不能抹掉 database 段；
 *   3. 停用后整站回到零呈现，而不是留一张半成品页面。
 */
final class ThemeStage
{
    public function run(Probe $p, Site $site, JourneyState $s): void
    {
        $k = $site->fresh();
        $H = ['Authorization' => 'Bearer ' . $s->bearer];

        $p->step('主题清单：四款出厂、两款元主题、当前 Headless', function () use ($p, $k): void {
            $d = $p->data($p->get($k, '/api/v1/themes'), 'theme.list');
            $p->ok(($d['headless'] ?? null) === true && $d['active'] === null, '出厂不应启用主题');
            $items = (array) ($d['items'] ?? []);
            $metas = array_values(array_filter($items, static fn(array $i): bool => !empty($i['meta'])));
            $p->ok(count($metas) === 2, '元主题应恰好两款，实际 ' . count($metas));
            $surfaces = array_column($metas, 'surface');
            $p->ok(in_array('front', $surfaces, true) && in_array('back', $surfaces, true), '元主题未成对');
            $p->note('total=' . ($d['total'] ?? 0) . ' · meta=' . count($metas) . '（front+back）· family=glass');
        });

        $p->step('启用前端元主题：changed / effective=next_request 语义', function () use ($p, $k, $H): void {
            $d = $p->data($p->post($k, '/api/v1/themes/active', ['slug' => 'glass-front'], [], $H), 'theme.activate');
            $p->ok(($d['active'] ?? '') === 'glass-front' && ($d['changed'] ?? false) === true, '切换结果不正确');
            $p->ok(($d['effective'] ?? '') === 'next_request', '未明示下次请求生效');
        });

        $p->step('接缝：新内核接管后前台真的渲染成 HTML 门户', function () use ($p, $site): void {
            $next = $site->fresh();
            $info = $p->data($p->get($next, '/api/v1/system/info'), 'system.info(front)');
            $p->ok(($info['theme_active'] ?? '') === 'glass-front', '回读 theme_active 不一致');
            $p->ok(($info['headless'] ?? true) === false, '启用主题后仍是 headless');
            $home = $p->get($next, '/');
            $p->ok(str_contains($home['ct'], 'text/html'), '前台未渲染 HTML，ct=' . $home['ct']);
            $p->ok(str_contains($home['body'], 'front-posts'), '门户文章区缺失');
            $p->ok(str_contains($home['body'], 'glass-front') || str_contains($home['body'], 'data-theme'), '缺少主题标识');
            $p->note('GET / → text/html · front-posts 容器 · theme_active=glass-front 回读一致');
        });

        $p->step('启用后台元主题：控制台出现「主题」可视化分区', function () use ($p, $site, $H): void {
            $d = $p->data($p->post($site->fresh(), '/api/v1/themes/active', ['slug' => 'glass'], [], $H), 'theme.activate');
            $p->ok(($d['active'] ?? '') === 'glass', '后台主题切换失败');
            $next = $site->fresh();
            $console = $p->get($next, '/console', [], $H);
            $p->ok($console['status'] === 200, '控制台未返回 200');
            $p->ok(str_contains($console['ct'], 'text/html'), '后台未渲染 HTML，ct=' . $console['ct']);
            $p->ok(str_contains($console['body'], 'data-nav="themes"'), '缺少「主题」导航分区');
            $p->ok(str_contains($console['body'], 'glass-themes'), '缺少主题卡片容器');
            $p->note('GET /console → text/html · data-nav="themes" · #glass-themes');
        });

        $p->step('写入边界：白名单外 40403、匿名 401、重复写幂等', function () use ($p, $site, $H): void {
            $bad = $p->post($site->fresh(), '/api/v1/themes/active', ['slug' => 'not-a-theme'], [], $H);
            $p->ok($bad['status'] === 404 && ($bad['json']['code'] ?? 0) === 40403, '白名单外未拒绝');
            $anon = $p->post($site->fresh(), '/api/v1/themes/active', ['slug' => 'glass-front']);
            $p->ok($anon['status'] === 401, '匿名写未被拒绝');
            $again = $p->data($p->post($site->fresh(), '/api/v1/themes/active', ['slug' => 'glass'], [], $H), 'theme.activate(idem)');
            $p->ok(($again['changed'] ?? true) === false, '重复写应幂等不写盘');
        });

        $p->step('覆盖层共存：主题写入后安装器写的 database 段仍在', function () use ($p, $site): void {
            $local = $site->localPhp();
            $p->ok(str_contains($local, "'dsn'") && str_contains($local, "'active'"), 'local.php 丢掉了 database 或 theme 段');
            $cfg = require $site->dir() . '/config/local.php';
            $p->ok(is_array($cfg) && (string) ($cfg['database']['dsn'] ?? '') !== '', 'database 段被抹掉');
            $p->ok((string) ($cfg['theme']['active'] ?? '') === 'glass', 'theme.active 未落地');
            $p->note('config/local.php 同时持有 database.dsn 与 theme.active');
        });

        $p->step('停用回 Headless：整站退化为纯数据，零标签', function () use ($p, $site, $H): void {
            $d = $p->data($p->post($site->fresh(), '/api/v1/themes/active', ['slug' => ''], [], $H), 'theme.activate(off)');
            $p->ok(array_key_exists('active', $d) && $d['active'] === null, '停用未回到 null');
            $next = $site->fresh();
            $list = $p->data($p->get($next, '/api/v1/themes'), 'theme.list(off)');
            $p->ok(($list['headless'] ?? null) === true, '停用后 headless 不为 true');
            $home = $p->get($next, '/');
            $p->ok(str_contains($home['ct'], 'json') && !str_contains($home['body'], '<'), '停用后仍在渲染 HTML');
            $p->note('theme.list headless=true · GET / → application/json · 零标签');
        });
    }
}
