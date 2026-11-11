<?php
declare(strict_types=1);
namespace Wx\Pages\Write;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;

/**
 * 编辑器取数 —— 只读一篇待改的文章，不在这里判权限。
 *
 * 用 content.post 而不是直接查库：草稿、定时、回收站的可见性口径都在系统层，
 * 页面自己写一条 SELECT 迟早会漏掉其中一种。读不到时区分「不存在」与「读取失败」，
 * 前者是 404，后者是 503 —— 不能都渲染成一张空表单，否则用户会以为正文被清空了。
 */
final class WriteQuery
{
    /**
     * @param array<string, mixed>|null $identity
     * @return array{post: array<string, mixed>, error: string, status: int}
     */
    public static function load(Kernel $kernel, string $slug, ?array $identity): array
    {
        if ($slug === '') return ['post' => [], 'error' => '', 'status' => 200];
        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return ['post' => [], 'error' => '接口清单未装配，无法读取文章。', 'status' => 503];
        }
        try {
            $post = $container->resolve(ApiRegistryInterface::class)->execute('content.post', [
                'slug' => $slug, '_identity' => $identity,
            ]);
        } catch (\Throwable $e) {
            $missing = str_contains($e->getMessage(), '不存在');
            return ['post' => [], 'status' => $missing ? 404 : 503,
                'error' => $missing ? '没有这篇文章：' . $slug . '（可能已删除或不在你的可见范围）'
                    : '文章读取失败：' . $e->getMessage()];
        }
        return ['post' => $post, 'error' => '', 'status' => 200];
    }
}
