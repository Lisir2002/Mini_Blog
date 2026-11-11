<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Pages\Archive\ArchiveFilter;
use Wx\Pages\Archive\ArchivePager;
use Wx\Pages\Archive\ArchiveQuery;

/**
 * 41 · 归档筛选与分页：纯逻辑穷举。
 *
 * 与 41b 的分工：这里把「参数边界 / 过滤口径 / 分页边界 / 链接保真」逐值穷举，
 * 不需要数据库；41b 只验「接上真实内核与主题之后」参数能不能传通、口径会不会混。
 * 筛选的跨年跨月组合放在这里，是因为造历史数据要改 created_at，
 * 而那是审计字段、接口不开放 —— 纯逻辑能覆盖得更彻底。
 */
echo "\n=== 41. 归档筛选与分页：参数 / 口径 / 边界 ===\n";

check('筛选参数：合法值原样通过，非法值一律拒绝而不是悄悄夹紧或回落默认', function (): void {
    $ok = ArchiveFilter::parse(['year' => '2026', 'month' => '3', 'page' => '2', 'per_page' => '10']);
    truthy($ok === ['year' => 2026, 'month' => 3, 'page' => 2, 'per_page' => 10],
        '合法参数被改写：' . json_encode($ok));

    $d = ArchiveFilter::parse([]);
    truthy($d['year'] === null && $d['month'] === null, '缺省不该补出一个年份');
    truthy($d['page'] === 1 && $d['per_page'] === ArchiveFilter::PER_PAGE_DEFAULT, '缺省页码 / 每页数不符');

    // 只给 month 表示「每年这个月」，year 必须保持 null，不能被补成今年。
    $m = ArchiveFilter::parse(['month' => '12']);
    truthy($m['year'] === null && $m['month'] === 12, '单独 month 被补了默认年');

    $bad = [
        'year'     => ['abc', '26', '0206', '1899', '2101', '2026-1', '-2026'],
        'month'    => ['0', '13', 'x', '1.5'],
        'page'     => ['0', '-1', 'abc', '10001'],
        'per_page' => ['0', '51', 'abc'],
    ];
    foreach ($bad as $field => $values) {
        foreach ($values as $v) {
            $thrown = false;
            try {
                ArchiveFilter::parse([$field => $v]);
            } catch (InvalidArgumentException) {
                $thrown = true;
            }
            // 「page=abc 当成第 1 页」会让读者以为筛选生效，实际请求已被悄悄改写。
            truthy($thrown, $field . '=' . $v . ' 应被拒绝，不能夹紧或回落默认');
        }
    }
});

check('时间筛选：只留命中项；脏日期不参与筛选，也不伪装成命中', function (): void {
    $items = [
        ['title' => '甲', 'created_at' => '2026-11-01 10:00:00'],
        ['title' => '乙', 'created_at' => '2026-03-15 10:00:00'],
        ['title' => '丙', 'created_at' => '2025-07-01 10:00:00'],
        ['title' => '丁', 'created_at' => ''],
        ['title' => '戊', 'created_at' => 'bad-date'],
    ];
    truthy(count(ArchiveFilter::filter($items, null, null)) === 5, '无筛选应原样返回，含脏日期');

    $y = ArchiveFilter::filter($items, 2026, null);
    truthy(count($y) === 2 && $y[0]['title'] === '甲', '按年筛选不符');

    $m = ArchiveFilter::filter($items, null, 7);
    truthy(count($m) === 1 && $m[0]['title'] === '丙', '按月跨年筛选不符');

    truthy(count(ArchiveFilter::filter($items, 2025, 7)) === 1, '年月联合筛选不符');
    truthy(ArchiveFilter::filter($items, 2024, null) === [], '不存在的年份应返回空，而不是全部');

    truthy(ArchiveFilter::active(['year' => 2026, 'month' => null, 'page' => 1, 'per_page' => 20]),
        'year 有值应视为筛选激活');
    truthy(!ArchiveFilter::active(['year' => null, 'month' => null, 'page' => 1, 'per_page' => 20]),
        '无筛选不该标记为激活');
});

