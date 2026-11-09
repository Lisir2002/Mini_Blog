<?php
declare(strict_types=1);
namespace Wx\Pages\Install;

use RuntimeException;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 安装向导独立页包 —— 标准化五步：环境 → 数据库 → 管理员 → 迁移 → 加锁。
 *
 * 边界：
 * - 页面只产结构化数据与动作结果，表单外观与提交脚本归主题（规矩 J）；
 * - 已安装后整个页面返回 404，不再提供任何安装动作；
 * - 写动作必须带 storage/install.token 里的一次性令牌，且仅本机来源可访问（page.json access）。
 */
final class InstallPage implements PackageInterface
{
    private const STEPS = ['preflight', 'database', 'admin', 'migrate', 'finish'];

    public function slug(): string { return 'install'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', static function (Request $request, Response $response, Kernel $k): void {
            if ($request->path() !== '/install') return;
            $state = new InstallState($k->root());
            if ($state->installed()) {
                $response->setStatus(404)->setPayload([
                    'title'  => '已完成安装',
                    'page'   => 'install',
                    'blocks' => [[
                        'type'  => 'text',
                        'value' => 'storage/install.lock 已存在，向导已关闭。如需重装，请先手动删除该文件与 config/local.php。',
                    ]],
                ]);
                return;
            }
            if ($request->method() === 'POST') {
                self::run($request, $response, $k, $state);
                return;
            }
            self::render($request, $response, $k, $state);
        }, 10);
    }

    private static function render(Request $request, Response $response, Kernel $k, InstallState $state): void
    {
        $probe = new InstallProbe($k);
        $installer = new Installer($k, $state);
        $preflight = $installer->preflight();
        $env = $probe->rows();
        $facts = $probe->progress();

        $blocks = [
            ['type' => 'table', 'title' => '安装前置检查', 'rows' => array_map(
                static fn(array $c): array => ['k' => (string) $c['name'], 'v' => $c['ok'] ? 'ok' : 'fail',
                    'ok' => (bool) $c['ok'], 'note' => (string) $c['detail']],
                $preflight['checks']
            )],
            ['type' => 'list', 'title' => '五个步骤', 'items' => array_map(
                static fn(array $s): string => ($s['done'] ? '✓ ' : '· ') . $s['title'] . '（' . $s['step'] . '）',
                $state->steps($facts)
            )],
            ['type' => 'table', 'title' => '运行环境', 'rows' => array_map(
                static fn(array $r): array => ['k' => (string) $r['title'], 'v' => (string) $r['value'],
                    'ok' => (bool) $r['ok'], 'note' => (string) $r['note']],
                $env
            )],
        ];
        if ($request->isLocal()) {
            $blocks[] = ['type' => 'pre', 'title' => '一次性令牌（仅本机返回）', 'id' => 'install-token',
                'value' => $state->token()];
            $blocks[] = ['type' => 'text', 'value' =>
                'POST /install 携带 {"step":"preflight|database|admin|migrate|finish","token":"…"}，按步骤顺序提交。'];
        }

        $response->setPayload([
            'title'     => '安装向导 · 万象',
            'page'      => 'install',
            'installed' => false,
            'steps'     => $state->steps($facts),
            'preflight' => $preflight,
            'next'      => self::nextStep($state, $facts),
            'blocks'    => $blocks,
        ]);
    }

    private static function run(Request $request, Response $response, Kernel $k, InstallState $state): void
    {
        $in = $request->json();
        $step = (string) ($in['step'] ?? '');
        try {
            $state->verify((string) ($in['token'] ?? ''));
            $installer = new Installer($k, $state);
            $result = match ($step) {
                'preflight' => $installer->preflight(),
                'database'  => $installer->database($in),
                'admin'     => $installer->admin($in),
                'migrate'   => $installer->migrate(),
                'finish'    => $installer->finish(),
                default     => throw new RuntimeException('未知安装步骤：' . $step, 42201),
            };
            $response->setPayload(['ok' => true, 'step' => $step, 'result' => $result,
                'next' => self::nextAfter($step),
                'steps' => $state->steps((new InstallProbe($k))->progress())]);
        } catch (RuntimeException $e) {
            $code = $e->getCode();
            $response->setStatus(self::statusFor($code))->setPayload([
                'ok' => false, 'step' => $step, 'code' => $code, 'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 业务码即「HTTP 状态 × 100 + 序号」（40301 → 403、40903 → 409）。
     * 这条约定是全局的（见 api 错误码表），这里直接按约定推导，不另建一张表。
     */
    private static function statusFor(int $code): int
    {
        $http = intdiv($code, 100);
        return $http >= 400 && $http < 600 ? $http : 500;
    }

    /** @param array{admin?: bool, migrate?: bool} $facts */
    private static function nextStep(InstallState $state, array $facts): string
    {
        foreach ($state->steps($facts) as $step) {
            if (!$step['done']) return $step['step'];
        }
        return 'finish';
    }

    private static function nextAfter(string $step): ?string
    {
        $index = array_search($step, self::STEPS, true);
        return $index === false ? null : (self::STEPS[$index + 1] ?? null);
    }
}
