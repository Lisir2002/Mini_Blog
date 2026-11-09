<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use PDO;
use PDOStatement;

/**
 * 带计时的语句对象 —— 只重写 execute()，因为真正的执行发生在这一步。
 *
 * 在 prepare() 上计时是错的：关闭模拟预处理后，prepare 只是把语句发给服务端编译，
 * 耗时不代表查询成本。构造函数必须非 public，这是 PDO 的硬性要求（父类会内部实例化）。
 */
final class ProfiledStatement extends PDOStatement
{
    protected function __construct(private QueryProfilerInterface $profiler) {}

    public function execute(?array $params = null): bool
    {
        $start = microtime(true);
        $ok = parent::execute($params);
        $this->profiler->hit((string) $this->queryString, (microtime(true) - $start) * 1000.0);
        return $ok;
    }
}
