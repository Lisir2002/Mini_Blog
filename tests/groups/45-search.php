<?php
declare(strict_types=1);

use Wx\Pages\Search\SearchBlocks;

/**
 * 45 · 站内搜索（纯逻辑）：四态必须可区分，关键词按字面量匹配
 *
 * 这一组不碰数据库，只查三件事：
 *  1. 四种状态（未输入 / 无结果 / 有结果 / 不可用）在区块里长得不一样；
 *  2. 结果条目是「标题 + 链接 + 摘要」，不是一串裸字符串；
 *  3. 系统层确实给 LIKE 做了转义 —— 否则搜一个 % 会返回全站。
 */
echo "\n=== 45. 站内搜索：四态与转义 ===\n";

check('未输入：idle 态，给引导而不是「没有找到」', function (): void {
    $blocks = SearchBlocks::build('', null, null);
    $notice = (array) ($blocks[1] ?? []);
    truthy(($notice['state'] ?? '') === 'idle', '未输入时状态不是 idle');
    $text = (string) ($notice['title'] ?? '') . (string) ($notice['value'] ?? '');
    truthy(str_contains($text, '开始搜索'), '没有给出搜索引导');
    truthy(!str_contains((string) ($notice['title'] ?? ''), '没有'), '未输入被说成了「没有匹配」');
});

check('无结果：empty 态，明确「没有匹配」并给出别的出口', function (): void {
    $blocks = SearchBlocks::build('zzzz', ['items' => [], 'total' => 0, 'shown' => 0, 'truncated' => false], null);
    $notice = (array) ($blocks[1] ?? []);
    truthy(($notice['state'] ?? '') === 'empty', '无结果时状态不是 empty');
    truthy(str_contains((string) ($notice['title'] ?? ''), '没有匹配'), '没有明确说明没有匹配');
    $alt = (array) ($blocks[2] ?? []);
    truthy(($alt['type'] ?? '') === 'links', '没有给出归档 / 标签出口');
});

check('有结果：ok 态写明总数；被截断时明确「未列全」', function (): void {
    $result = ['total' => 42, 'shown' => 2, 'truncated' => true, 'items' => [
        ['slug' => 'a', 'title' => '甲', 'excerpt' => '摘要甲', 'body' => ''],
        ['slug' => 'b', 'title' => '乙', 'excerpt' => '', 'body' => '正文乙'],
    ]];
    $blocks = SearchBlocks::build('甲', $result, null);
    $notice = (array) ($blocks[1] ?? []);
    truthy(($notice['state'] ?? '') === 'ok', '有结果时状态不是 ok');
    truthy(str_contains((string) $notice['value'], '共 42 篇'), '没有写明总数');
    truthy(str_contains((string) $notice['value'], '未列全'), '截断时没有声明未列全');

    $list = (array) ($blocks[2] ?? []);
    $items = (array) ($list['items'] ?? []);
    truthy(count($items) === 2, '结果条目数不对');
    truthy(($items[0]['href'] ?? '') === '/post?slug=a', '条目没有指向文章页：' . json_encode($items[0]));
    truthy(str_contains((string) ($items[1]['note'] ?? ''), '正文乙'), '没有摘要时未从正文截取');
});

check('不可用：failed 态，绝不渲染成「没有结果」', function (): void {
    $blocks = SearchBlocks::build('甲', null, ['title' => '搜索失败', 'message' => '库不可达']);
    $notice = (array) ($blocks[1] ?? []);
    truthy(($notice['state'] ?? '') === 'unavailable', '不可用时状态不是 unavailable');
    truthy(str_contains((string) $notice['value'], '库不可达'), '没有带上失败原因');
    truthy(!isset($blocks[2]), '失败页不该再有结果列表');
});

check('搜索表单是 GET：可分享、无脚本可用', function (): void {
    $form = (array) (SearchBlocks::build('', null, null)[0] ?? []);
    truthy(($form['type'] ?? '') === 'form' && ($form['method'] ?? '') === 'get', '表单不是 GET');
    truthy(($form['action'] ?? '') === '/search', '表单没有指向搜索页');
    $names = array_map(static fn(array $f): string => (string) ($f['name'] ?? ''), (array) $form['fields']);
    truthy(in_array('q', $names, true), '表单没有 q 字段');
});

check('系统层给 LIKE 转义：% 与 _ 按普通字符处理', function () use ($root): void {
    $src = (string) file_get_contents($root . '/systems/Content/PostQuery.php');
    truthy(str_contains($src, "ESCAPE"), 'LIKE 没有显式 ESCAPE，依赖服务端 sql_mode');
    truthy(str_contains($src, "strtr("), '关键词没有做转义替换');
    foreach (["'%' =>", "'_' =>"] as $needle) {
        truthy(str_contains($src, $needle), '缺少通配符转义：' . $needle);
    }
});

check('页面只产数据：搜索页不写 HTML / SQL', function () use ($root): void {
    foreach (['SearchPage.php', 'SearchBlocks.php', 'SearchQuery.php'] as $file) {
        $src = (string) file_get_contents($root . '/pages/Search/' . $file);
        foreach (['<?=', '<div', 'SELECT ', '->pdo('] as $needle) {
            truthy(!str_contains($src, $needle), $file . ' 出现了呈现 / 持久化痕迹：' . $needle);
        }
    }
});
