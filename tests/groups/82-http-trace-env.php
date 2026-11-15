<?php
declare(strict_types=1);

use Wx\Core\Config;
use Wx\Core\Kernel;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;

echo "\n=== 82. B2 HTTP 默认档 / C1 请求 ID 贯通 / C3 环境注入 ===\n";

/** 端到端固定 ID 请求；外部头用真实头名，验证大小写归一化后的读取。 */
function rid(string $id): Request { return new Request('GET', '/', [], ['X-Request-Id' => $id]); }

/** 临时进程变量，finally 里撤销，绝不污染后续守卫组。 */
function withEnv(string $key, string $val, callable $fn): mixed
{
    putenv($key . '=' . $val);
    try { return $fn(); } finally { putenv($key); }
}

check('B2 · fillHeaders 只补未设置项：不覆盖、大小写不敏感、不重复写同名头', function (): void {
    $r = (new Response())->setHeader('X-Frame-Options', 'DENY')->setHeader('x-content-type-options', 'custom');
    $r->fillHeaders(['X-Frame-Options' => 'SAMEORIGIN', 'X-Content-Type-Options' => 'nosniff',
                     'Referrer-Policy' => 'strict-origin-when-cross-origin']);
    $h = $r->headers();
    truthy($h['X-Frame-Options'] === 'DENY', '默认档覆盖了路径已声明的头');
    truthy($h['x-content-type-options'] === 'custom' && !isset($h['X-Content-Type-Options']), '大小写归一失效或写了重复头');
    truthy($h['Referrer-Policy'] === 'strict-origin-when-cross-origin', '缺省头未补齐');
    $c = (new Response())->setHeader('Content-Security-Policy', "default-src 'none'");
    $c->fillHeaders(['Content-Security-Policy' => "default-src 'self'"]);
    truthy($c->headers()['Content-Security-Policy'] === "default-src 'none'", '附件 sandbox 声明被默认档覆盖');
});

check('B2 · 默认档集中生效：三条基线齐备，CSP / 协商缓存头默认不出现', function () use ($root): void {
    $h = kernel('bare')->handle(new Request('GET', '/'))->headers();
    $base = ['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN',
             'Referrer-Policy' => 'strict-origin-when-cross-origin'];
    foreach ($base as $k => $v) truthy(($h[$k] ?? null) === $v, '缺少基线头 ' . $k);
    truthy(!isset($h['Content-Security-Policy']), '默认不应输出 CSP（09 组语义）');
    truthy(!isset($h['Cache-Control']), '默认档不得抢注协商缓存头（22b 组语义）');
    truthy(str_contains((string) file_get_contents($root . '/config/app.php'), "'headers' => ["), '默认档未集中声明在配置里');
});

check('B2 · 集中声明可扩展：站点主追加 HSTS / Permissions-Policy，配置 CSP 仍注入', function () use ($root): void {
    $k = new Kernel($root, ['security' => [
        'headers' => ['Strict-Transport-Security' => 'max-age=1', 'Permissions-Policy' => 'camera=()'],
        'csp' => "default-src 'self'",
    ]]);
    $h = $k->handle(new Request('GET', '/'))->headers();
    truthy(($h['Strict-Transport-Security'] ?? '') === 'max-age=1', '追加头未生效');
    truthy(($h['Permissions-Policy'] ?? '') === 'camera=()', '追加头未生效');
    truthy(($h['Content-Security-Policy'] ?? '') === "default-src 'self'", '配置 CSP 未注入');
    truthy(($h['X-Content-Type-Options'] ?? '') === 'nosniff', '追加时丢了出厂基线');
});

check('C1 · Request::id 稳定且是唯一真源：外部复用、非法拒绝、默认形状', function (): void {
    $a = new Request('GET', '/');
    truthy($a->id() === $a->id(), '同一 Request 的 id 不稳定');
    truthy(strlen($a->id()) === 16 && ctype_xdigit($a->id()), '默认 id 形状不是 16 位 hex');
    truthy(rid('abc-123_XYZ.9')->id() === 'abc-123_XYZ.9', '未复用外部传入的合法 ID');
    foreach (['bad id', str_repeat('x', 65), "a\nb", 'a:b'] as $bad) {
        truthy(rid($bad)->id() !== $bad, '非法外部 ID 未被拒绝');
    }
    truthy((new Request('GET', '/'))->id() !== $a->id(), '不同请求生成了相同 ID');
});

