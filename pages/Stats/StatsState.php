<?php
declare(strict_types=1);
namespace Wx\Pages\Stats;

/**
 * 看板显式状态 —— 三态必须能被分别断言，不能都长成「一排 0」。
 *
 * v0.26 把 durable / degraded / last_error 埋进了统计响应，但只存在于 JSON 与 CLI，
 * 页面上没人看得见。于是「库挂了、指标全丢」与「站点真的没流量」会渲染成同一张
 * 空看板。这里把采集器状态提升为页面一等公民：降级时醒目警示，不可用时 503，
 * 空窗口明确说「没有采样」，三者互不冒充。
 */
final class StatsState
{
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const DEGRADED = 'degraded';
    public const UNAVAILABLE = 'unavailable';

    /** @return array<string, mixed> */
    public static function skeleton(string $window): array
    {
        return [
            'title' => '统计看板', 'page' => 'stats', 'view' => 'stats',
            'window' => $window, 'stats_state' => self::OK,
            'metric' => StatsDimension::DEFAULT_METRIC, 'dimension' => '',
            'collector' => [], 'blocks' => [], 'state' => null,
        ];
    }

    /**
     * 采集器状态 → 三态判定。durable=false 表示统计没有持久化，degraded / last_error
     * 表示写失败过：任何一种都不能把「0 请求」当成健康数据展示。
     * @param array<string, mixed> $overview
     * @return array{state: string, tone: string, title: string, message: string}
     */
    public static function assess(array $overview, bool $hasPoints): array
    {
        $collector = (array) ($overview['collector'] ?? []);
        $durable = (bool) ($collector['durable'] ?? true);
        $degraded = (bool) ($collector['degraded'] ?? false);
        $lastError = (string) ($collector['last_error'] ?? '');
        $requests = (int) ($overview['traffic']['requests'] ?? 0);

        if (!$durable) {
            return ['state' => self::DEGRADED, 'tone' => 'warn',
                'title' => '统计未持久化',
                'message' => '当前采集器为内存驱动（durable=false），进程重启或数据库不可用后历史聚合会丢失。下列数字只代表本次运行，不能当作全站真实流量。'];
        }
        if ($degraded || $lastError !== '') {
            return ['state' => self::DEGRADED, 'tone' => 'warn',
                'title' => '统计采集已降级',
                'message' => '写入聚合时发生过失败，数据可能不完整。'
                    . ($lastError !== '' ? '最近一次错误：' . $lastError : '')];
        }
        if ($requests === 0 && !$hasPoints) {
            return ['state' => self::EMPTY, 'tone' => 'info',
                'title' => '窗口内没有采样',
                'message' => '采集器工作正常（数据可持久化、未降级），只是这个时间窗口内还没有请求被记录。这不是故障，也不等于站点不可用。'];
        }
        return ['state' => self::OK, 'tone' => 'ok',
            'title' => '采集正常',
            'message' => '数据可持久化、未降级；分位耗时为固定桶近似值，响应中已标注 approx。'];
    }

    /** @return array{status: int, payload: array<string, mixed>} 接口执行失败：显式不可用，不渲染空看板 */
    public static function unavailable(string $window, \Throwable $e, bool $badWindow = false): array
    {
        $payload = self::skeleton($window);
        $payload['stats_state'] = self::UNAVAILABLE;
        if ($badWindow) {
            return ['status' => 422, 'payload' => self::withState($payload, '参数不合法', $e->getMessage(),
                'window 只接受 1h~30d；metric 必须是 stats.catalog 里的指标；'
                . 'dimension 必须带齐该指标声明的全部标签。请修正地址后重试。')];
        }
        error_log('wx: stats board failed: ' . $e::class . ': ' . $e->getMessage());
        return ['status' => 503, 'payload' => self::withState($payload, '统计看板不可用',
            '读取统计接口失败，已记录到服务端日志。',
            '此处刻意不显示一排 0：那会把「功能挂了」伪装成「站点没流量」。稍后重试或检查采集器。')];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function withState(array $payload, string $title, string $message, string $hint): array
    {
        $payload['state'] = [
            'phase' => 'failed', 'title' => $title, 'message' => $message, 'hint' => $hint,
            'actions' => [
                ['href' => '/console', 'label' => '返回控制台', 'kind' => 'primary'],
                ['href' => '/console/stats', 'label' => '重试', 'kind' => 'secondary'],
            ],
        ];
        return $payload;
    }
}
