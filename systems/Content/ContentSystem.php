<?php
declare(strict_types=1);
namespace Wx\Systems\Content;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Data\Db;
use Wx\Systems\Data\RelationRegistry;

/**
 * 内容系统 —— 第 11 个系统层，博客的正文数据模型。
 *
 * 它不在最初的需求清单里，接入路径却与日志系统完全相同：一个目录 + 一份 system.json，
 * 内核零改动、其它系统层零改动。这是「保留可接入新系统层能力」的第一次真实检验。
 *
 * 自身持有：ContentRepositoryInterface 契约实现、migrations SQL、生命周期钩子、
 * 以及 content.* 规范化接口的执行者。核心不认识「文章」这个词。
 */
final class ContentSystem extends AbstractSystem
{
    public function name(): string { return 'content'; }

    public function register(Kernel $kernel): void
    {
        // 惰性工厂：接上 MySQL 走 PDO 实现，拔掉库自动回退内存适配器。
        // 主题、插件、首页包、核心都只认 ContentRepositoryInterface。
        $kernel->container()->singleton(
            ContentRepositoryInterface::class,
            static function () use ($kernel): ContentRepositoryInterface {
                $db = Db::optional($kernel);
                return $db === null
                    ? new MemoryContentRepository()
                    : new PdoContentRepository($db);
            },
            'system:content',
            10
        );

        // 业务钩子由系统层自行声明，核心的钩子清单不会因新增业务而膨胀
        $kernel->hooks()->filter('content.before_save', static function (array $post): array {
            $post['author']      = $post['author'] ?? '';
            $post['status']      = $post['status'] ?? 'published';
            $post['submitted_at']= $post['submitted_at'] ?? date(DATE_ATOM);
            return $post;
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        /** @var ContentRepositoryInterface $repo */
        $repo = $kernel->container()->resolve(ContentRepositoryInterface::class);
        if ($repo instanceof PdoContentRepository) {
            $repo->ensureSchema();   // 幂等建表：SQL 归本系统层，核心零表
            $repo->seed();           // 空库写入示例文章
        }

        // 统计层要「文章存量」，但统计层不认识文章表。由本层自报，两个维度各算一次。
        // 没有统计层时这个过滤器无人消费，零副作用。
        // v0.28 起 count() 已排除回收站与未到点的定时文章：存量口径必须与列表一致，
        // 否则首页写「共 12 篇」而列表只有 9 篇，读的人只会以为分页坏了。
        $kernel->hooks()->filter('metric.collect', static function (array $samples) use ($repo): array {
            $samples[] = ['name' => 'content_posts', 'labels' => ['status' => 'published'], 'value' => $repo->count('published')];
            $samples[] = ['name' => 'content_posts', 'labels' => ['status' => 'any'], 'value' => $repo->count('any')];
            $samples[] = ['name' => 'content_posts', 'labels' => ['status' => 'trash'], 'value' => $repo->count('trash')];
            return $samples;
        }, 10);

        // 术语表与关系表归属本系统层：声明出来，data.php verify 才能发现「绑到了已删除术语」。
        if ($kernel->container()->has(RelationRegistry::class)) {
            $relations = $kernel->container()->resolve(RelationRegistry::class);
            $relations->declare('content.term_relation', 'content_term_relations', 'term_id', 'content_terms', 'id');
            $relations->declare('content.term_post', 'content_term_relations', 'post_id', 'content_posts', 'id');
        }

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        $perPage = (int) $kernel->config()->get('content.per_page', 10);

        // 分类标签需要两张表；没有 MySQL 时传 null，相关接口会明确报 50101 而不是返回空列表。
        $terms = $repo instanceof PdoContentRepository ? ContentTermStore::fromKernel($kernel) : null;

        $registry->registerOperation('content.posts', static function (array $in) use ($repo, $perPage, $kernel, $terms): array {
            $result = $repo->list([
                'limit'   => (int)    ($in['limit']   ?? $perPage),
                'cursor'  => (string) ($in['cursor']  ?? ''),
                'status'  => (string) ($in['status']  ?? 'published'),
                'keyword' => (string) ($in['keyword'] ?? ''),
                'term'    => (string) ($in['term']    ?? ''),
                'kind'    => (string) ($in['kind']    ?? ''),
                'pinned'  => (bool)   ($in['pinned']  ?? false),
            ]);
            // 文章权限是可编程裁决点：内容层不认识权限层，只把列表交给钩子过滤
            $result['items'] = (array) $kernel->hooks()->apply('content.visible', $result['items'], $in, $kernel);
            $result['items'] = self::presentMany((array) $result['items'], $kernel, $terms);
            return $result;
        });

        $registry->registerOperation('content.post', static function (array $in) use ($repo, $kernel, $terms): array {
            $post = $repo->findBySlug((string) ($in['slug'] ?? ''));
            if ($post === null) {
                throw new ApiOperationException('文章不存在', 40403);
            }
            // 定时发布在到点前对公开读路径就是「不存在」。只在列表里藏起来不够：
            // 知道 slug 的人照样能提前读到，而那正是排期想避免的事。
            if (($post['status'] ?? '') === ContentStatus::PUBLISHED
                && !empty($post['publish_at']) && (int) strtotime((string) $post['publish_at']) > time()) {
                throw new ApiOperationException('文章不存在', 40403);
            }
            $visible = (array) $kernel->hooks()->apply('content.visible', [$post], $in, $kernel);
            if ($visible === []) {
                throw new ApiOperationException('无权访问该文章', 40301);
            }
            return self::present((array) ($visible[0] ?? $post), $kernel, $terms);
        });

        $registry->registerOperation('content.create', static function (array $in) use ($repo, $kernel): array {
            $slug = trim((string) ($in['slug'] ?? ''));
            if ($slug === '') {
                $slug = 'post-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
            }
            // slug 是用户给的地址，重复属于「状态冲突」而不是「服务器出错」。
            // 不预检的话唯一索引会抛 PDOException(23000)，被分发器翻译成 50001 ——
            // 一个纯粹由客户端输入引发的问题，却报告成服务端故障，重试永远不会成功。
            if ($repo->findBySlug($slug) !== null) {
                throw new ApiOperationException('slug 已被占用：' . $slug, 40901);
            }
            $post = (array) $kernel->hooks()->apply('content.before_save', [
                'slug'   => $slug,
                'title'  => (string) ($in['title']  ?? ''),
                'body'   => (string) ($in['body']   ?? ''),
                'status' => (string) ($in['status'] ?? 'published'),
                'author' => (string) ($in['author'] ?? ''),
            ]);
            $id = $repo->create($post);   // 非法参数抛 InvalidArgumentException → 分发器映射 42201
            // 内容事实广播：成员系统据此发经验、主题据此清缓存、插件据此推送。
            // 内容层不知道订阅者是谁，也不因订阅者缺席改变写入结果。
            $kernel->hooks()->emit('content.created', $id, $post, $in['_identity'] ?? null);
            return ['id' => $id, 'slug' => (string) $post['slug'], 'created' => true];
        });

        $registry->registerOperation('content.stats', static function (array $in) use ($repo): array {
            return [
                'published' => $repo->count('published'),
                'total'     => $repo->count('any'),
            ];
        });

        // 字段级写校验：不是第四层权限，而是把 resource.field 这一维度用在写路径上。
        // 每个被修改的字段单独询问一次 decide()，非 allow 一律 fail-closed 拒绝整次写入，
        // 避免「用户改不了 body，但请求里夹带 body 仍然写进去」这类部分成功的漏洞。
        $registry->registerOperation('content.update', static function (array $in) use ($repo, $kernel): array {
            $slug = trim((string) ($in['slug'] ?? ''));
            if ($slug === '') throw new ApiOperationException('slug 必填', 42201);
            $post = $repo->findBySlug($slug);
            if ($post === null) throw new ApiOperationException('文章不存在', 40403);

            $acl = $kernel->container()->has(AccessControlInterface::class)
                ? $kernel->container()->resolve(AccessControlInterface::class) : null;
            if ($acl === null) throw new ApiOperationException('权限层不可用，写入被拒绝', 40303);

            $identity = $in['_identity'] ?? null;
            $changes  = [];
            foreach (['title', 'body', 'status', 'pinned', 'weight'] as $field) {
                if (!array_key_exists($field, $in)) continue;
                // 资源标识统一用 slug：读路径（content.visible）与写路径必须指向同一个资源标识，
                // 否则按 slug 登记的 ACL 只保护读、按数字 id 登记的只保护写，同一条授权会两处打架。
                $decision = $acl->decide($identity, 'content.edit', [
                    'type'  => 'post',
                    'id'    => (string) ($post['slug'] ?? ''),
                    'field' => $field,
                ]);
                if ($decision !== AccessControlInterface::ALLOW) {
                    throw new ApiOperationException('字段写入被拒绝：' . $field, 40303);
                }
                $changes[$field] = $in[$field];
            }
            if ($changes === []) throw new ApiOperationException('没有可更新的字段', 42201);
            $applied = $repo->update($slug, $changes);
            // rowCount 为 0 有两种可能：写入了相同的值（正常），或文章刚被别人删掉（必须报错）。
            // 区分它们的唯一办法是再读一次，而不是把 false 当成成功。
            if (!$applied && $repo->findBySlug($slug) === null) {
                throw new ApiOperationException('文章在回收站，请先恢复', 40909);
            }
            return ['slug' => $slug, 'updated' => array_keys($changes)];
        });

        (new ContentLifecycleOperations($repo))->register($registry, $kernel);
        (new ContentTaxonomyOperations($repo, $terms))->register($registry);
    }

    /**
     * 正文原样返回，另附 emoji token。token 是数据不是 HTML：
     * 主题把它渲染成文字或图片，模型也能直接读懂；没有表情系统时退化为单个 text token。
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function present(array $post, Kernel $kernel, ?ContentTermStore $terms): array
    {
        $body = (string) ($post['body'] ?? '');
        $post['body_tokens'] = $kernel->hooks()->apply(
            'emoji.parse', [['type' => 'text', 'value' => $body]], $body
        );
        $post['terms'] = $terms === null ? [] : $terms->forPost((int) ($post['id'] ?? 0));
        return $post;
    }

    /**
     * 列表批量呈现：表情与术语都一次取全，避免每篇文章两条 SQL。
     * 缺少表情系统时钩子不注册，$defaults 原样返回，行为与单篇一致。
     * @param list<array<string, mixed>> $posts
     * @return list<array<string, mixed>>
     */
    private static function presentMany(array $posts, Kernel $kernel, ?ContentTermStore $terms): array
    {
        $texts = [];
        foreach ($posts as $post) $texts[] = (string) ($post['body'] ?? '');
        $defaults = array_map(static fn(string $text): array => [['type' => 'text', 'value' => $text]], $texts);
        $tokenLists = (array) $kernel->hooks()->apply('emoji.parse_many', $defaults, $texts);
        $termMap = $terms === null ? [] : $terms->forPosts(array_map(
            static fn(array $post): int => (int) ($post['id'] ?? 0),
            $posts
        ));
        foreach ($posts as $index => $post) {
            $post['body_tokens'] = $tokenLists[$index] ?? ($defaults[$index] ?? []);
            $post['terms'] = $termMap[(int) ($post['id'] ?? 0)] ?? [];
            $posts[$index] = $post;
        }
        return $posts;
    }
}