check('分页边界：页数向上取整、越界显式、空集合也有 1 页', function (): void {
    $items = array_fill(0, 5, ['title' => 'x']);

    $p1 = ArchiveFilter::paginate($items, 1, 2);
    truthy(count($p1['items']) === 2 && $p1['pages'] === 3 && $p1['total'] === 5, '首页分页不符');
    truthy($p1['has_prev'] === false && $p1['has_next'] === true, '首页 prev/next 不符');

    $p3 = ArchiveFilter::paginate($items, 3, 2);
    truthy(count($p3['items']) === 1 && $p3['has_next'] === false && $p3['beyond'] === false, '末页分页不符');

    // 越界是合法状态，但必须与「确实没有文章」区分开。
    $p9 = ArchiveFilter::paginate($items, 9, 2);
    truthy($p9['items'] === [] && $p9['beyond'] === true, '越界页必须显式 beyond');

    $big = ArchiveFilter::paginate($items, 1, 20);
    truthy($big['pages'] === 1 && !$big['has_next'], 'per_page 大于总数应只有一页');

    $none = ArchiveFilter::paginate([], 1, 20);
    truthy($none['pages'] === 1 && $none['total'] === 0, '空集合页数应为 1，否则会渲染「第 1 / 0 页」');
});

check('翻页链接保真：筛选与术语条件不能在翻页时丢失', function (): void {
    $f = ArchiveFilter::parse(['year' => '2025', 'month' => '7', 'per_page' => '5']);
    $h = ArchiveFilter::href($f, 2);
    foreach (['page=2', 'year=2025', 'month=7', 'per_page=5'] as $needle) {
        truthy(str_contains($h, $needle), '归档链接丢了 ' . $needle . '：' . $h);
    }

    $t = ArchiveFilter::href($f, 3, '/term', ['kind' => 'tag', 'slug' => 'php']);
    truthy(str_starts_with($t, '/term?'), '术语链接基址不对：' . $t);
    foreach (['page=3', 'kind=tag', 'slug=php', 'year=2025'] as $needle) {
        truthy(str_contains($t, $needle), '术语链接丢了 ' . $needle . '：' . $t);
    }

    $plain = ArchiveFilter::href(ArchiveFilter::parse([]), 1);
    truthy($plain === '/archive?page=1', '默认参数不该出现在链接里：' . $plain);
});

check('口径：site_total 是全站数，total 是命中数；采样截断时筛选必须声明不完整', function (): void {
    /** @param list<array<string, mixed>> $items */
    $fake = static function (array $items, int $total, ?string $cursor): ApiRegistryInterface {
        return new class($items, $total, $cursor) implements ApiRegistryInterface {
            public function __construct(private array $items, private int $total, private ?string $cursor) {}
            public function endpoints(): array { return []; }
            public function find(string $id): ?array { return null; }
            public function match(string $method, string $path): ?array { return null; }
            public function registerOperation(string $operation, callable $handler): void {}
            public function execute(string $operation, array $input): mixed
            {
                return ['items' => $this->items, 'total' => $this->total, 'next_cursor' => $this->cursor];
            }
        };
    };

    $rows = [];
    foreach (range(1, 20) as $i) $rows[] = ['title' => 'a' . $i, 'slug' => 'a' . $i, 'created_at' => '2026-11-01 00:00:00'];
    foreach (range(1, 10) as $i) $rows[] = ['title' => 'b' . $i, 'slug' => 'b' . $i, 'created_at' => '2025-07-01 00:00:00'];

    // 完整读取：30 篇里 2025 年命中 10 篇，全站仍是 30。
    $full = (new ArchivePager(new ArchiveQuery($fake($rows, 30, null))))
        ->fetch(['year' => '2025'], '', '', '/archive');
    truthy($full['total'] === 10 && $full['site_total'] === 30, '命中数与全站数混用了');
    truthy($full['filter_incomplete'] === false, '完整读取不该声明筛选不完整');
    truthy($full['sampled_truncated'] === false, '完整读取不该声明截断');

    // 采样撞上上限：只读到窗口内的一部分，筛选必须声明「可能不完整」。
    $loop = $fake(
        [['title' => 'c', 'slug' => 'c', 'created_at' => '2026-11-01 00:00:00']],
        9999,
        'always'
    );
    $cut = (new ArchivePager(new ArchiveQuery($loop)))->fetch(['year' => '2026'], '', '', '/archive');
    truthy($cut['sampled_truncated'] === true, '上游截断没有被透传');
    truthy($cut['filter_incomplete'] === true, '采样截断 + 筛选激活时，必须声明筛选可能不完整');
    truthy($cut['site_total'] === 9999, '采样截断后全站总数仍应是真值');

    $plain = (new ArchivePager(new ArchiveQuery($fake($rows, 30, null))))
        ->fetch([], '', '', '/archive');
    truthy(count($plain['items']) === 20 && $plain['total'] === 30, '每页 20 篇：本页 20 条，命中口径仍是 30');
    truthy($plain['pages'] === 2, '30 篇按每页 20 应是 2 页');

    // 越界页必须给出口：prev_href 减一会停在 page-1（仍然越界），应退回最后一页。
    $edge = (new ArchivePager(new ArchiveQuery($fake($rows, 30, null))))
        ->fetch(['page' => '9'], '', '', '/archive');
    truthy($edge['beyond'] === true, '越界页没有 beyond 标记');
    truthy(str_contains($edge['prev_href'], 'page=2'),
        '越界时「上一页」应回到最后一页（2），实际 ' . $edge['prev_href']);
    truthy($edge['next_href'] === '', '越界页不该有下一页');
});

