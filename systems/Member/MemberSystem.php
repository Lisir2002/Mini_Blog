<?php
declare(strict_types=1);
namespace Wx\Systems\Member;

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\AbstractSystem;
use Wx\Core\Kernel;
use Wx\Systems\Data\Db;
use Wx\Systems\Data\RelationRegistry;

/**
 * 成员系统 —— 第 13 个系统层：头衔 / 经验 / 等级。
 *
 * 它回答的是「这个人在这里是什么身份」，而不是「这个人能做什么」：
 * 头衔与等级默认不产生任何权限，授权入口仍然只有权限系统一个。
 *
 * 与其它系统层的协作只走两条既有通道：
 *   - 事件面：订阅 content.created / comment.created 发放经验，广播 member.* 供主题与审计订阅；
 *   - 过滤面：向 user.profile.enrich 追加成长快照，用户系统并不知道成员系统的存在。
 *
 * 接上 MySQL 走 Pdo* 实现，拔掉库回退 Memory* 实现，两种实现同一套语义。
 */
final class MemberSystem extends AbstractSystem
{
    private ProfileStoreInterface $profiles;
    private LevelStoreInterface $levels;
    private TitleStoreInterface $titles;
    private XpAwarder $awarder;
    private TitleBook $titleBook;
    private MemberProfile $profile;

    public function name(): string { return 'member'; }

    public function register(Kernel $kernel): void
    {
        $db = Db::optional($kernel);
        $this->profiles = $db === null ? new MemoryProfileStore() : new PdoProfileStore($db);
        $this->levels   = $db === null ? new MemoryLevelStore() : new PdoLevelStore($db);
        $this->titles   = $db === null ? new MemoryTitleStore() : new PdoTitleStore($db);
        $this->titleBook = new TitleBook($this->titles);
        $this->awarder   = new XpAwarder($this->profiles, $this->levels, $kernel->hooks());
        $this->profile   = new MemberProfile($this->profiles, $this->levels, $this->titleBook, $kernel->hooks());

        // 三个存储协议是系统层内部契约（不是核心契约），因此不注册进容器：
        // 对外只暴露 member.* 接口与事件，避免把实现细节变成别人的依赖面。
        // 用户系统的「我的资料」是公开装配点：成员系统追加成长快照，用户系统不需要认识本层。
        // 静态闭包不能捕获 $this，先把用例对象取成局部变量（与权限层同一写法）。
        $profile = $this->profile;
        $kernel->hooks()->filter('user.profile.enrich', static function (array $identity) use ($profile): array {
            $userId = (string) ($identity['user_id'] ?? '');
            if ($userId === '') return $identity;
            $identity['member'] = $profile->of($userId);
            return $identity;
        }, 10);

        $this->subscribeContent($kernel, $this->awarder);
        $this->subscribeComment($kernel, $this->awarder);
        $kernel->hooks()->on('member.level.changed', static function (array $event) use ($kernel): void {
            $kernel->trace('成员等级提升', $event);
        }, 10);
    }

    public function boot(Kernel $kernel): void
    {
        $this->profiles->ensureSchema();   // 内存实现为空操作
        $this->levels->seed();             // 只补基线，不覆盖站长修改
        $this->titles->seed();

        // 档案归属于账号：账号被删而档案留下，就是没人能认领的孤儿。
        if ($kernel->container()->has(RelationRegistry::class)) {
            $kernel->container()->resolve(RelationRegistry::class)
                ->declare('member.profile_user', 'member_profiles', 'user_id', 'user_accounts', 'id');
        }

        if (!$kernel->container()->has(ApiRegistryInterface::class)) return;

        /** @var ApiRegistryInterface $registry */
        $registry = $kernel->container()->resolve(ApiRegistryInterface::class);
        (new MemberQueryOperations($this->profile, $this->titleBook, $this->levels))->register($registry);
        (new MemberAdminOperations($this->awarder, $this->titleBook, $this->levels, $this->profile))->register($registry);
    }

    /** 发布文章发经验：event_key 用文章 ID，重复触发（重试 / 事件重放）不会重复加分。 */
    private function subscribeContent(Kernel $kernel, XpAwarder $awarder): void
    {
        $kernel->hooks()->on('content.created', static function (mixed $id, array $post = [], ?array $identity = null) use ($kernel, $awarder): void {
            $userId = (string) ($identity['user_id'] ?? '');
            if ($userId === '') return;
            try {
                $awarder->award($userId, 'post.publish', 'content:' . (string) $id,
                    '文章：' . (string) ($post['slug'] ?? ''));
            } catch (\Throwable $e) {
                // 经验是附加价值，不能反向拖垮正文写入；失败只留痕。
                $kernel->trace('经验发放失败', ['action' => 'post.publish', 'error' => $e->getMessage()]);
            }
        }, 10);
    }

    /** 评论发经验：匿名评论没有主体，直接跳过。 */
    private function subscribeComment(Kernel $kernel, XpAwarder $awarder): void
    {
        $kernel->hooks()->on('comment.created', static function (mixed $id, array $comment = [], ?array $identity = null) use ($kernel, $awarder): void {
            $userId = (string) ($identity['user_id'] ?? '');
            if ($userId === '') return;
            try {
                $awarder->award($userId, 'comment.create', 'comment:' . (string) $id,
                    '评论：' . (string) ($comment['post_slug'] ?? ''));
            } catch (\Throwable $e) {
                $kernel->trace('经验发放失败', ['action' => 'comment.create', 'error' => $e->getMessage()]);
            }
        }, 10);
    }
}
