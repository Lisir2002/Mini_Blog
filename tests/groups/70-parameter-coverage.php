<?php
declare(strict_types=1);

use Wx\Tools\Audit\ParameterLedger;

require_once dirname(__DIR__, 2) . '/tools/audit/ParameterLedger.php';

/**
 * 70 · 参数级覆盖账本（纯函数 / 静态核对）
 *
 * 运行时 CLI 见 70b。本组直接调用同一个核对引擎，用真实数据取证，
 * 再用「篡改过的登记表」验证双向核对真的会拦下坏账 —— 只跑一遍绿灯
 * 不算守卫，把登记表改坏必须变红。v0.53 暴露的盲点是「operation 已消费、
 * 参数没入口」，这里把粒度下沉到 registry 的每一条 params[]。
 *
 * v0.56：gap 桶首次归零（置顶视图 / 图片预览 / 维度下钻三条存量缺口一次收完）。
 * 本组同时锁住反向：把任一已收口的键重新登记，必须被判成坏账；账本只剩
 * 39 条 page / cli 结论，--zero 输出「无」。
 */
echo "\n=== 70. 参数级覆盖账本 ===\n";

$registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
$ledger   = json_decode((string) file_get_contents($root . '/api/parameter-coverage.json'), true);
$matrix   = ParameterLedger::matrix($root);
$scan     = ParameterLedger::scan($root, $registry, $matrix);
$declared = (array) ($ledger['declared'] ?? []);
/** @param array<string, mixed> $fake */
$badOf = static function (array $fake) use ($root, $scan, $matrix): string {
    return implode(' | ', ParameterLedger::verify($root, $scan, ['declared' => $fake], $matrix));
};
/** 基于真实登记表只改一项，其余保持原样。 @return array<string, mixed> */
$with = static function (string $key, array $patch) use ($declared): array {
    $copy = $declared;
    $copy[$key] = array_merge((array) ($copy[$key] ?? []), $patch);
    return $copy;
};
$chars = static fn(string $s): int => preg_match_all('/./us', $s) ?: 0;

check('参数全集完整且唯一，零证据集合恰好 39 条',
    function () use ($registry, $scan, $matrix): void {
        truthy(count($scan['params']) === 187, '参数总数不是 187，实际 ' . count($scan['params']));
        truthy(count($scan['by_key']) === count($scan['params']), '出现了重复的 op.param 键');
        $ops = array_flip(array_map(static fn(array $e): string => (string) ($e['operation'] ?? ''),
            (array) ($registry['endpoints'] ?? [])));
        foreach ($scan['by_key'] as $key => $row) {
            truthy(isset($ops[$row['op']]), "{$key} 的 operation 不在契约里");
            truthy(str_starts_with((string) $key, $row['op'] . '.'), "{$key} 键拼错");
        }
        truthy(count($scan['zero']) === 39, '零证据参数不是 39，实际 ' . count($scan['zero']));
        truthy($matrix['total'] === 92, 'operation 级矩阵被改动，实际 ' . (string) $matrix['total']);
    });

check('v0.53 与 v0.56 的六个写入口证据都在，职责拆分盲区如实为零',
    function () use ($scan): void {
        $hitsOf = static fn(string $key): array => (array) ($scan['by_key'][$key]['hits'] ?? []);
        foreach (['content.update.pinned', 'content.update.weight', 'content.transition.publish_at'] as $key) {
            truthy(in_array('pages/ContentAdmin/ContentAdminPlacement.php', $hitsOf($key), true),
                $key . ' 的页面证据丢了：v0.53 的写入口被回退');
        }
        truthy(in_array('pages/Write/WriteAction.php', $hitsOf('content.update.slug'), true),
            '写作页对 content.update.slug 的证据应保留');
        truthy(in_array('pages/ContentAdmin/ContentAdminQuery.php', $hitsOf('content.trash.cursor'), true),
            '回收站深翻页落地后，cursor 必须在查询层留下证据');
        // v0.56 收掉的三条 gap：证据必须真的落在页面消费方文件里，撤登才站得住。
        foreach ([
            'content.posts.pinned' => 'pages/ContentAdmin/ContentAdminQuery.php',
            'attachments.download.disposition' => 'pages/Post/PostAttachments.php',
            'stats.series.dimension' => 'pages/Stats/StatsQuery.php',
        ] as $key => $file) {
            truthy(in_array($file, $hitsOf($key), true),
                $key . ' 的页面证据丢了：v0.56 收口的入口被回退');
        }
        truthy($hitsOf('member.xp.award.event_key') === [],
            '成员页把入参组装委托给协作者，调用方文件里本该扫不到');
    });

check('真实账本核对干净，登记表恰好覆盖零证据集合',
    function () use ($root, $scan, $ledger, $matrix, $declared): void {
        $bad = ParameterLedger::verify($root, $scan, $ledger, $matrix);
        truthy($bad === [], '真实账本有坏账：' . implode(' | ', $bad));
        $missing = array_values(array_diff($scan['zero'], array_keys($declared)));
        truthy($missing === [], '未登记参数：' . implode(', ', $missing));
        $extra = array_values(array_diff(array_keys($declared), $scan['zero']));
        truthy($extra === [], '登记了已有证据的参数：' . implode(', ', $extra));
        truthy(count($declared) === 39, '登记表不是 39 条，实际 ' . count($declared));
    });

