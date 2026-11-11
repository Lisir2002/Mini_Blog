<?php
declare(strict_types=1);
namespace Wx\Pages\Archive;

use Wx\Systems\Api\ApiOperationException;

/**
 * 显式状态构造 —— 失败时「说清楚发生了什么」，不产出空数组或空白页。
 *
 * 与 ArchivePage 分开，是为了让失败态口径只有一份：守卫逐项断言时，
 * 不必从渲染结果里反推「这到底是空、是截断、还是功能不可用」。
 */
final class ArchiveState
{
    public const LINKS = [
        'archive' => ['label' => '归档', 'href' => '/archive'],
        'terms'   => ['label' => '标签与分类', 'href' => '/terms'],
        'home'    => ['label' => '返回首页', 'href' => '/'],
    ];

    /** @return array<string, mixed> */
    public static function skeleton(string $title, string $view): array
    {
        return [
            'title'       => $title,
            'page'        => 'archive',
            'view'        => $view,
            'sections'    => [],
            'terms'       => [],
            'term'        => null,
            'total'       => 0,      // 过滤后命中数，不是全站总数
            'site_total'  => 0,      // 全站已发布总数，与 total 严格分开
            'shown'       => 0,
            'truncated'   => false,  // 采样被硬上限截断（数据可能不全）
            'filter_incomplete' => false,
            'empty'       => false,
            // 分页：page 已被页面标识占用，页码统一叫 page_no，避免同名字段两种含义。
            'page_no'     => 1,
            'pages'       => 1,
            'per_page'    => ArchiveFilter::PER_PAGE_DEFAULT,
            'has_prev'    => false,
            'has_next'    => false,
            'beyond'      => false,
            'prev_href'   => '',
            'next_href'   => '',
            'filter'      => ['year' => null, 'month' => null, 'active' => false],
            'filter_action' => '/archive',
            'terms_state' => null,
            'state'       => null,
            'links'       => self::LINKS,
        ];
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    public static function failed(string $view, int $status, string $title, string $message, string $hint = ''): array
    {
        $payload = self::skeleton($title, $view);
        $payload['state'] = [
            'phase'   => 'failed',
            'title'   => $title,
            'message' => $message,
            'hint'    => $hint !== '' ? $hint : '请检查地址，或从归档重新进入。',
            'actions' => [
                ['href' => '/archive', 'label' => '返回归档', 'kind' => 'primary'],
                ['href' => '/terms',   'label' => '浏览标签', 'kind' => 'secondary'],
            ],
        ];
        return ['status' => $status, 'payload' => $payload];
    }

    /** @return array{status: int, payload: array<string, mixed>} */
    public static function fromThrowable(string $view, \Throwable $e): array
    {
        // 内存降级是「部署形态」而不是偶发故障：文案必须说清「功能不可用」，
        // 否则读者会一直重试一个永远不会自己变好的地址。
        if (self::isUnavailable($e)) {
            return self::failed($view, 503, '分类与标签不可用',
                '当前站点运行在内存降级模式，归档与标签需要 MySQL 存储。',
                '恢复数据库后本页会自动可用；此处刻意不显示空的标签云。');
        }
        error_log('wx: archive query failed: ' . $e::class . ': ' . $e->getMessage());
        return self::failed($view, 503, '归档数据暂不可用',
            '读取归档数据失败，已记录到服务端日志。',
            '请稍后重试；页面不会把失败伪装成「暂无内容」。');
    }

    public static function isUnavailable(\Throwable $e): bool
    {
        return $e instanceof ApiOperationException && $e->apiCode === 50101;
    }

    /** 术语云是旁路：它挂了不能让整篇归档一起消失，但必须留一块显式说明。 */
    public static function termsState(\Throwable $e): array
    {
        return self::isUnavailable($e)
            ? ['phase' => 'failed', 'title' => '标签云不可用',
               'message' => '分类与标签需要 MySQL 存储，当前为内存降级模式。',
               'hint' => '文章归档仍然可读；恢复数据库后标签链接会自动出现。']
            : ['phase' => 'failed', 'title' => '标签云读取失败',
               'message' => '已记录到服务端日志，文章归档不受影响。',
               'hint' => '请稍后重试；此处刻意不显示空的标签云。'];
    }
}
