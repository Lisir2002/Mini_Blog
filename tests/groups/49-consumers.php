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
    // v0.48 起零消费分两桶：未登记（zero）与刻意保留（deliberate_zero）。
    truthy((int) $data['consumed'] + count((array) $data['zero'])
        + count((array) ($data['deliberate_zero'] ?? [])) === (int) $data['total'],
        '已消费 + 未登记零消费 + 刻意保留 ≠ 总数');
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

check('会员页：五个只读接口都被算作消费方（经本地包装，记为 ref）', function () use ($run): void {
    $data = json_decode($run('--json')['text'], true) ?: [];
    $all = (array) ($data['all'] ?? []);
    // MemberQuery 通过本地 call() 包装执行操作，扫描器看到的是操作名引用而非 ->execute( 直调。
    // 这不是漏报：ref 与 exec 的区别是「谁在调」，两者都证明接口不再零消费。
    foreach (['member.levels', 'member.titles', 'member.xp.rules', 'member.leaderboard', 'member.profile'] as $op) {
        $row = (array) ($all[$op] ?? []);
        truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
        // v0.42 起 member.titles / member.profile 在 MemberAction 里也有直调，
        // 消费方不再只有 MemberQuery；断言「有 pages/Member 下的消费方」而非某个具体文件。
        $own = array_filter((array) ($row['consumers'] ?? []),
            static fn(string $f): bool => str_starts_with($f, 'pages/Member/'));
        truthy($own !== [], $op . ' 的消费方里没有 pages/Member/ 下的文件');
    }
});

check('成员写操作：五个接口都不再零消费（经 MemberAction 的动作表，记为 ref）', function () use ($run): void {
    $data = json_decode($run('--json')['text'], true) ?: [];
    $all = (array) ($data['all'] ?? []);
    foreach (['member.xp.award', 'member.xp.rule', 'member.title', 'member.title.grant', 'member.level'] as $op) {
        $row = (array) ($all[$op] ?? []);
        truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
        truthy(in_array('pages/Member/MemberAction.php', (array) ($row['consumers'] ?? []), true),
            $op . ' 没有算上 pages/Member/MemberAction.php');
    }
});

check('回归：ACL 权限点字符串不算接口消费', function () use ($run, $root): void {
    // member 系统的权限点与 operation 同名。v0.41 的 MemberQuery 里
    // `allows($identity, 'member.xp.award')` 一度把写接口伪装成已消费，
    // 零消费清单从此看不见它。扫描器必须先把 allows(...) 调用整段抹掉再扫字符串。
    $src = (string) file_get_contents($root . '/tools/consumers.php');
    truthy(str_contains($src, 'allows') && str_contains($src, '$refSrc'),
        '扫描器里没有剔除 ACL 裁决调用的净化逻辑');
    $data = json_decode($run('--json')['text'], true) ?: [];
    $all = (array) ($data['all'] ?? []);
    foreach (['member.xp.award', 'member.title.grant'] as $op) {
        truthy(!in_array('pages/Member/MemberQuery.php', (array) ($all[$op]['consumers'] ?? []), true),
            $op . ' 又把只读页里的权限检查算成了消费');
    }
});

check('二进制通道：下载接口通过页面链接消费，而不是被页面 execute()', function () use ($run): void {
    // /attach/download 是唯一不经 JSON 信封的出口。页面若 execute() 它，
    // 文件字节或磁盘路径就进了页面层。矩阵必须把「页面产出了这个链接」算成消费，
    // 否则会永远把下载接口列成零消费，逼着下一个人写一个错误的调用方。
    $data = json_decode($run('--json')['text'], true) ?: [];
    $row = (array) (($data['all']['attachments.download'] ?? []));
    truthy(($row['kind'] ?? 'none') !== 'none', '下载接口仍是零消费');
    truthy(in_array('pages/Attachments/AttachmentsBlocks.php', (array) ($row['consumers'] ?? []), true),
        '没有把附件库的下载链接算作消费方');
});

