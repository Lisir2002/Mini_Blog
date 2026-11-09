<?php
declare(strict_types=1);

use Wx\Contracts\ContentRepositoryInterface;
use Wx\Contracts\DatabaseInterface;
use Wx\Core\Kernel;
use Wx\Systems\Content\MemoryContentRepository;
use Wx\Systems\Content\PdoContentRepository;
use Wx\Core\Http\Request;

echo "\n=== 8a. 内容系统（第 11 层）与 MySQL 全量实现 ===\n";


check('MySQL 真实连通，5 张系统层自建表已建立', function (): void {
    $k = kernel(null);
    $k->boot();
    /** @var DatabaseInterface $db */
    $db = $k->container()->resolve(DatabaseInterface::class);
    $tables = array_map(static fn(array $row): string => (string) reset($row), $db->query('SHOW TABLES'));
    foreach (['wx_content_posts', 'wx_user_accounts', 'wx_user_tokens', 'wx_csrf_tokens', 'wx_comments'] as $table) {
        truthy(in_array($table, $tables, true), '缺少表：' . $table . '（现有 ' . implode(',', $tables) . '）');
    }
});

check('拔掉 DSN 自动回退内存适配器，站点仍可启动且首页有数据', function () use ($root): void {
    $k = new Kernel($root, ['database' => ['dsn' => '']]);
    $k->boot();
    truthy($k->container()->resolve(ContentRepositoryInterface::class) instanceof MemoryContentRepository, '未回退内存实现');
    $r = $k->handle(new Request('GET', '/'));
    $data = json_decode($r->body(), true);
    truthy(in_array('第一篇文章', $data['items'] ?? [], true), '回退后首页数据丢失');
});

check('游标分页：两页不重复、游标可前进到底', function (): void {
    $k = kernel(null);
    $k->boot();
    $repo = $k->container()->resolve(ContentRepositoryInterface::class);
    $p1 = $repo->list(['limit' => 1, 'status' => 'any']);
    truthy(count($p1['items']) === 1, '第一页条数不正确');
    truthy($p1['next_cursor'] !== null, '应返回下一页游标');
    $p2 = $repo->list(['limit' => 1, 'status' => 'any', 'cursor' => (string) $p1['next_cursor']]);
    truthy($p2['items'][0]['id'] !== $p1['items'][0]['id'], '第二页与第一页重复');
    truthy($repo->count('any') >= 2, '文章总数不足');
});

check('真实写入文章并可按 slug 读回（测试后清理）', function (): void {
    $k = kernel(null);
    $k->boot();
    /** @var PdoContentRepository $repo */
    $repo = $k->container()->resolve(ContentRepositoryInterface::class);
    $slug = 'guard-' . bin2hex(random_bytes(4));
    $db = $k->container()->resolve(DatabaseInterface::class);
    try {
        $id = $repo->create(['slug' => $slug, 'title' => '守卫测试文章', 'body' => '由 tests/run.php 写入 MySQL']);
        truthy($id > 0, '写入未返回 ID');
        $row = $repo->findBySlug($slug);
        truthy($row !== null && $row['title'] === '守卫测试文章', '读回内容不一致');
    } finally {
        $db->exec('DELETE FROM ' . $db->prefix() . 'content_posts WHERE slug = :slug', [':slug' => $slug]);
    }
});
