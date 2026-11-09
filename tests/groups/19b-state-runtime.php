<?php
declare(strict_types=1);

use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Systems\State\MemoryStateStore;
use Wx\Systems\State\State;
use Wx\Systems\State\StateFactory;
use Wx\Systems\State\StateException;
use Wx\Systems\State\StateTasks;

/**
 * 19b · 状态运行面 —— 长任务持久化、乐观锁、主题渲染、异常出口与只读接口。
 *
 * 这一组只验证「状态怎么被存下来、怎么被看到、异常怎么被接住」。
 */

echo "\n=== 19b. 状态运行面：长任务 / 渲染 / 异常出口 ===\n";

/** 用主题片段渲染一个状态，返回 HTML；不启动内核、不发请求。 */
function stateHtml(array $state): string
{
    global $root;
    $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ob_start();
    include $root . '/themes/Bare/templates/partials/state.php';
    return (string) ob_get_clean();
}

check('长任务：创建、读回、推进、终态拒绝回退', function (): void {
    $k = kernel(null);
    $k->boot();
    $tasks = $k->container()->resolve(StateTasks::class);
    $id = 'task-' . bin2hex(random_bytes(4));

    $opened = $tasks->open('demo', State::create(['phase' => 'running', 'scope' => 'task', 'id' => $id,
        'title' => '正在处理', 'progress' => ['done' => 1, 'total' => 3]]));
    truthy($opened->revision() === 1, '新建任务版本应为 1');
    truthy($tasks->find($id)?->phase() === 'running', '任务未读回');

    $advanced = $tasks->advance($opened->with(['progress' => ['done' => 2, 'total' => 3]]));
    truthy($advanced->revision() === 2, '推进后版本未递增');

    $ended = $tasks->advance($advanced->with(['phase' => 'done', 'progress' => ['done' => 3, 'total' => 3]]));
    truthy($ended->phase() === 'done', '任务未结束');

    $threw = false;
    try { $tasks->advance($ended->with(['phase' => 'running'])); }
    catch (StateException $e) { $threw = $e->getCode() === 40901; }
    truthy($threw, '终态应拒绝再次推进');
});

check('乐观锁：用过期的 revision 写入必须 40901，不静默覆盖', function (): void {
    $store = new MemoryStateStore();
    $id = 'cas-' . bin2hex(random_bytes(4));
    $first = $store->put(State::create(['phase' => 'running', 'scope' => 'task', 'id' => $id, 'title' => 'x']));
    $second = $store->put($first->with(['progress' => ['done' => 1, 'total' => 2]]));
    truthy($second->revision() === 2, '第二次写入版本异常');

    $threw = false;
    try { $store->put($first->with(['progress' => ['done' => 2, 'total' => 2]])); }
    catch (StateException $e) { $threw = $e->getCode() === 40901; }
    truthy($threw, '过期版本写入未被拒绝');
});

check('过期清理：任务到期后读不到，sweep 返回清理条数', function (): void {
    $store = new MemoryStateStore();
    $id = 'ttl-' . bin2hex(random_bytes(4));
    $store->put(State::create(['phase' => 'running', 'scope' => 'task', 'id' => $id, 'title' => 'x']), '', '', 1);
    truthy($store->find($id) !== null, '未过期任务应可读');
    truthy($store->sweep(time() + 5) === 1, 'sweep 未清理过期任务');
    truthy($store->find($id) === null, '清理后仍能读到');
});

check('安装页产出真实状态：环境就绪时为交互态，携带文案与追踪编号', function (): void {
    $k = pageKernel('bare');
    $r = $k->handle(new Request('GET', '/install'));
    truthy(str_contains($r->contentType(), 'text/html'), '安装页未输出 HTML');
    $body = $r->body();
    truthy(str_contains($body, 'role="status"'), '安装页缺少状态语义');
    truthy(str_contains($body, 'aria-live'), '安装页缺少读屏提示');
    truthy(str_contains($body, 'state--waiting'), '未安装时应为等待/交互态');
});

check('无主题时状态退化为纯数据：payload.state 存在、零 HTML', function (): void {
    $k = pageKernel(null);
    $r = $k->handle(new Request('GET', '/install'));
    $data = (array) json_decode($r->body(), true);
    truthy(is_array($data['state'] ?? null), '无主题时 payload 缺少 state');
    truthy(($data['state']['phase'] ?? '') === 'waiting', '状态相位不正确');
    truthy(($data['state']['held_by'] ?? '') === 'user', '交互态未标记 user');
    truthy(!str_contains($r->body(), '<html'), '无主题时出现 HTML');
});