check('框架载荷：分页字段由 ArchivePager 统一写回，页面不各拼一套', function (): void {
    $payload = ['total' => 0, 'sections' => []];
    ArchivePager::apply($payload, [
        'items' => [['title' => 'x'], ['title' => 'y']],
        'filter' => ['year' => 2026, 'month' => null, 'active' => true],
        'page_no' => 2, 'pages' => 5, 'per_page' => 2,
        'has_prev' => true, 'has_next' => true, 'beyond' => false,
        'prev_href' => '/archive?page=1', 'next_href' => '/archive?page=3',
        'total' => 10, 'site_total' => 99, 'sampled_truncated' => false,
        'filter_incomplete' => false,
    ], '/archive');

    truthy($payload['total'] === 10 && $payload['site_total'] === 99, '命中数与全站数写反了');
    truthy($payload['shown'] === 2, 'shown 应等于本页条数');
    truthy($payload['page_no'] === 2 && $payload['pages'] === 5, '分页字段未写回');
    truthy($payload['filter_action'] === '/archive' && $payload['filter']['active'] === true, '筛选字段未写回');
    truthy($payload['empty'] === false, '有内容却被标成空');
});

check('两款元主题归档模板逐字同源，且筛选与分页标记成对出现', function () use ($root): void {
    // 筛选与分页已拆成 partial（单文件会顶到 150 行预算），同源与标记要跨三份一起验。
    $files = [
        'templates/archive.php',
        'templates/partials/archive-filter.php',
        'templates/partials/archive-pager.php',
    ];
    $joined = '';
    foreach ($files as $f) {
        $a = (string) file_get_contents($root . '/themes/GlassFront/' . $f);
        $b = (string) file_get_contents($root . '/themes/Glass/' . $f);
        truthy($a === $b, $f . ' 两款主题不是逐字同源');
        $joined .= $a;
    }

    foreach (['archive-filter', 'data-pager', 'data-page', 'data-pages', 'data-beyond',
              'data-filter-incomplete', 'rel="prev"', 'rel="next"', 'method="get"'] as $needle) {
        truthy(str_contains($joined, $needle), '归档模板缺少筛选 / 分页标记：' . $needle);
    }
    // 筛选表单必须回填当前值，否则读者改一个条件就把另一个条件丢了。
    truthy(str_contains($joined, 'selected'), '月份下拉没有回填当前筛选值');
    truthy(str_contains($joined, 'hidden'), '术语页的筛选表单没有保留 kind / slug');
    // 拆分必须是真拆分：archive.php 自己要 include 两个 partial，不能把代码抄两份。
    $main = (string) file_get_contents($root . '/themes/GlassFront/templates/archive.php');
    truthy(str_contains($main, "partials/archive-filter.php") && str_contains($main, "partials/archive-pager.php"),
        'archive.php 没有引入拆分后的 partial');
});

check('两款渲染器都收敛了分页变量，模板不直接翻 payload', function () use ($root): void {
    foreach (['GlassFront', 'Glass'] as $dir) {
        $src = (string) file_get_contents($root . '/themes/' . $dir . '/' . $dir . 'Renderer.php');
        foreach (['$pageNo', '$pages', '$perPage', '$prevHref', '$nextHref',
                  '$filterAction', '$siteTotal', '$beyond', '$filterIncomplete'] as $var) {
            truthy(str_contains($src, $var), $dir . ' 渲染器未提取 ' . $var);
        }
    }
});
