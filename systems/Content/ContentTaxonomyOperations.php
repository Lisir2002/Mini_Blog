<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Systems\Api\ApiOperationException;

/**
 * 分类与标签接口 —— 术语的增删改查，以及「文章绑定哪些术语」。
 *
 * 绑定只接受已存在的术语（`kind:slug`）。自动创建更省事，代价是把拼写错误
 * 变成站点上的新词条：写 `tag:javascrpit` 也能成功，只是那个标签永远只有一篇。
 * 这类错误没有任何地方会报，所以宁可在写入时返回 40404。
 *
 * 需要 MySQL：术语要两张表。内存模式（拔库降级）下直接 50101，
 * 而不是返回空列表假装「还没有分类」。
 */
final class ContentTaxonomyOperations
{
    public function __construct(
        private ContentRepositoryInterface $repo,
        private ?ContentTermStore $terms
    ) {}

    public function role(): string { return 'content_taxonomy_operations'; }

    public function register(ApiRegistryInterface $registry): void
    {
        $repo  = $this->repo;
        $terms = $this->terms;

        $registry->registerOperation('content.terms', static function (array $in) use ($terms): array {
            $store = self::store($terms);
            $kind  = trim((string) ($in['kind'] ?? 'any'));
            if ($kind !== 'any' && !in_array($kind, ContentTermStore::KINDS, true)) {
                throw new ApiOperationException('kind 只允许 category / tag / any', 42201);
            }
            $items = $store->list($kind);
            return ['items' => $items, 'total' => count($items), 'kinds' => ContentTermStore::KINDS];
        });

        $registry->registerOperation('content.terms.manage', static function (array $in) use ($terms): array {
            $store  = self::store($terms);
            $action = trim((string) ($in['action'] ?? ''));

            if ($action === 'create') {
                $kind = trim((string) ($in['kind'] ?? 'tag'));
                $slug = trim((string) ($in['slug'] ?? ''));
                $name = trim((string) ($in['name'] ?? ''));
                if ($store->findBySlug($kind, $slug) !== null) {
                    throw new ApiOperationException('术语已存在：' . $kind . ':' . $slug, 40911);
                }
                try {
                    $id = $store->create($kind, $slug, $name, trim((string) ($in['description'] ?? '')));
                } catch (InvalidArgumentException $e) {
                    throw self::translate($e);
                }
                return ['id' => $id, 'created' => true] + (array) $store->find($id);
            }

            if ($action === 'update') {
                $id = (int) ($in['id'] ?? 0);
                if ($id <= 0) throw new ApiOperationException('id 必填', 42201);
                if ($store->find($id) === null) throw new ApiOperationException('术语不存在', 40404);
                $fields = [];
                foreach (['name', 'description'] as $key) {
                    if (array_key_exists($key, $in)) $fields[$key] = $in[$key];
                }
                try {
                    $changed = $store->update($id, $fields);
                } catch (InvalidArgumentException $e) {
                    throw new ApiOperationException($e->getMessage(), 42201);
                }
                return ['updated' => $changed] + (array) $store->find($id);
            }

            if ($action === 'delete') {
                $id = (int) ($in['id'] ?? 0);
                if ($id <= 0) throw new ApiOperationException('id 必填', 42201);
                $term = $store->find($id);
                if ($term === null) throw new ApiOperationException('术语不存在', 40404);
                return ['deleted' => true] + $term + ['relations_removed' => $store->delete($id)];
            }

            throw new ApiOperationException('action 只允许 create / update / delete', 42201);
        });

        $registry->registerOperation('content.attach', static function (array $in) use ($repo, $terms): array {
            $store = self::store($terms);
            $slug  = trim((string) ($in['slug'] ?? ''));
            if ($slug === '') throw new ApiOperationException('slug 必填', 42201);
            $post = $repo->findBySlug($slug);
            if ($post === null) {
                $inTrash = $repo->findBySlug($slug, true) !== null;
                throw new ApiOperationException($inTrash ? '文章在回收站，请先恢复' : '文章不存在', $inTrash ? 40909 : 40403);
            }

            $refs = $in['terms'] ?? [];
            if (!is_array($refs)) throw new ApiOperationException('terms 必须是数组', 42201);
            $ids = [];
            foreach ($refs as $ref) {
                $text = trim((string) $ref);
                $parts = explode(':', $text, 2);
                if (count($parts) !== 2) {
                    throw new ApiOperationException('术语写法应为 kind:slug：' . $text, 42201);
                }
                $term = $store->findBySlug($parts[0], $parts[1]);
                if ($term === null) {
                    throw new ApiOperationException('术语不存在：' . $text, 40404);
                }
                $ids[] = (int) $term['id'];
            }

            $result = $store->sync((int) $post['id'], $ids);
            return [
                'slug'    => $slug,
                'added'   => $result['added'],
                'removed' => $result['removed'],
                'terms'   => $store->forPost((int) $post['id']),
            ];
        });
    }

    private static function store(?ContentTermStore $terms): ContentTermStore
    {
        if ($terms === null) {
            throw new ApiOperationException('分类与标签需要 MySQL 存储，当前为内存降级模式', 50101);
        }
        return $terms;
    }

    /** 存储层只认识「参数不合法」，接口层要把它分成「已存在」与「格式错」两种可分支的结果。 */
    private static function translate(InvalidArgumentException $e): ApiOperationException
    {
        $message = $e->getMessage();
        return str_contains($message, '术语已存在')
            ? new ApiOperationException($message, 40911)
            : new ApiOperationException($message, 42201);
    }
}
