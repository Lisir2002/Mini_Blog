<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use PDOException;
use Throwable;
use Wx\Contracts\DatabaseInterface;

/**
 * 事务的实现 —— 三件事：嵌套保存点、回滚即丢弃副作用、提交后才放行副作用。
 *
 * afterCommit 的存在理由：有些动作一旦做了就无法用回滚撤销（写文件、发通知、推事件）。
 * 把它们排在提交之后，才不会出现「数据库回滚了，文件却已经落盘」的半成品状态。
 */
final class DataSession implements DataSessionInterface
{
    private int $depth = 0;
    /** @var list<callable> */
    private array $pending = [];

    public function __construct(private DatabaseInterface $db) {}

    public function transaction(callable $fn, int $attempts = 1): mixed
    {
        $attempts = max(1, $attempts);
        for ($try = 1; ; $try++) {
            try {
                return $this->run($fn);
            } catch (PDOException $e) {
                if ($try >= $attempts || !self::isRetryable($e)) throw $e;
            }
        }
    }

    /** @param callable(self): mixed $fn */
    private function run(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        $savepoint = 'wx_sp_' . $this->depth;
        if ($this->depth === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        }
        $this->depth++;
        try {
            $result = $fn($this);
            $this->depth--;
            if ($this->depth === 0) {
                $pdo->commit();
                $this->flush();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $e) {
            $this->depth--;
            if ($this->depth === 0) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $this->pending = [];
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            }
            throw $e;
        }
    }

    public function afterCommit(callable $fn): void
    {
        if ($this->depth === 0) { $fn(); return; }
        $this->pending[] = $fn;
    }

    public function inTransaction(): bool { return $this->depth > 0; }

    public function depth(): int { return $this->depth; }

    private function flush(): void
    {
        $queue = $this->pending;
        $this->pending = [];
        foreach ($queue as $fn) $fn();
    }

    /** 只有死锁与锁等待超时值得重试；其余异常重试只会放大问题。 */
    private static function isRetryable(PDOException $e): bool
    {
        return in_array((string) $e->getCode(), ['40001', 'HY000'], true)
            && (int) ($e->errorInfo[1] ?? 0) === 1213;
    }
}
