<?php
declare(strict_types=1);
namespace Wx\Tools\Scale;

use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Comment\PdoCommentStore;
use Wx\Systems\Content\PdoContentRepository;
use Wx\Systems\Data\Schema;

/**
 * 造数 —— 只在独立前缀的临时表上写，跑完由调用方清理。
 *
 * 为什么用多值 INSERT 而不是逐行：逐行 5 万次往返要几十秒，测量还没开始就先失败；
 * 分批 500/1000 行能把 10 万行压到 1 秒内，而这不影响被测查询的性能特征 ——
 * 被测的是读路径的索引命中，不是写路径。
 */
final class Seeder
{
    public function __construct(
        private DatabaseInterface $db,
        private PdoContentRepository $posts,
        private PdoCommentStore $comments
    ) {}

    /** 重建两张表 —— drop 之后必须让 Schema 指纹作废，否则 ensureSchema 会以为「建过了」。 */
    public function reset(): void
    {
        $this->comments->dropSchema();
        $this->posts->dropSchema();
        Schema::invalidate($this->db);
        $this->posts->ensureSchema();
        $this->comments->ensureSchema();
    }

    public function posts(int $count): float
    {
        $start = hrtime(true);
        $table = $this->db->prefix() . 'content_posts';
        $this->db->pdo()->beginTransaction();
        $buf = [];
        for ($i = 1; $i <= $count; $i++) {
            $buf[] = sprintf(
                "('post-%06d','标题 %d','%s','摘要 %d','published','admin',NOW(),NOW())",
                $i, $i, str_repeat('正文内容 ', 20), $i
            );
            if (count($buf) === 500) {
                $this->db->exec("INSERT INTO {$table} (slug,title,body,excerpt,status,author,created_at,updated_at) VALUES " . implode(',', $buf));
                $buf = [];
            }
        }
        if ($buf !== []) {
            $this->db->exec("INSERT INTO {$table} (slug,title,body,excerpt,status,author,created_at,updated_at) VALUES " . implode(',', $buf));
        }
        $this->db->pdo()->commit();
        return (hrtime(true) - $start) / 1e6;
    }

    /**
     * $hot 条评论集中挂在一篇文章上，其余均摊 —— 前者测「热文读放大」，后者测普通文章。
     * 状态用 published：CommentPolicy::PUBLISHED，写别的值会让 countForPost 静默返回 0。
     */
    public function comments(int $count, string $hotSlug, int $hot, int $postCount): float
    {
        $start = hrtime(true);
        $table = $this->db->prefix() . 'comments';
        $hot = min($hot, $count);
        $this->db->pdo()->beginTransaction();
        $buf = [];
        for ($i = 1; $i <= $count; $i++) {
            if ($i <= $hot) {
                $slug = $hotSlug;
            } else {
                $slug = sprintf('post-%06d', (($i - $hot) % max(1, $postCount)) + 1);
            }
            $buf[] = sprintf("('%s','读者%d','第 %d 条评论 %s','published',NOW())", $slug, $i % 200, $i, str_repeat('内容', 20));
            if (count($buf) === 1000) {
                $this->db->exec("INSERT INTO {$table} (post_slug,author,body,status,created_at) VALUES " . implode(',', $buf));
                $buf = [];
            }
        }
        if ($buf !== []) {
            $this->db->exec("INSERT INTO {$table} (post_slug,author,body,status,created_at) VALUES " . implode(',', $buf));
        }
        $this->db->pdo()->commit();
        return (hrtime(true) - $start) / 1e6;
    }
}
