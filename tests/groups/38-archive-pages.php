<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Pages\Archive\ArchiveQuery;
use Wx\Pages\Archive\ArchiveState;
use Wx\Systems\Api\ApiOperationException;

echo "\n=== 38. 归档页：纯逻辑 / 截断 / 状态 / 承载边界 ===\n";

/** 假的接口清单：让「翻页 / 截断 / 死循环保护」不依赖数据库也能穷举。 */
$fakeRegistry = static function (array $pages): ApiRegistryInterface {
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

/** @return list<array<string, mixed>> */
$rows = static fn(int $n, int $from): array => array_values(array_map(
    static fn(int $i): array => [
        'slug' => 'p' . $i, 'title' => '文章' . $i,
        'created_at' => '2026-11-11 10:00:00', 'terms' => [],
    ],
    range($from, $from + $n - 1)
));

check('月份分组：正常 / 单位数月 / 脏日期都落在可解释的桶里', function () use ($rows): void {
    truthy(ArchiveQuery::monthLabel('2026-11-11 10:00:00') === '2026 年 11 月', '年月格式不符');
    truthy(ArchiveQuery::monthLabel('2026-01-02') === '2026 年 1 月', '月份不该补零后又被当成十进制');
    truthy(ArchiveQuery::monthLabel('') === '日期未知', '空日期不能变成 1970 年');
    truthy(ArchiveQuery::monthLabel('not-a-date') === '日期未知', '脏日期不能变成 1970 年');

    $g = ArchiveQuery::groupByMonth([
        $rows(1, 1)[0],
        ['slug' => 'b', 'title' => '乙', 'created_at' => '2026-10-01 08:00:00', 'terms' => []],
        $rows(1, 3)[0],
        ['slug' => 'd', 'title' => '丁', 'created_at' => '', 'terms' => []],
    ]);
    truthy(count($g) === 3, '应得到三个分组');
    truthy($g[0]['label'] === '2026 年 11 月' && $g[0]['count'] === 2, '同月文章未合并');
    truthy($g[1]['label'] === '2026 年 10 月' && $g[1]['count'] === 1, '跨月分组不符');
    truthy($g[2]['label'] === '日期未知' && $g[2]['count'] === 1, '脏日期未独立成桶');
    truthy($g[0]['items'][0]['href'] === '/post?slug=p1', '文章链接未按 slug 生成');
});

check('文章条目：空头字段显式、非法术语不混进链接、脏日期不伪装', function (): void {
    $a = ArchiveQuery::article([
        'title' => '', 'slug' => '', 'created_at' => 'bad-date',
        'terms' => [
            ['kind' => 'tag', 'slug' => 'php', 'name' => 'PHP'],
            ['kind' => 'weird', 'slug' => 'x', 'name' => 'X'],
            ['kind' => 'tag', 'slug' => '', 'name' => '空'],
        ],
    ]);
    truthy($a['title'] === '(无标题)', '空标题不能变成空字符串');
    truthy($a['href'] === '', '没有 slug 不该编造一个可点的链接');
    truthy($a['date'] === 'bad-date', '脏日期原样保留，不能伪装成今天');
    truthy(count($a['terms']) === 1, '非法 kind / 空 slug 的术语不该成为链接');
    truthy(str_contains($a['terms'][0]['href'], 'kind=tag') && str_contains($a['terms'][0]['href'], 'slug=php'),
        '术语链接缺少 kind / slug');

    $t = ArchiveQuery::termRef(['kind' => 'category', 'slug' => 'notes', 'name' => '随笔', 'post_count' => 7]);
    truthy($t['count'] === 7 && $t['name'] === '随笔', '术语计数或名称丢失');
    truthy(str_contains($t['href'], 'kind=category') && str_contains($t['href'], 'slug=notes'), '术语链接不完整');
});

check('翻页拼接：total 与 items 同口径，term / kind / cursor 逐页透传', function () use ($fakeRegistry, $rows): void {
    $fake = $fakeRegistry([
        ['items' => $rows(100, 1),   'total' => 250, 'next_cursor' => 'c1'],
        ['items' => $rows(100, 101), 'total' => 250, 'next_cursor' => 'c2'],
        ['items' => $rows(50, 201),  'total' => 250, 'next_cursor' => null],
    ]);
    $r = (new ArchiveQuery($fake))->posts('php', 'tag');
    truthy(count($r['items']) === 250, '三页未拼齐');
    truthy($r['total'] === 250, 'total 丢了或改了口径');
    truthy($r['truncated'] === false, '正常读完不该声明截断');
    truthy(count($fake->calls) === 3, '游标没有推进到第三页');
    truthy(($fake->calls[0]['term'] ?? '') === 'php' && ($fake->calls[0]['kind'] ?? '') === 'tag',
        '筛选条件没有透传给列表接口');
    truthy(($fake->calls[1]['cursor'] ?? '') === 'c1', '第二页没带上一页的游标');
});

check('截断必须声明：撞上篇数上限时停住，且不会无限翻页', function () use ($fakeRegistry, $rows): void {
    $fake = $fakeRegistry(array_fill(0, 20, [
        'items' => $rows(100, 1), 'total' => 9999, 'next_cursor' => 'always',
    ]));
    $r = (new ArchiveQuery($fake))->posts();
    truthy(count($r['items']) === ArchiveQuery::MAX_POSTS, '没有在篇数上限处停住');
    truthy($r['truncated'] === true, '达到上限却没声明截断');
    truthy($r['total'] === 9999, '截断后 total 仍应是真实总数');
    truthy(count($fake->calls) <= ArchiveQuery::MAX_PAGES, '超出页数上限，存在死循环风险');
    truthy(count($fake->calls) >= 5, '第一页就假截断，等于没翻页');

    // 接口一直返回空页却不推进游标：显式声明异常，而不是安静地「读完了」。
    $empty = $fakeRegistry(array_fill(0, 20, ['items' => [], 'total' => 0, 'next_cursor' => 'stuck']));
    $r2 = (new ArchiveQuery($empty))->posts();
    truthy(count($empty->calls) === ArchiveQuery::MAX_PAGES, '空页循环没有页数兜底');
    truthy($r2['truncated'] === true, '游标未推进却没声明，会被误读成「确实没有文章」');
});

check('失败态：内存降级与偶发故障分开说，内部异常详情不进读者文案', function (): void {
    $degrade = ArchiveState::fromThrowable('terms', new ApiOperationException('内部细节', 50101));
    truthy($degrade['status'] === 503, '功能不可用不该返回 200 假装成功');
    truthy(str_contains((string) $degrade['payload']['state']['message'], '内存降级'), '未说清是部署形态');
    truthy($degrade['payload']['view'] === 'terms', '失败态丢了视图信息');

    $boom = ArchiveState::fromThrowable('archive', new RuntimeException('/secret/path 爆了'));
    truthy($boom['status'] === 503, '读取失败不该返回 200');
    truthy(!str_contains((string) $boom['payload']['state']['message'], '/secret/path'),
        '内部异常详情泄露到读者可见文案');
    truthy(($boom['payload']['state']['phase'] ?? '') === 'failed', '失败态相位不对');
    truthy($boom['payload']['state']['actions'] !== [], '失败态没有给可恢复的出口');

    $termState = ArchiveState::termsState(new ApiOperationException('x', 50101));
    truthy(($termState['phase'] ?? '') === 'failed' && str_contains((string) $termState['hint'], '仍然可读'),
        '旁路失败不该让整篇归档一起消失');
});

check('独立页清单：三条路由 / archive 布局 / 公开访问，源码零呈现零 SQL', function () use ($root): void {
    $meta = json_decode((string) file_get_contents($root . '/pages/Archive/page.json'), true);
    truthy(is_array($meta), 'page.json 不可解析');
    truthy(($meta['layout'] ?? null) === 'archive', '布局声明不符');
    truthy(($meta['routes'] ?? []) === ['/archive', '/terms', '/term'], '路由声明不符');
    truthy(($meta['access']['auth'] ?? '') === 'public', '归档不该要求登录');
    foreach (glob($root . '/pages/Archive/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        foreach (['<html', '<div', 'class=', '<style', '<script'] as $needle) {
            truthy(!str_contains($src, $needle), basename($f) . ' 里出现呈现痕迹：' . $needle);
        }
        truthy(!str_contains($src, 'SELECT ') && !str_contains($src, 'INSERT '),
            basename($f) . ' 里出现 SQL，独立页不该认识数据库');
    }
    truthy((glob($root . '/pages/*/assets/*') ?: []) === [], '独立页出现 assets 资源');
    truthy((glob($root . '/pages/*/templates/*.php') ?: []) === [], '独立页出现 HTML 模板');
});

check('主题契约：两款元主题成对提供 archive 槽位，Bare 系刻意不提供', function () use ($root): void {
    $css = [];
    foreach (['Glass', 'GlassFront'] as $dir) {
        $m = json_decode((string) file_get_contents($root . '/themes/' . $dir . '/theme.json'), true);
        truthy(in_array('archive', (array) ($m['layouts'] ?? []), true), $dir . ' 未声明 archive 槽位');
        truthy(is_file($root . '/themes/' . $dir . '/templates/archive.php'), $dir . ' 缺 archive 模板');
        truthy(is_file($root . '/themes/' . $dir . '/assets/archive.css'), $dir . ' 缺 archive 样式');
        $css[$dir] = (string) file_get_contents($root . '/themes/' . $dir . '/assets/archive.css');
        $renderer = (string) file_get_contents($root . '/themes/' . $dir . '/' . $dir . 'Renderer.php');
        foreach (['$sections', '$termsState', '$truncated', '$links'] as $var) {
            truthy(str_contains($renderer, $var), $dir . ' 渲染器未提取 ' . $var);
        }
    }
    truthy($css['Glass'] !== '' && $css['Glass'] === $css['GlassFront'], '两款元主题 archive.css 不是逐字同源');
    foreach (['Bare', 'BareHtml'] as $dir) {
        $m = json_decode((string) file_get_contents($root . '/themes/' . $dir . '/theme.json'), true);
        truthy(!in_array('archive', (array) ($m['layouts'] ?? []), true),
            $dir . ' 不该有 archive 槽位：退化必须是显式的、可被守卫断言的');
    }
});
