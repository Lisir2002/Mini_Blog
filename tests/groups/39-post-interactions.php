<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Pages\Post\PostInteraction;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Comment\CommentInteractionPolicy;

/**
 * 39 · 文章页互动入口：主体 / 意图 / 失败态映射的纯逻辑穷举。
 *
 * 这一组的核心不是「表单能不能提交」，而是三件容易安静出错的事：
 *  1. 访客标识不能被截断或宽容化 —— 非法值当合法，等于把两个读者并成一个主体。
 *  2. 未知意图不能落到默认分支 —— 那会变成「点了举报，结果发了条空评论」。
 *  3. 失败不能 303 —— 303 是「成功、去看结果」的语义，用它送失败等于假成功。
 */
echo "\n=== 39. 文章页互动：主体 / 意图 / 失败态 ===\n";

/** @param array<string, mixed> $headers */
$req = static fn(array $headers = [], string $method = 'GET'): Request
    => new Request($method, '/post', [], $headers, []);

check('访客标识：表单优先、其次 Cookie，非法值一律拒绝而不是截断后当合法', function () use ($req): void {
    [$v, $fresh] = PostInteraction::visitorFrom($req(['Cookie' => 'wx_visitor=v-cookie-1']));
    truthy($v === 'v-cookie-1' && $fresh === false, 'Cookie 里的标识没有被复用');

    [$v2, $fresh2] = PostInteraction::visitorFrom($req(['Cookie' => 'wx_visitor=v-cookie-1']), 'v-form-2');
    truthy($v2 === 'v-form-2' && $fresh2 === false, '表单值应当优先于 Cookie');

    // 首次访问：生成并标记为「新」，页面据此下发 Set-Cookie。
    [$v3, $fresh3] = PostInteraction::visitorFrom($req());
    truthy(str_starts_with($v3, 'v-') && strlen($v3) > 8 && $fresh3 === true, '首次访问没有生成新标识');

    // 非法值不能被「清洗成」合法值：超长、控制字符、引号、空格都拒绝。
    foreach (['v-has space', "v-\nnewline", 'v-<script>', 'v-' . str_repeat('a', 200), '中文标识', ''] as $bad) {
        [$v4, $fresh4] = PostInteraction::visitorFrom($req(['Cookie' => 'wx_visitor=' . rawurlencode($bad)]));
        truthy($fresh4 === true && $v4 !== $bad, '非法标识「' . $bad . '」被当成了合法主体');
        truthy(PostInteraction::validVisitor($bad) === false, 'validVisitor 放过了非法值：' . $bad);
    }

    truthy(PostInteraction::validVisitor('v-abc_123.x-y') === true, '正常标识被误判为非法');
});

check('Cookie 属性：长期、HttpOnly、SameSite=Lax，脚本读不到也跨站带不上', function (): void {
    $h = PostInteraction::cookieHeader('v-abc');
    foreach (['wx_visitor=v-abc', 'Path=/', 'Max-Age=', 'HttpOnly', 'SameSite=Lax'] as $needle) {
        truthy(str_contains($h, $needle), 'Cookie 缺少属性：' . $needle);
    }
});

