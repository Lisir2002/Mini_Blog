<?php
declare(strict_types=1);
namespace Wx\Systems\Metric;

/**
 * 指标字典 —— 每一个允许被写入的指标，必须在这里有名字、类型、单位、标签与来源。
 *
 * 为什么是白名单而不是「随便写」：指标名与标签是基数爆炸的两条入口。
 * 一个拼错的 `http_request` 会让看板永远少一块；一个带用户 ID 的标签
 * 能让一小时产生几十万行。白名单把这两类事故变成「写不进去 + 可解释地丢弃」，
 * 而不是「查不到但没人知道为什么」。
 *
 * 插件可以通过 `metric.catalog` 过滤器追加定义（见 MetricSystem::boot）。
 */
final class MetricCatalog
{
    public const COUNTER   = 'counter';
    public const GAUGE     = 'gauge';
    public const HISTOGRAM = 'histogram';

    /** 直方图桶上界（毫秒 / 条数）：命中第一个 >= 观测值的桶，并同时累加 +Inf。 */
    public const BUCKETS = [5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000, 10000];

    /**
     * @return array<string, array{kind: string, unit: string, labels: list<string>, source: string, desc: string}>
     */
    public static function builtin(): array
    {
        return [
            'http_requests' => [
                'kind' => self::COUNTER, 'unit' => '次', 'labels' => ['method', 'status'],
                'source' => 'kernel.response', 'desc' => '按方法与状态码统计的全部 HTTP 响应',
            ],
            'http_errors' => [
                'kind' => self::COUNTER, 'unit' => '次', 'labels' => ['method'],
                'source' => 'kernel.response / kernel.exception', 'desc' => '服务端错误（5xx，含未捕获异常）',
            ],
            'http_client_errors' => [
                'kind' => self::COUNTER, 'unit' => '次', 'labels' => ['method'],
                'source' => 'kernel.response', 'desc' => '客户端错误（4xx）',
            ],
            'http_duration_ms' => [
                'kind' => self::HISTOGRAM, 'unit' => 'ms', 'labels' => ['method'],
                'source' => 'kernel.shutdown', 'desc' => '请求耗时分布（进入 handle → 渲染完成，不含网络传输）',
            ],
            'api_calls' => [
                'kind' => self::COUNTER, 'unit' => '次', 'labels' => ['operation', 'code'],
                'source' => 'api.executed', 'desc' => '接口操作调用次数与业务码',
            ],
            'api_duration_ms' => [
                'kind' => self::HISTOGRAM, 'unit' => 'ms', 'labels' => ['operation'],
                'source' => 'api.executed', 'desc' => '接口操作执行耗时分布（匹配 → 守卫 → 执行 → 信封）',
            ],
            'db_queries' => [
                'kind' => self::HISTOGRAM, 'unit' => '条', 'labels' => [],
                'source' => 'kernel.shutdown + QueryProfiler', 'desc' => '单次请求的 SQL 条数分布',
            ],
            'db_duration_ms' => [
                'kind' => self::HISTOGRAM, 'unit' => 'ms', 'labels' => [],
                'source' => 'kernel.shutdown + QueryProfiler', 'desc' => '单次请求的 SQL 总耗时分布',
            ],
            'content_posts' => [
                'kind' => self::GAUGE, 'unit' => '篇', 'labels' => ['status'],
                'source' => 'metric.collect', 'desc' => '文章存量（published / any）',
            ],
            'comment_total' => [
                'kind' => self::GAUGE, 'unit' => '条', 'labels' => ['status'],
                'source' => 'metric.collect', 'desc' => '评论存量，按审核状态拆分',
            ],
            'user_accounts' => [
                'kind' => self::GAUGE, 'unit' => '个', 'labels' => [],
                'source' => 'metric.collect', 'desc' => '账号总数',
            ],
            'content_created' => [
                'kind' => self::COUNTER, 'unit' => '篇', 'labels' => [],
                'source' => 'content.created', 'desc' => '新增文章事件数',
            ],
            'comment_created' => [
                'kind' => self::COUNTER, 'unit' => '条', 'labels' => ['status'],
                'source' => 'comment.created', 'desc' => '新增评论事件数，按初始状态拆分',
            ],
            'comment_deleted' => [
                'kind' => self::COUNTER, 'unit' => '条', 'labels' => [],
                'source' => 'comment.deleted', 'desc' => '删除评论事件数',
            ],
            'comment_moderated' => [
                'kind' => self::COUNTER, 'unit' => '条', 'labels' => ['to'],
                'source' => 'comment.moderated', 'desc' => '审核动作数，按目标状态拆分',
            ],
            'attachment_uploaded' => [
                'kind' => self::COUNTER, 'unit' => '个', 'labels' => [],
                'source' => 'attachment.uploaded', 'desc' => '附件上传事件数',
            ],
            'member_xp_awarded' => [
                'kind' => self::COUNTER, 'unit' => '次', 'labels' => ['action'],
                'source' => 'member.xp.awarded', 'desc' => '经验发放次数，按动作拆分',
            ],
            'metric_dropped' => [
                'kind' => self::COUNTER, 'unit' => '次', 'labels' => ['reason'],
                'source' => 'MetricRecorder', 'desc' => '被拒绝写入的采样数（未知指标 / 类型不符 / 非法值）',
            ],
        ];
    }

    /** @param array<string, mixed> $catalog @return array<string, array<string, mixed>> */
    public static function sanitize(array $catalog): array
    {
        $out = [];
        foreach ($catalog as $name => $def) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name) !== 1) continue;
            if (!is_array($def)) continue;
            $kind = (string) ($def['kind'] ?? '');
            if (!in_array($kind, [self::COUNTER, self::GAUGE, self::HISTOGRAM], true)) continue;
            $labels = [];
            foreach ((array) ($def['labels'] ?? []) as $label) {
                $labels[] = MetricLabels::key((string) $label);
            }
            $out[$name] = [
                'kind'   => $kind,
                'unit'   => (string) ($def['unit'] ?? ''),
                'labels' => array_values(array_unique($labels)),
                'source' => (string) ($def['source'] ?? 'custom'),
                'desc'   => (string) ($def['desc'] ?? ''),
            ];
        }
        return $out;
    }
}
