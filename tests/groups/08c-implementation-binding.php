<?php
declare(strict_types=1);

use Wx\Contracts\CommentStoreInterface;
use Wx\Contracts\ContentRepositoryInterface;
use Wx\Systems\Comment\PdoCommentStore;
use Wx\Systems\Content\PdoContentRepository;
use Wx\Systems\User\PdoUserProvider;
use Wx\Contracts\UserProviderInterface;

echo "\n=== 8c. 契约与实现绑定核对 ===\n";

check('用户 / 评论 / 内容默认绑定 MySQL 实现（契约与实现分离兑现）', function (): void {
    $k = kernel(null);
    $k->boot();
    truthy($k->container()->resolve(UserProviderInterface::class) instanceof PdoUserProvider, '用户未走 MySQL');
    truthy($k->container()->resolve(CommentStoreInterface::class) instanceof PdoCommentStore, '评论未走 MySQL');
    truthy($k->container()->resolve(ContentRepositoryInterface::class) instanceof PdoContentRepository, '内容未走 MySQL');
});
