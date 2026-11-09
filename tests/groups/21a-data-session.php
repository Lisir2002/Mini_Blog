<?php
declare(strict_types=1);

use Wx\Systems\Data\Db;
use Wx\Systems\Data\DataSessionInterface;

/**
 * 21a · 数据会话 —— 事务原子性、嵌套保存点、提交后副作用。
 *
 * 只验证「多语句是否同生共死」，不碰业务语义。
 */
echo "\n=== 21a. 数据会话：事务 / 保存点 / 提交后回调 ===\n";

$site   = tempSite('data-session');
$prefix = 'ds_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix);
$k->boot();

$connected = Db::connected($k);

check('事务：回调抛异常则整体回滚，不留半条记录', function () use ($k, $connected, $prefix): void {
    if (!$connected) {
        // 没有库时事务必须明确失败，而不是静默执行 —— 静默成功会让调用方误以为已落库。
        $threw = false;
        try {
            $k->container()->resolve(DataSessionInterface::class)->transaction(static fn(): int => 1);
        } catch (Throwable $e) {
            $threw = true;
        }
        truthy($threw, '无连接时事务应抛异常');
        return;
    }
    $db = $k->container()->resolve(\Wx\Contracts\DatabaseInterface::class);
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS ' . $prefix . 'probe (id INT PRIMARY KEY)');
    $db->pdo()->exec('DELETE FROM ' . $prefix . 'probe');
    $session = $k->container()->resolve(DataSessionInterface::class);
    try {
        $session->transaction(static function () use ($db, $prefix): void {
            $db->exec('INSERT INTO ' . $prefix . 'probe (id) VALUES (1)');
            throw new RuntimeException('故意失败');
        });
        truthy(false, '异常应向外抛出');
    } catch (RuntimeException $e) {
        truthy($e->getMessage() === '故意失败', '异常被替换了');
    }
    $rows = $db->query('SELECT COUNT(*) AS n FROM ' . $prefix . 'probe');
    truthy((int) ($rows[0]['n'] ?? -1) === 0, '回滚后仍有残留行');
});

check('嵌套：内层失败只回滚到保存点，外层继续提交', function () use ($k, $connected, $prefix): void {
    if (!$connected) return;
    $db = $k->container()->resolve(\Wx\Contracts\DatabaseInterface::class);
    $session = $k->container()->resolve(DataSessionInterface::class);
    $db->pdo()->exec('DELETE FROM ' . $prefix . 'probe');
    $session->transaction(static function () use ($db, $session, $prefix): void {
        $db->exec('INSERT INTO ' . $prefix . 'probe (id) VALUES (10)');
        try {
            $session->transaction(static function () use ($db, $prefix): void {
                $db->exec('INSERT INTO ' . $prefix . 'probe (id) VALUES (11)');
                throw new RuntimeException('内层失败');
            });
        } catch (RuntimeException $e) {
            // 外层决定吞掉：这正是保存点的意义 —— 只撤销这一小段。
        }
        $db->exec('INSERT INTO ' . $prefix . 'probe (id) VALUES (12)');
    });
    $ids = array_map(static fn(array $r): int => (int) $r['id'],
        $db->query('SELECT id FROM ' . $prefix . 'probe ORDER BY id'));
    truthy($ids === [10, 12], '保存点回滚范围错误，实际 ' . implode(',', $ids));
});

check('提交后副作用：回滚时丢弃，提交时执行一次', function () use ($k, $connected, $prefix): void {
    if (!$connected) return;
    $session = $k->container()->resolve(DataSessionInterface::class);
    $fired = 0;
    try {
        $session->transaction(static function () use ($session, &$fired): void {
            $session->afterCommit(static function () use (&$fired): void { $fired++; });
            throw new RuntimeException('回滚');
        });
    } catch (RuntimeException $e) {
        // 预期
    }
    truthy($fired === 0, '回滚后不应执行提交回调');

    $session->transaction(static function () use ($session, &$fired): void {
        $session->afterCommit(static function () use (&$fired): void { $fired++; });
    });
    truthy($fired === 1, '提交后应恰好执行一次，实际 ' . $fired);
});
