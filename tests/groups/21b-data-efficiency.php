<?php
declare(strict_types=1);

use Wx\Systems\Data\QueryProfiler;
use Wx\Systems\Emoji\EmojiCatalogInterface;
use Wx\Systems\Emoji\EmojiParser;
use Wx\Systems\Emoji\MemoryEmojiCatalog;

/**
 * 21b · 数据效率 —— 「列表查询必须批量」这条规矩的可执行版本。
 *
 * 不测耗时（不可复现），测调用次数：解析一段正文只允许问目录一次。
 */
echo "\n=== 21b. 数据效率：批量取数 / 查询计数 ===\n";

/** 记账用的目录：转调内存实现，同时记录 find / findMany 各被调了几次。 */
final class CountingCatalog implements EmojiCatalogInterface
{
    public int $find = 0;
    public int $findMany = 0;
    private MemoryEmojiCatalog $inner;

    public function __construct() { $this->inner = new MemoryEmojiCatalog(); }

    public function find(string $code): ?array { $this->find++; return $this->inner->find($code); }

    public function findMany(array $codes): array { $this->findMany++; return $this->inner->findMany($codes); }

    public function all(): array { return $this->inner->all(); }

    public function custom(): array { return $this->inner->custom(); }

    public function register(array $emoji): bool { return $this->inner->register($emoji); }

    public function remove(string $code): bool { return $this->inner->remove($code); }
}

check('单段解析：多个短码只问目录一次，且不再逐条 find', function (): void {
    $catalog = new CountingCatalog();
    $parser = new EmojiParser($catalog);
    $tokens = $parser->parse('你好 :smile: 再见 :tada: 还有 :heart:');
    truthy($catalog->findMany === 1, 'findMany 应只调用 1 次，实际 ' . $catalog->findMany);
    truthy($catalog->find === 0, '不应再逐条 find，实际 ' . $catalog->find);
    $emoji = array_values(array_filter($tokens, static fn(array $t): bool => $t['type'] === 'emoji'));
    truthy(count($emoji) === 3, '应解析出 3 个表情 token，实际 ' . count($emoji));
});

check('批量解析：N 段正文只问目录一次', function (): void {
    $catalog = new CountingCatalog();
    $parser = new EmojiParser($catalog);
    $lists = $parser->parseMany(['第一段 :smile:', '第二段没有表情', '第三段 :tada: 与 :heart:']);
    truthy(count($lists) === 3, '应返回 3 段 token 列表');
    truthy($catalog->findMany === 1, '整页只应查目录 1 次，实际 ' . $catalog->findMany);
    truthy($catalog->find === 0, '批量路径不应退回逐条 find');
    truthy($lists[1][0]['type'] === 'text', '无表情的段落应退化为单个 text token');
});

check('未知短码保持字面量，批量与单条语义一致', function (): void {
    $parser = new EmojiParser(new CountingCatalog());
    $single = $parser->parse('前 :not_a_real_code: 后');
    $batch = $parser->parseMany(['前 :not_a_real_code: 后'])[0];
    truthy($single === $batch, '两条路径对未知短码的处理不一致');
    truthy($single[0]['type'] === 'text' && str_contains((string) $single[0]['value'], ':not_a_real_code:'),
        '未知短码不应被吞掉');
});

check('查询计数器：计数、总耗时与慢查询留样', function (): void {
    $profiler = new QueryProfiler();
    $profiler->hit('SELECT 1', 1.0);
    $profiler->hit('SELECT 2', 80.0);
    truthy($profiler->count() === 2, '计数错误');
    truthy($profiler->totalMs() >= 81.0, '总耗时应累加');
    $slow = $profiler->slow(50.0);
    truthy(count($slow) === 1 && $slow[0]['sql'] === 'SELECT 2', '慢查询留样错误');
    $profiler->reset();
    truthy($profiler->count() === 0 && $profiler->slow(0.0) === [], '重置不彻底');
});