check('零消费清单与确证消费互斥', function () use ($run): void {
    $data = json_decode($run('--json')['text'], true) ?: [];
    $zero = (array) ($data['zero'] ?? []);
    $dz = (array) ($data['deliberate_zero'] ?? []);
    foreach ((array) ($data['all'] ?? []) as $op => $row) {
        if (($row['kind'] ?? '') === 'none') {
            truthy(isset($zero[$op]) || isset($dz[$op]),
                $op . ' 是零消费却没有出现在（未登记或刻意）清单里');
        } else {
            truthy(!isset($zero[$op]) && !isset($dz[$op]),
                $op . ' 有消费方却仍被列为零消费');
        }
    }
});

check('v0.44：资源 ACL / 用户组 / 角色树 / 覆盖台账离开零消费，覆盖授权刻意仍是零消费',
    function () use ($run): void {
        $data = json_decode($run('--json')['text'], true) ?: [];
        $all = (array) ($data['all'] ?? []);
        foreach (['permission.acl.set', 'permission.acl.list', 'permission.role.tree',
            'permission.overrides', 'permission.groups', 'permission.group', 'users.view'] as $op) {
            $row = (array) ($all[$op] ?? []);
            truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
            $own = array_filter((array) ($row['consumers'] ?? []),
                static fn(string $f): bool => str_starts_with($f, 'pages/Acl/'));
            truthy($own !== [], $op . ' 的消费方里没有 pages/Acl/ 下的文件');
        }
        // 装配期动作不进网页：页面直调执行者会绕过 RequestGuard 的本机来源限制。
        truthy(isset($data['deliberate_zero']['permission.override.grant']),
            'permission.override.grant 应在刻意零消费登记表里');
    });

check('v0.45：分类与标签两个写接口离开零消费，且消费方落在 pages/Terms/ 下',
    function () use ($run): void {
        $data = json_decode($run('--json')['text'], true) ?: [];
        $all = (array) ($data['all'] ?? []);
        foreach (['content.terms.manage', 'content.attach'] as $op) {
            $row = (array) ($all[$op] ?? []);
            truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
            $own = array_filter((array) ($row['consumers'] ?? []),
                static fn(string $f): bool => str_starts_with($f, 'pages/Terms/'));
            truthy($own !== [], $op . ' 的消费方里没有 pages/Terms/ 下的文件');
        }
        // content.terms 原本只在前台归档页被消费；后台页接入后应同时出现两处。
        $terms = (array) ($all['content.terms'] ?? []);
        truthy(in_array('pages/Terms/TermsQuery.php', (array) ($terms['consumers'] ?? []), true),
            'content.terms 没有把后台取数算作消费方');
        truthy(count((array) ($data['zero'] ?? [])) === 0, '未登记零消费应为 0');
    });

check('v0.46：评论删除 / 表情登记 / 表情删除都落在各自后台页，零消费降到 20',
    function () use ($run): void {
        $data = json_decode($run('--json')['text'], true) ?: [];
        $all = (array) ($data['all'] ?? []);
        foreach (['comments.delete' => 'pages/Queue/',
            'emojis.register' => 'pages/Emojis/', 'emojis.delete' => 'pages/Emojis/'] as $op => $prefix) {
            $row = (array) ($all[$op] ?? []);
            truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
            $own = array_filter((array) ($row['consumers'] ?? []),
                static fn(string $f): bool => str_starts_with($f, $prefix));
            truthy($own !== [], $op . ' 的消费方里没有 ' . $prefix . ' 下的文件');
        }
        // comments.list 原本只在前台文章页被读；后台「按文章浏览」后应多一个消费方。
        $list = (array) ($all['comments.list'] ?? []);
        truthy(in_array('pages/Queue/QueueQuery.php', (array) ($list['consumers'] ?? []), true),
            'comments.list 没把后台取数算作消费方');
        truthy(in_array('pages/Emojis/EmojisQuery.php',
            (array) (($all['emojis.list'] ?? [])['consumers'] ?? []), true),
            'emojis.list 没把后台目录算作消费方');
        truthy(count((array) ($data['zero'] ?? [])) === 0, '未登记零消费应为 0');
    });

