<?php
declare(strict_types=1);

/**
 * 49 · 接口消费方矩阵：把「零消费」变成可查的证据
 *
 * 前几版反复出现「接口完备、页面没人调」（举报队列、关键词搜索、内容生命周期），
 * 每次都是人肉翻 registry.json 才发现的。这一组确保工具本身可重跑、结果与清单一致：
 *  1. 矩阵覆盖全部 operation，不遗漏也不凭空多出；
 *  2. 本轮新接的页面（搜索 / 内容管理 / 订阅 / 附件）确实被算作消费方；
 *  3. 零消费清单与「确证消费」互斥 —— 一个 operation 不能同时出现在两边。
 */
echo "\n=== 49. 接口消费方矩阵 ===\n";

$run = static function (string $args) use ($root): array {
    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/consumers.php') . ' ' . $args
        . ' 2>&1', $out, $code);
    return ['code' => $code, 'text' => implode("\n", $out)];
};

check('工具可重跑：--json 输出合法结构，operation 数与清单一致', function () use ($run, $root): void {
    $r = $run('--json');
    truthy($r['code'] === 0, '工具退出码非 0：' . substr($r['text'], 0, 200));
    $data = json_decode($r['text'], true);
    truthy(is_array($data), '--json 输出不是合法 JSON');

    $registry = json_decode((string) file_get_contents($root . '/api/registry.json'), true);
    $ops = array_filter(array_map(static fn(array $e): string => (string) ($e['operation'] ?? ''),
        (array) ($registry['endpoints'] ?? [])));
    truthy((int) $data['total'] === count($ops), '矩阵 operation 数与清单不一致：'
        . $data['total'] . ' vs ' . count($ops));
    truthy((int) $data['consumed'] + count((array) $data['zero']) === (int) $data['total'],
        '已消费 + 零消费 ≠ 总数');
});

check('本轮接入的页面确实被算作消费方', function () use ($run): void {
    $data = json_decode($run('--json')['text'], true) ?: [];
    $all = (array) ($data['all'] ?? []);
    $expect = [
        'content.posts'     => 'pages/Search/SearchQuery.php',
        'content.transition'=> 'pages/ContentAdmin/ContentAdminAction.php',
        'attachments.list'  => 'pages/Post/PostAttachments.php',
        'comments.moderate' => 'pages/Queue/QueueAction.php',
        'auth.register'     => 'pages/Auth/AuthAction.php',
        'users.me'          => 'pages/Account/AccountQuery.php',
        'auth.password.change' => 'pages/Account/AccountAction.php',
    ];
    foreach ($expect as $op => $file) {
        $consumers = (array) ($all[$op]['consumers'] ?? []);
        truthy(in_array($file, $consumers, true), $op . ' 没有算上 ' . $file
            . '（实际：' . implode(', ', $consumers) . '）');
        truthy(($all[$op]['kind'] ?? '') === 'exec', $op . ' 的消费类型不是确证调用');
    }
});

check('系统层接缝：@consumes 声明的会话登录被算作消费方', function () use ($run): void {
    $data = json_decode($run('--json')['text'], true) ?: [];
    $row = (array) (($data['all']['auth.session.create'] ?? []));
    truthy(($row['kind'] ?? '') === 'seam', '会话登录不是接缝消费：' . json_encode($row));
    truthy(in_array('systems/Security/PageSession.php', (array) ($row['consumers'] ?? []), true),
        '没有把 PageSession 算作会话登录的消费方');
});

check('零消费清单与确证消费互斥', function () use ($run): void {
    $data = json_decode($run('--json')['text'], true) ?: [];
    $zero = (array) ($data['zero'] ?? []);
    foreach ((array) ($data['all'] ?? []) as $op => $row) {
        if (($row['kind'] ?? '') === 'none') {
            truthy(isset($zero[$op]), $op . ' 是零消费却没有出现在清单里');
        } else {
            truthy(!isset($zero[$op]), $op . ' 有消费方却仍被列为零消费');
        }
    }
});

check('--zero 只打印零消费，不混入已消费段', function () use ($run): void {
    $r = $run('--zero');
    truthy($r['code'] === 0, '--zero 退出码非 0');
    truthy(str_contains($r['text'], '零消费'), '没有输出零消费段');
    truthy(!str_contains($r['text'], '--- 已消费 ---'), '--zero 仍然打印了已消费段');
});
