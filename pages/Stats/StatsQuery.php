<?php
declare(strict_types=1);
namespace Wx\Pages\Stats;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;

/**
 * 看板取数协作者 —— 复用既有 stats.* 接口，不新增接口、不认识 SQL、不自己算分位。
 *
 * 三条不变量：
 *  1. 采集器状态原样带出：durable / degraded / last_error 是页面的显式状态来源，
 *     绝不把「内存降级下的 0」当成健康数据。
 *  2. 截断必须声明：series 与 operations 各自的 truncated 都进 blocks，
 *     不假装「已经列全」。
 *  3. 任一接口失败都原样上抛，由页面转成显式不可用态（503），不吞成空数组。
 *
 * 行 / 点 / 窗口链接的摆法在 StatsBlocks；这里只管「取哪几个接口、要多少条」。
 */
final class StatsQuery
{
    public const SERIES_NAME = 'http_requests';
    public const SERIES_LIMIT = 48;
    public const OPS_LIMIT = 12;

    public function __construct(private ApiRegistryInterface $registry) {}

    /** window 白名单校验放在页面层：非法值显式 422，不静默回落到默认窗口。 */
    public static function normalizeWindow(string $raw): string
    {
        $value = strtolower(trim($raw));
        if ($value === '') return '24h';
        // 窗口形如 24h / 7d；上限 720 小时（30 天），因此小时位允许三位数。
        if (preg_match('/^(\d{1,3})(h|d)$/', $value, $m) !== 1) {
            throw new InvalidArgumentException('window 只接受 1h~30d 形式，例如 24h / 7d');
        }
        $hours = (int) $m[1] * ($m[2] === 'd' ? 24 : 1);
        if ($hours < 1 || $hours > 720) {
            throw new InvalidArgumentException('window 超出允许范围（1 小时 ~ 30 天）');
        }
        return $value;
    }

    /** @return array<string, mixed> 概览、序列、排行、字典各取一次 */
    public function collect(string $window): array
    {
        $overview = (array) $this->registry->execute('stats.overview', ['window' => $window]);
        $series = (array) $this->registry->execute('stats.series', [
            'name' => self::SERIES_NAME, 'window' => $window, 'limit' => self::SERIES_LIMIT,
        ]);
        $operations = (array) $this->registry->execute('stats.operations', [
            'window' => $window, 'limit' => self::OPS_LIMIT,
        ]);
        $catalog = (array) $this->registry->execute('stats.catalog', []);
        return [
            'overview' => $overview, 'series' => $series,
            'operations' => $operations, 'catalog' => $catalog,
        ];
    }

    /** @return array{notice: array<string,mixed>, blocks: list<array<string,mixed>>, has_points: bool} */
    public function board(string $window, array $raw): array
    {
        $overview = (array) ($raw['overview'] ?? []);
        $series = (array) ($raw['series'] ?? []);
        $ops = (array) ($raw['operations'] ?? []);
        $catalog = (array) ($raw['catalog'] ?? []);

        $points = StatsBlocks::chartPoints((array) ($series['points'] ?? []));
        $hasPoints = $points !== [];
        $notice = StatsState::assess($overview, $hasPoints);

        $blocks = [];
        $blocks[] = [
            'type' => 'notice', 'id' => 'stats-notice',
            'tone' => $notice['tone'], 'state' => $notice['state'],
            'title' => $notice['title'], 'value' => $notice['message'],
        ];
        $blocks[] = [
            'type' => 'links', 'id' => 'stats-windows', 'title' => '时间窗口',
            'items' => StatsBlocks::windowLinks($window),
        ];
        $blocks[] = ['type' => 'table', 'title' => '流量与耗时（窗口 ' . $window . '）',
            'rows' => StatsBlocks::trafficRows($overview)];
        $blocks[] = [
            'type' => 'chart', 'id' => 'stats-series',
            'title' => '按小时请求量 · ' . self::SERIES_NAME,
            'unit' => (string) ($series['unit'] ?? '次'),
            'points' => $points, 'empty' => !$hasPoints,
            'truncated' => !empty($series['truncated']),
        ];
        $blocks[] = ['type' => 'table', 'title' => '接口调用排行',
            'rows' => StatsBlocks::opsRows($ops, self::OPS_LIMIT)];
        $blocks[] = ['type' => 'table', 'title' => '采集器与字典',
            'rows' => StatsBlocks::collectorRows($overview, $catalog)];

        return ['notice' => $notice, 'blocks' => $blocks, 'has_points' => $hasPoints];
    }
}
