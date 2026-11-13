<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Pages\ContentAdmin\ContentAdminBlocks;
use Wx\Pages\ContentAdmin\ContentAdminPager;
use Wx\Pages\ContentAdmin\ContentAdminQuery;

/**
 * 71 · 回收站深翻页（纯函数 / 静态核对）
 *
 * 71b 从真实 HTTP + MariaDB 取运行时证据，本组把不依赖数据库的那半锁死：
 * 游标必须原样透传与带回、base64 里的 + / = 必须逐字符编码、四种翻页形状
 * 可穷举、降级与空页文案不能撒谎、页面不复制系统层的游标校验。
 */
echo "\n=== 71. 回收站深翻页：游标透传 / 链接编码 / 边界文案 ===\n";

/** 假的接口清单：按调用次序返回预置页，并记录每次入参。 */
$fake = static function (array $pages): ApiRegistryInterface {
    return new class($pages) implements ApiRegistryInterface {
        /** @var list<array<string, mixed>> */
        public array $calls = [];
        public function __construct(private array $pages) {}
        public function endpoints(): array { return []; }
        public function find(string $id): ?array { return null; }
        public function match(string $method, string $path): ?array { return null; }
        public function registerOperation(string $operation, callable $handler): void {}
        public function execute(string $operation, array $input): mixed
        {
            $i = count($this->calls);
            $this->calls[] = $input;
            return $this->pages[$i] ?? ['items' => [], 'total' => 0, 'next_cursor' => null];
        }
    };
};

/** @return array<string, mixed> */
$page = static function (int $n, int $from, string $next, int $total): array {
    $items = [];
    for ($i = 0; $i < $n; $i++) {
        $items[] = ['slug' => 'p' . ($from + $i), 'title' => '文章', 'status' => 'published'];
    }
    return ['items' => $items, 'total' => $total, 'next_cursor' => $next];
};

/** @return list<string> */
$labels = static function (?array $block): array {
    if ($block === null) return [];
    return array_map(static fn(array $i): string => (string) ($i['label'] ?? ''),
        (array) ($block['items'] ?? []));
};
/** @return list<array<string, mixed>> */
$formsOf = static function (array $blocks): array {
    return array_values(array_filter($blocks,
        static fn(array $b): bool => (string) ($b['type'] ?? '') === 'form'));
};
/** @return list<string> */
$hiddenNames = static function (array $form): array {
    return array_map(static fn(array $h): string => (string) ($h['name'] ?? ''),
        (array) ($form['hidden'] ?? []));
};

check('trash() 首页不塞空游标，第二页原样透传，limit 恒为 20',
    function () use ($fake, $page): void {
        $reg = $fake([$page(20, 1, 'MjA=', 45), $page(20, 21, 'NDA=', 45)]);
        $q = new ContentAdminQuery($reg);
        $a = $q->trash();
        $b = $q->trash('MjA=');
        truthy($reg->calls[0] === ['limit' => 20],
            '首页不该带空 cursor：' . json_encode($reg->calls[0]));
        truthy($reg->calls[1] === ['limit' => 20, 'cursor' => 'MjA='], '第二页没透传 cursor');
        truthy($a['next_cursor'] === 'MjA=' && $a['cursor'] === '', '首页出站游标不对');
        truthy($b['next_cursor'] === 'NDA=' && $b['cursor'] === 'MjA=', '第二页没带回当前游标');
        truthy($a['total'] === 45 && $a['shown'] === 20 && $a['truncated'] === true,
            'total / shown 被游标改写了');
    });

check('shape() 把 null 游标归一为空串，live() 不凭空造翻页',
    function () use ($fake, $page): void {
        $reg = $fake([['items' => [], 'total' => 0, 'next_cursor' => null]]);
        $q = new ContentAdminQuery($reg);
        $t = $q->trash();
        truthy($t['next_cursor'] === '' && $t['cursor'] === '', 'null 游标没归一成空串');
        truthy($q->live()['cursor'] === '', '活文章列表不该有当前页游标');
    });

check('nextHref 对 base64 的 + / = 逐字符 rawurlencode',
    function (): void {
        truthy(ContentAdminPager::nextHref('MjA=') === '/console/content?view=trash&cursor=MjA%3D',
            'base64 尾部的 = 没编码，query 会被截断');
        truthy(ContentAdminPager::nextHref('a+b=c') === '/console/content?view=trash&cursor=a%2Bb%3Dc',
            '+ / = 必须分别编码，不能拼成裸 query');
        truthy(ContentAdminPager::firstHref() === '/console/content?view=trash', '第一页链接不对');
    });

