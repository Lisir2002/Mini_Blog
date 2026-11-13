<?php
declare(strict_types=1);

/**
 * 73 · 万象核心基座加固守卫（纯函数，不依赖数据库）
 *
 * 锁三件事：
 *  A. Request 输入解析健壮性 —— json() 区分空 body / 非法 JSON / 顶层非数组，
 *     新增 jsonError() / jsonValid() 显式暴露解析结果；header() 构造时小写归一化、O(1) 查找。
 *  B. Container 循环依赖防护 —— resolve() 遇环抛清晰 RuntimeException，不再无限递归栈溢出。
 *  C. SystemRegistry 早失败 —— 同名系统层冲突、system.json 缺 class 在发现期就被拦下。
 */

use Wx\Core\Container;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Core\SystemRegistry;

echo "\n=== 73. 万象核心基座加固 ===\n";

// ---------------------------------------------------------------------------
// A. Request 输入解析健壮性
// ---------------------------------------------------------------------------

check('A1 · 空 body：json() 返回空数组且不算错误', function (): void {
    $r = new Request('POST', '/x', [], [], [], '');
    truthy($r->json() === [], '空 body 应返回 []');
    truthy($r->jsonError() === null, '空 body 不应记为错误');
    truthy($r->jsonValid() === true, '空 body 应视为合法');
});

check('A2 · 合法 JSON 对象：原样解码且 jsonValid', function (): void {
    $r = new Request('POST', '/x', [], [], [], '{"a":1,"b":"二"}');
    truthy($r->json() === ['a' => 1, 'b' => '二'], '应解码为关联数组');
    truthy($r->jsonError() === null, '合法 JSON 不应有错');
    truthy($r->jsonValid() === true, '应标记合法');
});

check('A3 · 非法 JSON：返回空数组且 jsonError 指明解析失败', function (): void {
    $r = new Request('POST', '/x', [], [], [], 'not json {{{');
    truthy($r->json() === [], '非法 JSON 退化空数组');
    truthy($r->jsonError() !== null, '应记录错误');
    truthy(str_contains((string) $r->jsonError(), 'JSON 解析失败'), '错误信息应点明解析失败：' . $r->jsonError());
    truthy($r->jsonValid() === false, '应标记不合法');
});

check('A4 · 顶层标量（数字 / null）：记为「顶层非数组」错误', function (): void {
    foreach (['123', 'null', '"str"', 'true'] as $body) {
        $r = new Request('POST', '/x', [], [], [], $body);
        truthy($r->json() === [], "顶层 {$body} 应退化空数组");
        truthy(str_contains((string) $r->jsonError(), '顶层必须是对象或数组'),
            "顶层 {$body} 应判为非数组错误：{$r->jsonError()}");
    }
});

check('A5 · json() 结果可重复调用且一致', function (): void {
    $r = new Request('POST', '/x', [], [], [], '{"k":1}');
    $first = $r->json();
    truthy($r->json() === $first, '二次调用应返回同一解码结果');
    truthy($r->jsonValid() === true, '二次调用仍合法');
});

check('A6 · header() 大小写不敏感且 O(1) 命中（构造时小写归一化）', function (): void {
    $r = new Request('GET', '/x', [], ['X-Foo-Bar' => 'baz'], [], '');
    truthy($r->header('x-foo-bar') === 'baz', '小写键应命中');
    truthy($r->header('X-FOO-BAR') === 'baz', '大写键应命中');
    truthy($r->header('X-Foo-Bar') === 'baz', '原大小写键也应命中');
    truthy($r->header('missing') === null, '缺失头应返回 null');
});

check('A7 · headers() 返回小写键的规范形态', function (): void {
    $r = new Request('GET', '/x', [], ['Content-Type' => 'application/json', 'X-Token' => 't'], [], '');
    $hs = $r->headers();
    truthy(($hs['content-type'] ?? null) === 'application/json', '键应规范为小写');
    truthy(($hs['x-token'] ?? null) === 't', '键应规范为小写');
    truthy(!array_key_exists('Content-Type', $hs), '原大小写键不应残留');
});

// ---------------------------------------------------------------------------
// B. Container 循环依赖防护
// ---------------------------------------------------------------------------

check('B1 · 正常 singleton / 普通绑定仍可解析', function (): void {
    $c = new Container();
    $c->singleton('B1_Svc', static fn(): object => new stdClass());
    $c->bind('B1_Plain', static fn(): object => new stdClass());
    truthy($c->resolve('B1_Svc') instanceof stdClass, 'singleton 应解析');
    truthy($c->resolve('B1_Plain') instanceof stdClass, '普通绑定应解析');
    truthy($c->resolve('B1_Svc') === $c->resolve('B1_Svc'), 'singleton 应返回同一实例');
});

check('B2 · 未绑定契约：仍是「契约未实现」错误', function (): void {
    $c = new Container();
    $thrown = false;
    try {
        $c->resolve('B2_Missing');
    } catch (RuntimeException $e) {
        $thrown = str_contains($e->getMessage(), '契约未实现');
    }
    truthy($thrown, '未绑定应报「契约未实现」');
});