check('意图白名单：缺省按 parent_id 兼容旧表单，未知值明确拒绝', function (): void {
    truthy(PostInteraction::intent([]) === 'comment', '空意图应落到发表评论');
    truthy(PostInteraction::intent(['parent_id' => '3']) === 'reply', '带 parent_id 应识别为回复');
    truthy(PostInteraction::intent(['_intent' => 'like']) === 'like', '显式 like 未识别');

    $thrown = false;
    try {
        PostInteraction::intent(['_intent' => 'delete']);
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    // 未知意图落到默认分支 = 「点了举报结果发了条空评论」，必须拒绝。
    truthy($thrown, '未知意图被静默当成发表评论');
});

check('互动入参：点赞 add/remove、举报原因白名单、comment_id 必填', function (): void {
    truthy(PostInteraction::interaction('comment', []) === null, '评论意图不该走互动接口');
    truthy(PostInteraction::interaction('reply', []) === null, '回复意图不该走互动接口');

    $like = PostInteraction::interaction('like', ['comment_id' => '7']);
    truthy(($like['operation'] ?? '') === 'comments.like', '点赞映射到了错误的 operation');
    truthy(($like['input']['action'] ?? '') === 'add', '点赞默认应是 add');
    truthy(($like['input']['id'] ?? 0) === 7, '评论 id 没有传成整数');

    $remove = PostInteraction::interaction('like', ['comment_id' => '7', 'vote' => 'remove']);
    truthy(($remove['input']['action'] ?? '') === 'remove', '取消点赞没有映射成 remove');

    $report = PostInteraction::interaction('report', ['comment_id' => '7', 'reason' => 'spam']);
    truthy(($report['operation'] ?? '') === 'comments.report', '举报映射到了错误的 operation');
    truthy(($report['input']['reason'] ?? '') === 'spam', '举报原因没有透传');

    $default = PostInteraction::interaction('report', ['comment_id' => '7']);
    truthy(($default['input']['reason'] ?? '') === 'other', '缺省举报原因应为 other');

    $cases = [
        ['like', []],                                   // 缺 comment_id
        ['like', ['comment_id' => '0']],
        ['like', ['comment_id' => '7', 'vote' => 'toggle']],
        ['report', ['comment_id' => '7', 'reason' => 'bogus']],
    ];
    foreach ($cases as [$intent, $body]) {
        $thrown = false;
        try {
            PostInteraction::interaction($intent, $body);
        } catch (InvalidArgumentException) {
            $thrown = true;
        }
        truthy($thrown, $intent . ' ' . json_encode($body) . ' 应被拒绝');
    }
});

check('失败态映射：真实错误码 → HTTP 状态，绝不落到 303', function (): void {
    $cases = [
        [40912, 409], [40913, 409], [40914, 409], [42903, 429],
        [40404, 404], [40301, 403], [42201, 422], [50101, 503],
    ];
    foreach ($cases as [$code, $expect]) {
        $f = PostInteraction::failure(new ApiOperationException('x', $code));
        truthy($f['status'] === $expect, '错误码 ' . $code . ' 应映射为 ' . $expect . '，实际 ' . $f['status']);
        truthy($f['code'] === $code, '错误码在映射中丢失');
    }

    // 非业务异常不能把内部信息原样抛给读者可见文案。
    $f = PostInteraction::failure(new RuntimeException('数据库连接串泄露'));
    truthy($f['status'] === 422 && $f['code'] === 42201, '未知异常应落到 422 / 42201');
});

check('评论树：can_interact 递归补齐，主题只消费布尔不持业务规则', function (): void {
    $tree = PostInteraction::decorateTree([
        ['id' => 1, 'status' => 'published', 'children' => [
            ['id' => 2, 'status' => 'pending', 'children' => []],
            ['id' => 3, 'status' => 'deleted', 'children' => []],
        ]],
        ['id' => 4, 'status' => 'spam', 'children' => []],
    ]);

    truthy(($tree[0]['can_interact'] ?? null) === true, '已发布评论应可互动');
    truthy(($tree[0]['children'][0]['can_interact'] ?? null) === false, '待审评论不该可互动');
    truthy(($tree[0]['children'][1]['can_interact'] ?? null) === false, '已删除评论不该可互动');
    truthy(($tree[1]['can_interact'] ?? null) === false, '垃圾评论不该可互动');
    // 与系统层策略同一口径：不允许页面自造一套「哪种状态可点赞」。
    truthy(CommentInteractionPolicy::interactable(['status' => 'published']) === true,
        '系统层策略与页面口径不一致');
});

check('主体上下文：GET 与 POST 共用一份；_identity 恒 null 是 CSRF 取舍而非漏注入', function () use ($req): void {
    $s = PostInteraction::subject($req([], 'POST'), 'v-abc');
    truthy(($s['visitor_id'] ?? '') === 'v-abc', '访客标识没有进入主体上下文');
    truthy(array_key_exists('_identity', $s), '主体上下文缺少 _identity 键');
    // 页面访问守卫对非 GET 请求不读 Cookie；表单无法带 Authorization。
    // 因此表单互动只能按匿名主体计。把 _identity 补成登录身份 = 用 Cookie 冒充登录。
    truthy($s['_identity'] === null, '表单互动不该携带登录身份（CSRF 面会被打开）');

    $g = PostInteraction::subject($req(), 'v-abc');
    truthy($g === $s, 'GET 与 POST 的主体上下文不一致，liked 回显会和写入错位');
});
