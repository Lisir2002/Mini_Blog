<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

/**
 * 事务会话契约 —— 让「多语句必须原子」有一个唯一的入口。
 *
 * 为什么不是仓储各自 beginTransaction：事务边界属于用例，不属于单条 SQL；
 * 而且嵌套时手写 SAVEPOINT 几乎必错。这里把嵌套、回滚、提交后回调收敛到一处。
 */
interface DataSessionInterface
{
    /**
     * 在事务中执行；回调抛异常则整体回滚（嵌套时只回滚到本层保存点）。
     *
     * @param callable(self): mixed $fn
     * @param int $attempts 死锁重试次数（1 表示不重试）
     */
    public function transaction(callable $fn, int $attempts = 1): mixed;

    /** 提交成功后才执行（用于「字节已落盘 / 事件该发了」这类外部副作用）。回滚时丢弃。 */
    public function afterCommit(callable $fn): void;

    public function inTransaction(): bool;

    /** 0 表示不在事务内；1 及以上表示嵌套层数。 */
    public function depth(): int;
}
