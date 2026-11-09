<?php
declare(strict_types=1);

use Wx\Systems\State\State;
use Wx\Systems\State\StateFactory;
use Wx\Systems\State\StateException;

/**
 * 19a · 状态值对象 —— 五态、业务码、动作、进度、不可变与文案映射。
 *
 * 这一组只验证「一个状态是否合法、说了什么」，不碰存储、不碰渲染。
 */

echo "\n=== 19a. 状态值对象：五态 / 规则 / 文案 ===\n";

check('五态构造：等待态与交互态由 held_by 区分，其余相位不得携带', function (): void {
    $f = stateFactory();
    truthy($f->pending()->phase() === 'pending', '待启动态构造失败');
    truthy($f->running()->phase() === 'running', '进行态构造失败');
    truthy($f->done()->phase() === 'done', '完成态构造失败');

    $waiting = $f->waiting();
    truthy($waiting->phase() === 'waiting' && $waiting->heldBy() === 'system', '等待态未标记 system');
    $input = $f->needsInput();
    truthy($input->phase() === 'waiting' && $input->heldBy() === 'user', '交互态未标记 user');

    $threw = false;
    try { State::create(['phase' => 'waiting', 'title' => 'x']); }
    catch (StateException $e) { $threw = $e->getCode() === 42201; }
    truthy($threw, '等待态缺 held_by 应被拒绝');

    $threw = false;
    try { State::create(['phase' => 'running', 'held_by' => 'user', 'title' => 'x']); }
    catch (StateException $e) { $threw = $e->getCode() === 42201; }
    truthy($threw, '非等待态携带 held_by 应被拒绝');
});

check('失败态必须带 4xx/5xx 业务码，非失败态不得携带', function (): void {
    $f = stateFactory();
    $failed = $f->failed(50001);
    truthy($failed->code() === 50001 && $failed->phase() === 'failed', '失败态构造失败');

    foreach ([
        ['phase' => 'failed', 'title' => 'x'],
        ['phase' => 'done', 'code' => 50001, 'title' => 'x'],
    ] as $bad) {
        $threw = false;
        try { State::create($bad); }
        catch (StateException $e) { $threw = $e->getCode() === 42201; }
        truthy($threw, '非法业务码组合应被拒绝：' . json_encode($bad, JSON_UNESCAPED_UNICODE));
    }
});

check('可重试只在失败态成立：5xx 默认可重试，4xx 默认不可', function (): void {
    $f = stateFactory();
    truthy($f->failed(50001)->retryable() === true, '5xx 应默认可重试');
    truthy($f->failed(40301)->retryable() === false, '403 不应默认可重试');
    $threw = false;
    try { State::create(['phase' => 'done', 'title' => 'x', 'retryable' => true]); }
    catch (StateException $e) { $threw = true; }
    truthy($threw, '非失败态不得标记可重试');
});

check('状态到 HTTP 的推导：done=200 / 进行中=202 / 失败取业务码前三位', function (): void {
    $f = stateFactory();
    truthy($f->done()->httpStatus() === 200, '完成态应为 200');
    truthy($f->running()->httpStatus() === 202, '进行态应为 202');
    truthy($f->waiting()->httpStatus() === 202, '等待态应为 202');
    truthy($f->failed(42201)->httpStatus() === 422, '42201 应推导为 422');
    truthy($f->failed(40901)->httpStatus() === 409, '40901 应推导为 409');
    truthy($f->failed(50001)->httpStatus() === 500, '50001 应推导为 500');
});

check('动作清单：最多 4 个、key 唯一、只允许站内链接', function (): void {
    $action = static fn(string $key, string $href = ''): array =>
        ['key' => $key, 'label' => '动作', 'kind' => 'secondary', 'href' => $href, 'method' => 'GET'];

    $ok = State::create(['phase' => 'running', 'title' => 'x',
        'actions' => [$action('a'), $action('b'), $action('c'), $action('d')]]);
    truthy(count($ok->actions()) === 4, '四个动作应被接受');

    foreach ([
        [[$action('a'), $action('a')], '重复 key'],
        [[$action('a'), $action('b'), $action('c'), $action('d'), $action('e')], '超过 4 个'],
        [[$action('a', 'javascript:alert(1)')], '站外协议'],
        [[$action('a', 'https://evil.example')], '外链'],
    ] as [$actions, $why]) {
        $threw = false;
        try { State::create(['phase' => 'running', 'title' => 'x', 'actions' => $actions]); }
        catch (StateException $e) { $threw = $e->getCode() === 42201; }
        truthy($threw, '动作清单应拒绝：' . $why);
    }
});