check('三类桶计数与 gap 明细逐条可核对',
    function () use ($declared, $scan, $matrix, $chars): void {
        $count = ['page' => 0, 'cli' => 0, 'gap' => 0];
        foreach ($declared as $entry) {
            $state = (string) (is_array($entry) ? ($entry['state'] ?? '') : '');
            if (isset($count[$state])) $count[$state]++;
        }
        truthy($count === ['page' => 17, 'cli' => 22, 'gap' => 0],
            '三类桶计数不符：' . json_encode($count, JSON_UNESCAPED_UNICODE));
        $gaps = array_values(array_filter(array_keys($declared),
            static fn(string $k): bool => (string) ($declared[$k]['state'] ?? '') === 'gap'));
        truthy($gaps === [], 'gap 桶已归零却还列着待补入口：' . implode(', ', $gaps));
        $deliberate = (array) ($matrix['deliberate_zero'] ?? []);
        foreach ($gaps as $key) {
            truthy($chars((string) ($declared[$key]['note'] ?? '')) >= 20, $key . ' 的理由不足 20 字');
            truthy(!isset($deliberate[(string) $scan['by_key'][$key]['op']]),
                $key . ' 的 operation 属于刻意零消费，不该算待补入口');
        }
    });

check('双向核对：漏登记一条参数会被拦下',
    function () use ($declared, $badOf): void {
        $fake = $declared;
        unset($fake['member.xp.award.event_key']);
        $bad = $badOf($fake);
        truthy(str_contains($bad, '未登记：member.xp.award.event_key'), '删掉登记没被拦下：' . $bad);
    });

check('双向核对：登记不存在的参数 / 已有证据的参数会被拦下',
    function () use ($declared, $badOf): void {
        $fake = $declared;
        $fake['content.update.nope'] = ['state' => 'gap', 'note' => '凭空多出来的契约参数必须被拦下。'];
        $bad = $badOf($fake);
        truthy(str_contains($bad, '登记了不存在的参数：content.update.nope'),
            '拼错的键没被拦下：' . $bad);
        $fake = $declared;
        $fake['content.update.slug'] = ['state' => 'gap', 'note' => '已有页面证据却被登记成缺口。'];
        $bad = $badOf($fake);
        truthy(str_contains($bad, '该参数已有页面证据，不该登记：content.update.slug'),
            '有证据的参数被登记没被拦下：' . $bad);
        // v0.55 / v0.56 的反向锁：四条已收口的键放回去都必须是坏账。
        foreach (['content.trash.cursor', 'content.posts.pinned',
            'attachments.download.disposition', 'stats.series.dimension'] as $key) {
            $fake = $declared;
            $fake[$key] = ['state' => 'gap',
                'note' => '入口已经落地，这条登记本该撤掉却留着，必须被判成坏账。'];
            $bad = $badOf($fake);
            truthy(str_contains($bad, '该参数已有页面证据，不该登记：' . $key),
                $key . ' 已收口却又被登记，没被拦下：' . $bad);
        }
    });

check('state=page 的 file / anchor / 同目录范围必须可核对',
    function () use ($declared, $badOf, $with): void {
        $key = 'member.xp.award.event_key';
        $bad = $badOf($with($key, ['anchor' => "'never-appears-in-input'"]));
        truthy(str_contains($bad, '证据锚点在文件里找不到'), '伪造锚点没被拦下：' . $bad);
        $bad = $badOf($with($key, ['anchor' => "\$body['event_key']",
            'file' => 'pages/Post/PostPage.php']));
        truthy(str_contains($bad, '证据文件不在该接口的页面协作者目录内'),
            '拿别页文件凑数没被拦下：' . $bad);
        $bad = $badOf($with($key, ['anchor' => "\$body['event_key']",
            'file' => 'pages/Member/not-exist.php']));
        truthy(str_contains($bad, '证据文件不存在'), '不存在的证据文件没被拦下：' . $bad);
    });

check('理由长度、state 取值、cli / gap 与刻意零消费口径一致',
    function () use ($declared, $badOf, $with): void {
        $bad = $badOf($with('member.title.color', ['note' => '太短']));
        truthy(str_contains($bad, '理由至少 20 字'), '理由过短没被拦下：' . $bad);
        $bad = $badOf($with('member.title.color', ['state' => 'nope']));
        truthy(str_contains($bad, 'state 只能是 page / cli / gap'), '非法 state 没被拦下：' . $bad);
        $bad = $badOf($with('member.title.color', ['state' => 'cli']));
        truthy(str_contains($bad, 'state=cli 的 operation 必须列入刻意零消费'),
            '把页面协作者伪装成 cli 没被拦下：' . $bad);
        $bad = $badOf($with('lifecycle.install.type', ['state' => 'gap']));
        truthy(str_contains($bad, '刻意零消费的 operation 不该登记为待补入口'),
            '把刻意零消费伪装成待补入口没被拦下：' . $bad);
    });