check('C1 · 端到端逐字一致：响应头 / 错误信封 / 附件路径 / trace 日志同用一个 ID', function () use ($root): void {
    $id = 'trace-' . bin2hex(random_bytes(4));
    $k = kernel('bare');
    $home = $k->handle(rid($id));
    truthy(($home->headers()['X-Request-Id'] ?? '') === $id, '首页响应头 ID 不一致');
    $api = $k->handle(new Request('GET', '/api/v1/no-such-endpoint-xyz', [], ['X-Request-Id' => $id]));
    $env = json_decode((string) $api->body(), true);
    truthy(($env['code'] ?? null) === 40401, '未命中 API 错误信封');
    truthy(($env['request_id'] ?? '') === $id, '信封 request_id 与响应头对不上');
    truthy(($api->headers()['X-Request-Id'] ?? '') === $id, 'API 响应头 ID 不一致');
    $att = $k->handle(new Request('GET', '/attach/nope', [], ['X-Request-Id' => $id]));
    $aenv = json_decode((string) $att->body(), true);
    truthy(($aenv['request_id'] ?? '') === $id, '附件路径 request_id 未统一');
    $log = (string) @file_get_contents($root . '/storage/logs/' . date('Y-m-d') . '.log');
    truthy(str_contains($log, $id), 'trace 日志未带 request_id，日志与响应对不上');
});

check('C3 · env 注入通道：主配置留空占位、WX_ 映射、优先级与字符串语义', function () use ($root): void {
    $app = require $root . '/config/app.php';
    truthy(($app['database']['pass'] ?? 'x') === '', '主配置仍写着明文口令');
    truthy((string) (new Config($root))->get('database.pass') !== '', '本地 env 文件未注入 DB 凭据');
    truthy(withEnv('WX_SITE__NAME', 'env-name', static fn() => (new Config($root))->get('site.name')) === 'env-name',
        '进程变量未覆盖文件值');
    truthy(withEnv('WX_LIFECYCLE__SIGNATURE_SECRET', 'probe',
        static fn() => (new Config($root))->get('lifecycle.signature_secret')) === 'probe', '__ 分段映射失效');
    truthy(withEnv('WX_CONTENT__PER_PAGE', '000123',
        static fn() => (new Config($root))->get('content.per_page')) === '000123', 'env 值被猜成数值');
    truthy(withEnv('WX_SITE__NAME', 'env',
        static fn() => (new Config($root, ['site' => ['name' => 'override']]))->get('site.name')) === 'override',
        '运行期 override 未置顶');
});

check('C3 · 凭据治理：诊断不泄露值、fail-closed 不变、明文清查与忽略规则', function () use ($root): void {
    $rep = (new Config($root))->envReport();
    truthy(in_array('database.pass', $rep['applied'], true), '未登记注入点');
    truthy($rep['dotenv'] === true, '未识别本地 env 文件');
    truthy($rep['signature_ok'] === false, '验签信任根被意外注入，fail-closed 被破坏');
    truthy(!str_contains((string) json_encode($rep), 'wx_pass_2026'), '诊断接口泄露了凭据值');
    truthy(!str_contains((string) file_get_contents($root . '/config/app.php'), 'wx_pass_2026'), '主配置出现明文口令');
    truthy(is_file($root . '/.env.example')
        && !str_contains((string) file_get_contents($root . '/.env.example'), 'wx_pass_2026'), 'env 模板缺失或含真实口令');
    truthy(str_contains((string) file_get_contents($root . '/.gitignore'), 'config/.env'), '未忽略本地凭据文件');
    exec('cd ' . escapeshellarg($root) . ' && git check-ignore -q config/.env', $o, $rc);
    truthy($rc === 0, 'config/.env 未被 git 忽略');
    truthy((string) kernel('bare')->config()->get('lifecycle.signature_secret') === '', '默认签名密钥应为空');
});

check('B2/C1/C3 · 静态落点：core 仍 24 文件、四处预算合规、关键方法在位', function () use ($root): void {
    $core = count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []);
    truthy($core === 24, 'core 文件数变化：' . $core);
    foreach (['core/Kernel.php', 'core/Config.php', 'core/Http/Request.php', 'core/Http/Response.php'] as $f) {
        $m = \Wx\Tools\Audit\Policy::metrics($root . '/' . $f, $f);
        truthy($m['sloc'] <= 120 && $m['violations'] === [], $f . ' 超核心预算或违规');
    }
    $read = static fn(string $f): string => (string) file_get_contents($root . '/' . $f);
    truthy(str_contains($read('core/Http/Request.php'), 'function id('), 'Request::id 缺失');
    truthy(str_contains($read('core/Http/Response.php'), 'function fillHeaders('), 'fillHeaders 缺失');
    truthy(str_contains($read('core/Kernel.php'), "setHeader('X-Request-Id'")
        && str_contains($read('core/Kernel.php'), "'request_id'"), 'Kernel 贯通落点缺失');
    truthy(str_contains($read('core/Config.php'), 'envPairs(') && str_contains($read('core/Config.php'), 'envReport('),
        'Config 注入层缺失');
    truthy(str_contains($read('systems/Security/SecuritySystem.php'), "get('security.headers'"),
        '默认档未由集中配置驱动');
    truthy(str_contains($read('systems/Api/ApiDispatcher.php'), '$request->id()')
        && str_contains($read('systems/Attachment/AttachmentRoute.php'), '$request->id()'), '仍有各自生成 ID 的割裂点');
});