check('B3 · 工厂互解析形成环：抛「循环依赖」而非栈溢出', function (): void {
    $c = new Container();
    // 工厂通过闭包捕获容器，互相解析对方，制造 A -> B -> A 环。
    $c->bind('B3_A', function () use ($c): object {
        $c->resolve('B3_B');
        return new stdClass();
    });
    $c->bind('B3_B', function () use ($c): object {
        $c->resolve('B3_A');
        return new stdClass();
    });
    $thrown = false;
    $msg = '';
    try {
        $c->resolve('B3_A');
    } catch (RuntimeException $e) {
        $thrown = true;
        $msg = $e->getMessage();
    }
    truthy($thrown, '循环依赖必须抛异常，而非无限递归栈溢出');
    truthy(str_contains($msg, '循环依赖'), '错误信息应点明循环依赖：' . $msg);
    truthy(str_contains($msg, 'B3_A') && str_contains($msg, 'B3_B'), '错误应列出依赖链：' . $msg);
});

check('B4 · 自引用（A 解析 A）同样被环检测拦下', function (): void {
    $c = new Container();
    $c->bind('B4_Self', function () use ($c): object {
        $c->resolve('B4_Self');
        return new stdClass();
    });
    $thrown = false;
    try {
        $c->resolve('B4_Self');
    } catch (RuntimeException $e) {
        $thrown = str_contains($e->getMessage(), '循环依赖');
    }
    truthy($thrown, '自引用应被循环依赖检测拦下');
});

// ---------------------------------------------------------------------------
// C. SystemRegistry 早失败
// ---------------------------------------------------------------------------

/** 造一个最小临时根目录（只含 config/app.php），避免触碰真实 systems/ 与数据库。 */
function wx73_temp_root(string $id): string
{
    $tmp = sys_get_temp_dir() . '/wx-core73-' . $id . '-' . getmypid();
    if (is_dir($tmp)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        }
        rmdir($tmp);
    }
    mkdir($tmp . '/config', 0777, true);
    file_put_contents($tmp . '/config/app.php', "<?php\nreturn [];\n");
    return $tmp;
}

function wx73_cleanup(string $tmp): void
{
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($tmp);
}

check('C1 · 同名系统层冲突：发现期即抛「重名冲突」', function (): void {
    $tmp = wx73_temp_root('dup');
    try {
        mkdir($tmp . '/systems/dup1', 0777, true);
        mkdir($tmp . '/systems/dup2', 0777, true);
        file_put_contents($tmp . '/systems/dup1/system.json',
            json_encode(['name' => 'dup', 'class' => 'X\\Y', 'enabled' => true], JSON_UNESCAPED_SLASHES));
        file_put_contents($tmp . '/systems/dup2/system.json',
            json_encode(['name' => 'dup', 'class' => 'X\\Z', 'enabled' => true], JSON_UNESCAPED_SLASHES));
        $k = new Kernel($tmp);
        $thrown = false;
        $msg = '';
        try {
            $k->systems()->discover();
        } catch (RuntimeException $e) {
            $thrown = true;
            $msg = $e->getMessage();
        }
        truthy($thrown, '同名系统层必须被发现期拦下');
        truthy(str_contains($msg, '重名冲突'), '错误应点明重名冲突：' . $msg);
    } finally {
        wx73_cleanup($tmp);
    }
});

check('C2 · system.json 缺 class：发现期即抛「缺少 class」', function (): void {
    $tmp = wx73_temp_root('noclass');
    try {
        mkdir($tmp . '/systems/bad', 0777, true);
        file_put_contents($tmp . '/systems/bad/system.json',
            json_encode(['name' => 'bad', 'enabled' => true], JSON_UNESCAPED_SLASHES));
        $k = new Kernel($tmp);
        $thrown = false;
        $msg = '';
        try {
            $k->systems()->discover();
        } catch (RuntimeException $e) {
            $thrown = true;
            $msg = $e->getMessage();
        }
        truthy($thrown, '缺 class 必须被发现期拦下');
        truthy(str_contains($msg, '缺少 class'), '错误应点明缺少 class：' . $msg);
    } finally {
        wx73_cleanup($tmp);
    }
});

check('C3 · 正常系统层：发现期通过且返回装配顺序', function (): void {
    $tmp = wx73_temp_root('ok');
    try {
        mkdir($tmp . '/systems/ok', 0777, true);
        file_put_contents($tmp . '/systems/ok/system.json',
            json_encode(['name' => 'ok', 'class' => 'stdClass', 'enabled' => true], JSON_UNESCAPED_SLASHES));
        $k = new Kernel($tmp);
        $order = $k->systems()->discover();
        truthy($order === ['ok'], '正常系统层应被发现：' . json_encode($order));
    } finally {
        wx73_cleanup($tmp);
    }
});

check('C4 · 禁用系统层不参与冲突检测（enabled=false 跳过）', function (): void {
    $tmp = wx73_temp_root('disabled');
    try {
        mkdir($tmp . '/systems/d1', 0777, true);
        mkdir($tmp . '/systems/d2', 0777, true);
        file_put_contents($tmp . '/systems/d1/system.json',
            json_encode(['name' => 'same', 'class' => 'X\\A', 'enabled' => false], JSON_UNESCAPED_SLASHES));
        file_put_contents($tmp . '/systems/d2/system.json',
            json_encode(['name' => 'same', 'class' => 'X\\B', 'enabled' => true], JSON_UNESCAPED_SLASHES));
        $k = new Kernel($tmp);
        $order = $k->systems()->discover();
        truthy($order === ['same'], '禁用项跳过，启用项正常发现：' . json_encode($order));
    } finally {
        wx73_cleanup($tmp);
    }
});