check('进度边界：0 ≤ done ≤ total，完成态必须走满', function (): void {
    $f = stateFactory();
    $s = $f->running('state.running', ['progress' => ['done' => 2, 'total' => 5, 'unit' => '步', 'label' => '建表']]);
    truthy($s->progress()['done'] === 2 && $s->progress()['total'] === 5, '进度未保留');

    foreach ([
        ['done' => 6, 'total' => 5],
        ['done' => -1, 'total' => 5],
        ['done' => 0, 'total' => 0],
    ] as $bad) {
        $threw = false;
        try { $f->running('state.running', ['progress' => $bad]); }
        catch (StateException $e) { $threw = true; }
        truthy($threw, '非法进度应被拒绝：' . json_encode($bad));
    }

    $threw = false;
    try { $f->done('state.done', ['progress' => ['done' => 1, 'total' => 5]]); }
    catch (StateException $e) { $threw = true; }
    truthy($threw, '完成态进度未走满应被拒绝');
});

check('长任务必须有 id；状态不可变，with 返回新快照', function (): void {
    $threw = false;
    try { State::create(['phase' => 'running', 'scope' => 'task', 'title' => 'x']); }
    catch (StateException $e) { $threw = $e->getCode() === 42201; }
    truthy($threw, 'scope=task 缺 id 应被拒绝');

    $a = State::create(['phase' => 'running', 'title' => 'x', 'progress' => ['done' => 1, 'total' => 3]]);
    $b = $a->with(['phase' => 'done', 'progress' => ['done' => 3, 'total' => 3]]);
    truthy($a->phase() === 'running' && $b->phase() === 'done', 'with 未返回新快照');
    truthy($a->progress()['done'] === 1, '原快照被就地修改');
});

check('文案：核心给键与中文兜底，state.copy 钩子可整体覆写', function (): void {
    $k = kernel(null);
    $k->boot();
    $f = $k->container()->resolve(StateFactory::class);
    $default = $f->failed(50001)->text();
    truthy($default['key'] === 'state.error.internal' && $default['title'] !== '', '默认文案缺失');

    $k->hooks()->filter('state.copy', static function (array $table): array {
        $table['state.error.internal'] = ['title' => '站点自定义标题', 'message' => 'm', 'hint' => 'h'];
        return $table;
    }, 5);
    $custom = $k->container()->resolve(StateFactory::class)->failed(50001)->text();
    truthy($custom['title'] === '站点自定义标题', 'state.copy 覆写未生效');
});

check('Throwable 与统一信封都能翻译成五态，异常原文不进入界面', function (): void {
    $f = stateFactory();

    $auth = $f->fromThrowable(new RuntimeException('SQLSTATE[HY000] 敏感原文', 40301));
    truthy($auth->phase() === 'failed' && $auth->code() === 40301, '异常未翻译成失败态');
    truthy($auth->text()['key'] === 'state.error.permission', '403 未映射到权限文案');
    truthy(!str_contains($auth->text()['message'], 'SQLSTATE'), '异常原文泄露到界面');

    $internal = $f->fromThrowable(new RuntimeException('boom', 50001));
    truthy($internal->retryable() === true, '5xx 失败态应可重试');

    $ok = $f->fromEnvelope(['code' => 0, 'message' => 'ok', 'request_id' => 'abc']);
    truthy($ok->phase() === 'done', '成功信封未翻译成完成态');

    $bad = $f->fromEnvelope(['code' => 42201, 'message' => '参数错误', 'request_id' => 'r1',
        'errors' => [['code' => 'x', 'message' => '标题不能为空']]]);
    truthy($bad->phase() === 'failed' && $bad->text()['message'] === '标题不能为空', '信封错误明细未进入说明');
    truthy($bad->meta()['trace_id'] === 'r1', '信封 request_id 未进入追踪编号');
});