check('v0.47：漏报修正 —— /console/system 显式字面量化，system.* 与 content.stats 如实记账',
    function () use ($run): void {
        $data = json_decode($run('--json')['text'], true) ?: [];
        $all = (array) ($data['all'] ?? []);
        foreach (['system.info', 'system.health', 'system.layers', 'system.contracts',
            'content.stats'] as $op) {
            $row = (array) ($all[$op] ?? []);
            truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
            truthy(in_array('pages/Systems/SystemsQuery.php', (array) ($row['consumers'] ?? []), true),
                $op . ' 没把 /console/system 的取数算作消费方');
        }
    });

check('v0.47：会话收口 —— 登出与残留登出都经安全层走会话接口，零消费降到 14',
    function () use ($run): void {
        $data = json_decode($run('--json')['text'], true) ?: [];
        $all = (array) ($data['all'] ?? []);
        foreach (['auth.session.revoke', 'auth.logout'] as $op) {
            $row = (array) ($all[$op] ?? []);
            truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
            truthy(in_array('systems/Security/PageSession.php', (array) ($row['consumers'] ?? []), true),
                $op . ' 的消费方不是安全层 PageSession');
        }
    });

check('v0.48：升级可观测 —— history / verify / releases / task 由 /console/system 只读消费',
    function () use ($run): void {
        $data = json_decode($run('--json')['text'], true) ?: [];
        $all = (array) ($data['all'] ?? []);
        foreach (['release.history', 'lifecycle.verify', 'lifecycle.releases',
            'state.task.status'] as $op) {
            $row = (array) ($all[$op] ?? []);
            truthy(($row['kind'] ?? 'none') !== 'none', $op . ' 仍是零消费');
            truthy(in_array('pages/Systems/SystemsQuery.php', (array) ($row['consumers'] ?? []), true),
                $op . ' 没把 /console/system 的探查算作消费方');
        }
    });

check('v0.48：刻意零消费制度化 —— 登记表双向核对，未登记零消费必须为空',
    function () use ($run): void {
        $data = json_decode($run('--json')['text'], true) ?: [];
        $all = (array) ($data['all'] ?? []);
        $ledger = (array) ($data['deliberate'] ?? []);
        $dz = (array) ($data['deliberate_zero'] ?? []);
        truthy(count($ledger) === 6, '登记表应有 6 条，实际 ' . count($ledger));
        foreach ($ledger as $op => $reason) {
            truthy(is_string($reason) && strlen($reason) >= 24,
                $op . ' 的登记理由缺失或太短：没有理由的登记无效');
            $row = (array) ($all[$op] ?? []);
            truthy(($row['kind'] ?? 'none') === 'none',
                $op . ' 有消费方却被登记为刻意零消费');
            truthy(isset($dz[$op]), $op . ' 在矩阵里不是零消费，登记成了死条目');
        }
        truthy(count((array) ($data['zero'] ?? [])) === 0,
            '存在未登记的零消费接口：' . implode(',', array_keys((array) ($data['zero'] ?? []))));
        foreach (array_keys((array) ($data['zero'] ?? [])) as $op) {
            truthy((bool) preg_match('/^(lifecycle\.|release\.|permission\.override\.grant|state\.task\.)/',
                (string) $op), '零消费清单里出现了业务接口：' . $op);
        }
    });

check('--zero 只打印零消费，不混入已消费段', function () use ($run): void {
    $r = $run('--zero');
    truthy($r['code'] === 0, '--zero 退出码非 0');
    truthy(str_contains($r['text'], '零消费'), '没有输出零消费段');
    truthy(!str_contains($r['text'], '--- 已消费 ---'), '--zero 仍然打印了已消费段');
});
