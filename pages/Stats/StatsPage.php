<?php
declare(strict_types=1);
namespace Wx\Pages\Stats;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 统计看板 —— 只产结构化数据，标签与柱形全由主题的 admin 布局承载。
 *
 * 复用既有 stats.* 接口（零新增接口）、复用 admin 布局槽位（零新增槽位）、
 * 复用 metric.read 权限点（零新增权限）。访问裁决交给 page.json 的 access 段：
 * 未登录 / 非管理员在 route.access 阶段就被短路，本页根本不会执行（fail-closed）。
 *
 * 失败态自行固定 admin 布局：4xx 会被 render.layout 跳过路径映射，
 * 不固定就会被默认 site 槽位渲染成「带门户导航的空页面」，错误说明一个字看不到。
 */
final class StatsPage implements PackageInterface
{
    public function slug(): string { return 'stats'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== '/console/stats') return;

            // window 非法是「参数不合法」，不是「统计挂了」：两者必须分开表达。
            try {
                $window = StatsQuery::normalizeWindow((string) ($request->query()['window'] ?? ''));
            } catch (InvalidArgumentException $e) {
                $this->emit($response, StatsState::unavailable('', $e, true));
                return;
            }

            $registry = $this->registry($kernel);
            if ($registry === null) {
                $this->emit($response, StatsState::unavailable($window,
                    new \RuntimeException('接口清单未装配')));
                return;
            }

            try {
                $query = new StatsQuery($registry);
                $raw = $query->collect($window);
                $board = $query->board($window, $raw);
            } catch (\Throwable $e) {
                $this->emit($response, StatsState::unavailable($window, $e));
                return;
            }

            $payload = StatsState::skeleton($window);
            $payload['stats_state'] = $board['notice']['state'];
            $payload['collector'] = (array) ($raw['overview']['collector'] ?? []);
            $payload['blocks'] = $board['blocks'];
            $this->emit($response, ['status' => 200, 'payload' => $payload]);
        }, 10);
    }

    /** @param array{status: int, payload: array<string, mixed>} $result */
    private function emit(Response $response, array $result): void
    {
        $response->setStatus((int) $result['status'])
            ->setLayout('admin')
            ->setPayload((array) $result['payload']);
    }

    private function registry(Kernel $kernel): ?ApiRegistryInterface
    {
        return $kernel->container()->has(ApiRegistryInterface::class)
            ? $kernel->container()->resolve(ApiRegistryInterface::class) : null;
    }
}
