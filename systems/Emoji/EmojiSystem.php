<?php
declare(strict_types=1);
namespace Wx\Systems\Emoji;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Attachment\AttachmentRepositoryInterface;
use Wx\Systems\Data\Db;
use Wx\Systems\Data\RelationRegistry;

/**
 * 表情系统 —— 第 17 个系统层。
 *
 * 它只拥有两样东西：短码目录，以及把正文切成 token 的解析器。
 * 它不拥有任何标签、任何图片文件、任何渲染逻辑 —— 图片归附件系统，呈现归主题。
 */
final class EmojiSystem extends AbstractSystem
{
    public function name(): string { return 'emoji'; }

    public function register(Kernel $kernel): void
    {
        $kernel->container()->singleton(
            EmojiCatalogInterface::class,
            static function () use ($kernel): EmojiCatalogInterface {
                $db = Db::optional($kernel);
                return $db === null ? new MemoryEmojiCatalog() : new PdoEmojiCatalog($db);
            },
            'system:emoji',
            10
        );
        $kernel->container()->singleton(
            EmojiParser::class,
            static fn(): EmojiParser => new EmojiParser($kernel->container()->resolve(EmojiCatalogInterface::class)),
            'system:emoji:parser',
            10
        );

        // 解析是纯函数式的「把文本变成 token」：评论、文章、摘要都订阅同一个结果，
        // 谁都不需要知道表情从哪来，表情层也不需要知道谁在调用。
        $kernel->hooks()->filter('emoji.parse', static function (array $tokens, string $text) use ($kernel): array {
            /** @var EmojiParser $parser */
            $parser = $kernel->container()->resolve(EmojiParser::class);
            return $parser->parse($text);
        }, 10);

        // 列表专用：整页只查一次目录，避免「条数 × 短码」的查询放大。
        $kernel->hooks()->filter('emoji.parse_many', static function (array $defaults, array $texts) use ($kernel): array {
            /** @var EmojiParser $parser */
            $parser = $kernel->container()->resolve(EmojiParser::class);
            return $parser->parseMany(array_values($texts));
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        /** @var EmojiCatalogInterface $catalog */
        $catalog = $kernel->container()->resolve(EmojiCatalogInterface::class);
        if ($catalog instanceof PdoEmojiCatalog) $catalog->ensureSchema();

        // 自定义表情引用本站附件；附件被彻底删除后，这条引用就是孤儿。
        if ($kernel->container()->has(RelationRegistry::class)) {
            $kernel->container()->resolve(RelationRegistry::class)
                ->declare('emoji.attachment', 'emojis', 'attachment_id', 'attachments', 'id', true);
        }

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;

        $attachments = $kernel->container()->has(AttachmentRepositoryInterface::class)
            ? $kernel->container()->resolve(AttachmentRepositoryInterface::class)
            : new \Wx\Systems\Attachment\MemoryAttachmentRepository();
        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        (new EmojiOperations($catalog, $attachments))->register($registry);
    }
}