check('pager 只在回收站产出，首页 / 中间页 / 末页 / 无后续四形可穷举',
    function () use ($labels): void {
        truthy(ContentAdminPager::build('live',
            ['cursor' => '', 'next_cursor' => 'MjA=', 'shown' => 20, 'total' => 45]) === null,
            '活文章视图不该出现回收站翻页');
        truthy($labels(ContentAdminPager::build('trash',
            ['cursor' => '', 'next_cursor' => 'MjA=', 'shown' => 20, 'total' => 45])) === ['下一页 →'],
            '首页应只有下一页');
        $mid = ContentAdminPager::build('trash',
            ['cursor' => 'MjA=', 'next_cursor' => 'NDA=', 'shown' => 20, 'total' => 45]);
        truthy($labels($mid) === ['← 回到第一页', '下一页 →'], '中间页缺方向');
        truthy((string) ($mid['items'][1]['href'] ?? '') === '/console/content?view=trash&cursor=NDA%3D',
            '下一页链接没编码');
        truthy($labels(ContentAdminPager::build('trash',
            ['cursor' => 'NDA=', 'next_cursor' => '', 'shown' => 5, 'total' => 45])) === ['← 回到第一页'],
            '末页不该还有下一页');
        truthy(ContentAdminPager::build('trash',
            ['cursor' => '', 'next_cursor' => '', 'shown' => 3, 'total' => 3]) === null,
            '无需翻页时不该产出空导航条');
        $empty = ContentAdminPager::build('trash',
            ['cursor' => 'NDA=', 'next_cursor' => '', 'shown' => 0, 'total' => 45]);
        truthy(str_contains((string) ($empty['title'] ?? ''), '已经空了'), '空页没有显式说明');
    });

check('escape() 只有非法游标降级态才给脱困链接', function (): void {
    truthy(ContentAdminPager::escape(null) === null, '正常态不该有脱困链接');
    truthy(ContentAdminPager::escape(['phase' => 'failed']) === null, '操作失败态不该有脱困链接');
    $e = ContentAdminPager::escape(['phase' => 'invalid']);
    truthy($e !== null && (string) ($e['id'] ?? '') === 'content-pager-escape',
        '非法游标没给脱困链接');
    truthy((string) ($e['items'][0]['href'] ?? '') === '/console/content?view=trash',
        '脱困链接不是回收站第一页');
});

check('Blocks：翻页块落在末尾，行表单把 cursor 带回 POST',
    function () use ($page, $formsOf, $hiddenNames): void {
        $raw = $page(20, 21, 'NDA=', 45);
        $data = ['items' => $raw['items'], 'total' => 45, 'shown' => 20, 'truncated' => true,
                 'next_cursor' => 'NDA=', 'cursor' => 'MjA='];
        $blocks = ContentAdminBlocks::build('trash', $data, 'ticket',
            ['restore' => true, 'purge' => true], null, false);
        $last = (array) end($blocks);
        truthy((string) ($last['id'] ?? '') === 'content-pager', '翻页块不在末尾');
        $forms = $formsOf($blocks);
        truthy($forms !== [], '没有行表单');
        truthy(in_array('cursor', $hiddenNames($forms[0]), true), '行表单没带回 cursor');
        truthy(in_array('slug', $hiddenNames($forms[0]), true), '行表单丢了 slug');

        $first = ['items' => $raw['items'], 'total' => 45, 'shown' => 20, 'truncated' => true,
                  'next_cursor' => 'NDA=', 'cursor' => ''];
        $forms2 = $formsOf(ContentAdminBlocks::build('trash', $first, 'ticket',
            ['restore' => true], null, false));
        truthy(!in_array('cursor', $hiddenNames($forms2[0]), true),
            '首页不该塞空 cursor hidden');
    });

check('Blocks：非法游标是 422 显式状态，不是「列表读取失败」', function (): void {
    $state = ['phase' => 'invalid', 'title' => '回收站翻页参数不合法', 'message' => '非法游标'];
    $blocks = ContentAdminBlocks::build('trash', [], '', [], $state, true);
    $notice = (array) $blocks[0];
    truthy((string) ($notice['state'] ?? '') === 'invalid', '降级态没标成 invalid');
    truthy(str_contains((string) ($notice['title'] ?? ''), '翻页参数不合法'), '标题误报成服务故障');
    truthy(!str_contains((string) ($notice['value'] ?? ''), '内容列表读取失败'), '文案还在说列表读取失败');
    $ids = array_map(static fn(array $b): string => (string) ($b['id'] ?? ''), $blocks);
    truthy(in_array('content-pager-escape', $ids, true), '降级态没有脱困链接');
});

check('Blocks：本页被清光时说「这一页空了」，不谎称回收站是空的', function (): void {
    $data = ['items' => [], 'total' => 45, 'shown' => 0, 'truncated' => true,
             'next_cursor' => '', 'cursor' => 'NDA='];
    $blocks = ContentAdminBlocks::build('trash', $data, 'ticket', [], null, false);
    $notice = (array) $blocks[0];
    truthy((string) ($notice['state'] ?? '') === 'page-empty', '空页没有独立状态');
    truthy(!str_contains((string) ($notice['title'] ?? ''), '回收站是空的'), '把空页说成了空回收站');
    truthy(str_contains((string) ($notice['value'] ?? ''), '仍有 45 篇'), '没给出真实总数');
});

check('设计纪律：页面不复制游标校验，链接必须编码', function () use ($root): void {
    $q = (string) file_get_contents($root . '/pages/ContentAdmin/ContentAdminQuery.php');
    truthy(!str_contains($q, 'base64_decode'), '页面自己解了游标，等于复制系统层规则');
    $p = (string) file_get_contents($root . '/pages/ContentAdmin/ContentAdminPager.php');
    truthy(str_contains($p, 'rawurlencode'), '翻页链接没有对游标做 url 编码');
});
