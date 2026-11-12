<?php
declare(strict_types=1);

/**
 * 68. UTF-8 文本原语守卫 —— 同一输入在任何部署下行为一致。
 *
 * 此前仓库里有 10 处「mb 探测 + 字节退化」的双路实现：mbstring 装没装决定
 * 同一个输入按字符还是按字节处理，字节截断还会把多字节字符从中间切开，
 * 产出非法 UTF-8（RSS 摘要末尾出现「�」、显示名截断后 json_encode 返回
 * false 让整个响应变空白）。v0.52 用 systems/Data/Utf8Text.php 单一实现
 * （PCRE，PHP 内置，零扩展依赖）收口全部调用点，这一组锁住两件事：
 *  1. Utf8Text 的行为契约（含非法输入的显式降级方向）；
 *  2. 双路实现不许回流 —— 全仓不再出现「探测扩展再换实现」的文本处理。
 */

use Wx\Pages\Feed\FeedSerialize;
use Wx\Systems\Data\Utf8Text;

echo "\n=== 68. UTF-8 文本原语：字符口径、非法输入显式降级、双路实现清零 ===\n";

check('len：按字符计数，8 个汉字不是 24 个字；非法 UTF-8 回退字节数（保守偏大）', function (): void {
    truthy(Utf8Text::len('中文测试') === 4, '汉字被按字节计数了');
    truthy(Utf8Text::len('中文测试') !== strlen('中文测试'), 'len 与字节数相同说明口径不对');
    truthy(Utf8Text::len('aé中🙂') === 4, '多字节组合（拉丁扩展 / Emoji）计数错误');
    truthy(Utf8Text::len('') === 0, '空串应为 0');

    // 非法 UTF-8：PCRE 失败 → 字节回退。计数偏大是保守方向，宁可误拒不可漏放。
    $bad = "中文" . "\xe8\xbf" . "abc";
    truthy(Utf8Text::len($bad) === strlen($bad), '非法输入应回退字节计数');
});

check('clip：按字符截断且结果仍是合法 UTF-8；脏数据回退字节上界但绝不假装', function (): void {
    $long = str_repeat('这是中文摘要测试文本。', 20); // 220 字符
    $cut = Utf8Text::clip($long, 200);
    truthy(Utf8Text::len($cut) === 200, '截断结果不是 200 个字符');
    truthy(Utf8Text::valid($cut), '按字符截断却产出了非法 UTF-8 —— 这是本组要消灭的缺陷');
    truthy(!str_contains($cut, "\xef\xbf\xbd"), '合法输入不该出现替换符');

    truthy(Utf8Text::clip('短文本', 200) === '短文本', '短于上限时不应截断');
    truthy(Utf8Text::clip('中文', 0) === '' && Utf8Text::clip('中文', -1) === '', '非正数上限应得空串');

    // 非法输入：字节上界兜底（字符数 × 4），非法序列保留 —— 转义层显式替换，
    // 不在这里假装修复。回退结果必须不超过上界。
    $bad = str_repeat("中文\xe8\xbf", 100); // 300 字节，非法
    $clipped = Utf8Text::clip($bad, 50);
    truthy(strlen($clipped) <= 200, '非法输入回退应受字节上界约束');
    truthy(!Utf8Text::valid($bad), '夹具本身应是非法 UTF-8（否则此断言无意义）');
});

check('valid：合法放行、非法拒绝，长度校验可以先说明拒绝原因', function (): void {
    truthy(Utf8Text::valid('中文 abc 🙂'), '合法多字节文本被误判');
    truthy(!Utf8Text::valid("ok\xe8\xbf"), '非法序列被放行');
    truthy(Utf8Text::valid(''), '空串应为合法');
});

check('单一实现：Utf8Text 不探测扩展，全仓不再出现「mb 探测 + 字节退化」双路', function () use ($root): void {
    $src = (string) file_get_contents($root . '/systems/Data/Utf8Text.php');
    truthy(!str_contains($src, 'function_exists'), '文本原语自身不应按扩展换实现 —— 那正是要消灭的缺陷');

    // 双路实现回流扫描：mb_* / iconv_* 探测与调用在 pages / systems 里必须为零。
    // Utf8Text 用 PCRE（PHP 内置），任何部署行为一致。注释行不算 —— 但代码行
    // 里的调用与探测都拦，防止未来悄悄加回「探测扩展再换实现」。
    $hits = [];
    $scan = static function (string $dir) use (&$scan, &$hits): void {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') continue;
            foreach ((array) file($f->getPathname()) as $line) {
                $code = trim((string) $line);
                if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '/*')
                    || str_starts_with($code, '//')) continue;
                $code = (string) preg_replace('/\/\/.*$/', '', $code); // 行尾注释
                if (preg_match("/function_exists\('mb_|(?<![\w\\\\@])(?:mb_|iconv_)(?:substr|strlen|split)\(/", $code)) {
                    $hits[] = basename(dirname($f->getPathname())) . '/' . $f->getBasename();
                }
            }
        }
    };
    $scan($root . '/pages');
    $scan($root . '/systems');
    truthy($hits === [], '双路实现回流: ' . implode(', ', array_unique($hits)));
});

check('端到端：超长中文摘要进 RSS 不产生非法 UTF-8，末尾无替换符', function () use ($root): void {
    require_once $root . '/pages/Feed/FeedSerialize.php';
    $body = str_repeat('这是正文里的长中文段落，用来检验摘要截断。', 30);
    $xml = FeedSerialize::rss([[
        'slug' => 'utf8-safe', 'title' => '中文标题',
        'excerpt' => '', 'body' => $body, 'created_at' => '2026-01-02 03:04:05',
    ]], 'https://example.test', '站点');
    $m = [];
    truthy(preg_match('/<description>(.*?)<\/description>/s', $xml, $m) === 1, 'RSS 缺摘要元素');
    truthy($m[1] !== '', '中文正文回退摘要时被截成了空串');
    truthy(Utf8Text::valid($m[1]), 'RSS 摘要含非法 UTF-8 —— 阅读器会解析失败或显示乱码');
    truthy(!str_contains($m[1], "\xef\xbf\xbd"), 'RSS 摘要含替换符乱码');
});