check('主题片段：进行态带 aria-busy 与 progress，失败态用 alert + assertive', function (): void {
    $f = stateFactory();
    $running = stateHtml($f->running('state.running', [
        'progress' => ['done' => 2, 'total' => 5, 'unit' => '步', 'label' => '建表'],
    ])->toArray());
    truthy(str_contains($running, 'aria-busy="true"'), '进行态缺少 aria-busy');
    truthy(str_contains($running, '<progress'), '进行态缺少进度条');
    truthy(str_contains($running, 'role="status"'), '进行态应使用 status');
    truthy(str_contains($running, '2/5步'), '进度文本不正确');

    $failed = stateHtml($f->failed(50001)->toArray());
    truthy(str_contains($failed, 'role="alert"'), '失败态应使用 alert');
    truthy(str_contains($failed, 'aria-live="assertive"'), '失败态应为 assertive');
    truthy(str_contains($failed, 'state--failed'), '失败态样式钩子缺失');

    $input = stateHtml($f->needsInput('state.waiting.user', ['actions' => [[
        'key' => 'confirm', 'label' => '确认继续', 'kind' => 'primary', 'href' => '', 'method' => 'POST',
    ]]])->toArray());
    truthy(str_contains($input, 'data-state-action'), '交互态缺少动作按钮');
    truthy(str_contains($input, 'state--waiting'), '交互态样式钩子缺失');
});

check('状态文案转义：标题里的标签不会被当成结构输出', function (): void {
    $f = stateFactory();
    $html = stateHtml($f->failed(50001, 'state.error.internal', [
        'text' => ['title' => '<script>alert(1)</script>'],
    ])->toArray());
    truthy(!str_contains($html, '<script>alert(1)</script>'), '状态标题未转义');
    truthy(str_contains($html, '&lt;script&gt;'), '转义后的文本未按实体输出');
});

check('未捕获异常：内核询问 kernel.exception，状态系统返回五态页面', function (): void {
    $k = kernel('bare');
    $k->hooks()->on('route.resolve', static function (): void {
        throw new RuntimeException('boom', 50001);
    }, 1);

    $old = [$_SERVER['REQUEST_URI'] ?? null, $_SERVER['REQUEST_METHOD'] ?? null];
    $_SERVER['REQUEST_URI'] = '/__boom__';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    ob_start();
    try { $k->run(); } finally { $out = (string) ob_get_clean(); }
    [$_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']] = $old;

    truthy(str_contains($out, 'state--failed'), '异常未走状态系统');
    truthy(str_contains($out, 'role="alert"'), '异常页缺少 alert 语义');
    truthy(!str_contains($out, 'boom'), '异常原文泄露到页面');
});

check('状态系统可停用：页面照常工作，只是不带状态信封', function () use ($root): void {
    $k = new Kernel($root, ['theme' => ['active' => null], 'systems' => ['disabled' => ['state']]]);
    $k->boot();
    truthy(!$k->container()->has(StateFactory::class), '停用后仍绑定了状态契约');
    $r = $k->handle(new Request('GET', '/install'));
    truthy($r->status() === 200, '停用状态系统后安装页不可用');
    $data = (array) json_decode($r->body(), true);
    truthy($data['title'] !== '', '停用后页面数据缺失');
});

check('长任务接口：未认证 40102，认证后按 id 查询，缺失返回 40403', function (): void {
    $k = adminKernel();
    $anon = $k->handle(new Request('GET', '/api/v1/state/task', ['id' => 'nope']));
    truthy($anon->status() === 401, '未认证应返回 401');

    $miss = $k->handle(new Request('GET', '/api/v1/state/task', ['id' => 'nope'], adminHeaders()));
    $body = (array) json_decode($miss->body(), true);
    truthy(($body['code'] ?? 0) === 40403, '不存在的任务应返回 40403');

    $bad = $k->handle(new Request('GET', '/api/v1/state/task', ['id' => '../etc/passwd'], adminHeaders()));
    truthy(((array) json_decode($bad->body(), true))['code'] === 42201, '非法 id 应返回 42201');
});
