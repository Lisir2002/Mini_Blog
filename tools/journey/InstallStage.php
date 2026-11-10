<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

/**
 * 阶段一 · 从零到装好：全新站点基线 → 五步安装 → 只读 local.php 的新内核。
 *
 * 这一段验证的不是「安装器自己能跑」（18c/18d 已守），而是安装产物能否
 * 被一个不带任何覆盖配置的新内核接管 —— 全链路里最容易断的接缝。
 */
final class InstallStage
{
    public function run(Probe $p, Site $site, JourneyState $s): void
    {
        $k = $site->kernel();
        $appHash = $site->appPhpHash();

        $p->step('全新站点：Headless 基线、零呈现、出厂主题清单', function () use ($p, $k): void {
            $info = $p->get($k, '/api/v1/system/info');
            $d = $p->data($info, 'system.info');
            $p->ok(($d['headless'] ?? null) === true, '全新站点不应有渲染器');
            $p->ok(array_key_exists('theme_active', $d) && $d['theme_active'] === null, '默认必须是 Headless');
            $p->ok(($d['database'] ?? '') === 'mysql', '旅程要求真实数据库，当前为 ' . ($d['database'] ?? '?'));
            $home = $p->get($k, '/');
            $p->ok(str_contains($home['ct'], 'json'), '无主题时首页应为纯数据');
            $p->ok(!str_contains($home['body'], '<'), '核心不应产出任何标签');
            $themes = $p->data($p->get($k, '/api/v1/themes'), 'theme.list');
            $p->ok(($themes['headless'] ?? null) === true && ($themes['total'] ?? 0) >= 4, '出厂主题清单不完整');
            $p->note('system.info: headless=true · theme_active=null · database=mysql；theme.list total=' . ($themes['total'] ?? 0));
        });

        $p->step('安装窗口：五步结构、令牌仅本机可见、公网被拒', function () use ($p, $k, $site, $s): void {
            $page = $p->get($k, '/install');
            $p->ok($page['status'] === 200, '安装页未返回 200');
            $body = $page['json'];
            $p->ok(($body['installed'] ?? true) === false, '全新站点被误判为已安装');
            $steps = array_map(static fn(array $x): string => (string) $x['step'], (array) ($body['steps'] ?? []));
            $p->ok($steps === ['preflight', 'database', 'admin', 'migrate', 'finish'], '五步顺序不符');
            foreach ((array) ($body['blocks'] ?? []) as $block) {
                if (($block['id'] ?? '') === 'install-token') $s->installToken = (string) ($block['value'] ?? '');
            }
            $p->ok(preg_match('/^[0-9a-f]{32}$/', $s->installToken) === 1, '未拿到 32 位一次性令牌');
            $remote = $p->get($k, '/install', [], [], '203.0.113.9');
            $p->ok($remote['status'] === 403 && ($remote['json']['reason'] ?? '') === 'local_only', '公网来源未拒绝');
            $p->note('steps=preflight>database>admin>migrate>finish；install.token=' . substr($s->installToken, 0, 8) . '…；公网 403 local_only');
        });

        $p->step('安装动作：缺令牌 / 错令牌被拒且不写状态', function () use ($p, $k, $site, $s): void {
            $missing = $p->post($k, '/install', ['step' => 'database']);
            $p->ok($missing['status'] === 403 && ($missing['json']['code'] ?? 0) === 40301, '缺令牌未拒绝');
            $wrong = $p->post($k, '/install', ['step' => 'database', 'token' => str_repeat('0', 32)]);
            $p->ok($wrong['status'] === 403, '错令牌未拒绝');
            $p->ok(!is_file($site->dir() . '/config/local.php'), '被拒请求仍然写了 local.php');
        });

        $p->step('五步安装：database → admin → migrate → finish', function () use ($p, $k, $site, $s): void {
            $dbInput = $site->databaseInput();
            $plan = [
                'database' => ['step' => 'database', 'token' => $s->installToken] + $dbInput,
                'admin'    => ['step' => 'admin', 'token' => $s->installToken,
                               'username' => 'webmaster', 'password' => 'Journey-Pass-2026'],
                'migrate'  => ['step' => 'migrate', 'token' => $s->installToken],
                'finish'   => ['step' => 'finish', 'token' => $s->installToken],
            ];
            $results = [];
            foreach ($plan as $name => $payload) {
                $res = $p->post($k, '/install', $payload);
                $p->ok($res['status'] === 200 && ($res['json']['ok'] ?? false) === true,
                    $name . ' 步骤失败：' . substr($res['body'], 0, 200));
                $results[$name] = (array) ($res['json']['result'] ?? []);
                $s->visited[] = $name;
            }
            $p->ok(($results['database']['connected'] ?? false) === true, '数据库未报告连接成功');
            $p->ok(($results['admin']['role'] ?? '') === 'admin', '管理员角色不正确');
            $p->ok((int) ($results['migrate']['applied'] ?? 0) > 0, '迁移基线为空');
            $p->ok($site->hasLock(), 'finish 未生成 install.lock');
            $p->note('database connected · admin=webmaster · migrate applied=' . ($results['migrate']['applied'] ?? 0)
                . ' tables=' . ($results['migrate']['tables'] ?? 0) . ' · install.lock 已生成');
        });

        $p->step('安装产物：只写覆盖层，主配置逐字未动', function () use ($p, $site, $appHash): void {
            $p->ok($site->localPhp() !== '', 'config/local.php 未生成');
            $p->ok(str_contains($site->localPhp(), "'dsn'"), 'local.php 未记录 DSN');
            $p->ok($site->appPhpHash() === $appHash, '带注释的主配置被覆盖了');
        });

        $p->step('接缝：不带任何覆盖配置的新内核接管站点，向导已关闭', function () use ($p, $site): void {
            $live = $site->fresh();
            $info = $p->data($p->get($live, '/api/v1/system/info'), 'system.info(live)');
            $p->ok(($info['database'] ?? '') === 'mysql', '新内核读不到 local.php 的库配置');
            $p->ok(array_key_exists('theme_active', $info) && $info['theme_active'] === null, '装完仍应是 Headless');
            $closed = $p->get($live, '/install');
            $p->ok($closed['status'] === 404, '装完向导应关闭，实际 ' . $closed['status']);
            $p->note('new Kernel($site) 只读 config/local.php → database=mysql · theme_active=null · /install=404');
        });
    }
}
