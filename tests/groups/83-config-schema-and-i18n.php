<?php
declare(strict_types=1);

use Wx\Core\Config;
use Wx\Core\Kernel;
use Wx\Core\Http\Request;
use Wx\Systems\Api\Envelope;
use Wx\Systems\Api\MessageCatalog;

echo "\n=== 83. 配置完整性校验 / 错误文案结构化 / locale 协商 ===\n";

/** 临时进程变量，finally 里撤销，绝不污染后续守卫组。 */
function env83(string $key, string $val, callable $fn): mixed
{
    putenv($key . '=' . $val);
    try { return $fn(); } finally { putenv($key); }
}

$src = static fn(string $f): string => (string) file_get_contents($root . '/' . $f);

check('配置完整性 · 白名单由 app.php 动态展开：声明规模非零、诊断不泄露任何值', function () use ($root, $src): void {
    $rep = (new Config($root))->envReport();
    truthy($rep['declared'] > 50, '声明白名单没有从主配置展开');
    truthy($rep['integrity_ok'] === true, '出厂配置应零完整性问题');
    truthy($rep['rejected'] === [], '出厂环境不应有被拒注入');
    truthy(!str_contains((string) json_encode($rep), 'wx_pass_2026'), '诊断接口泄露了凭据值');
    truthy(str_contains($src('config/app.php'), "'pass'          => ''"), '主配置仍写着明文口令');
});

check('配置完整性 · 标量叶子可注入且保持字符串语义（82 组契约不回归）', function () use ($root): void {
    truthy(env83('WX_SITE__NAME', 'env-name', static fn() => (new Config($root))->get('site.name')) === 'env-name',
        '合法标量叶子未注入');
    truthy(env83('WX_CONTENT__PER_PAGE', '000123', static fn() => (new Config($root))->get('content.per_page')) === '000123',
        'int 槽位被猜成数值，字符串注入语义被破坏');
});

check('配置完整性 · 拼错键显式拒绝：不生效、不造分支、留痕可查', function () use ($root): void {
    $c = env83('WX_DATABSE__PASS', 'evil-value', static fn() => new Config($root));
    truthy($c->get('database.pass') !== 'evil-value', '拼错键竟然注入生效');
    truthy($c->get('databse.pass') === null, '拼错键静默造出了新配置分支');
    truthy(($c->envReport()['rejected']['databse.pass'] ?? null) === 'unknown', '拼错键未按 unknown 留痕');
    truthy(str_contains(implode(' ', $c->issues()), 'databse.pass'), '问题清单未列出拼错键');
    truthy(!str_contains((string) json_encode($c->envReport()), 'evil-value'), '留痕泄露了注入值');
});

check('配置完整性 · 数组槽位拒绝：字符串不得覆盖结构节点，结构本身保持完整', function () use ($root): void {
    $c = env83('WX_API__RATE_LIMIT', 'flattened', static fn() => new Config($root));
    truthy(is_array($c->get('api.rate_limit')), '数组槽位被字符串覆盖成标量');
    truthy((int) $c->get('api.rate_limit.max') === 120, '结构节点被子项冲掉');
    truthy(($c->envReport()['rejected']['api.rate_limit'] ?? null) === 'structure', '结构冲突未按 structure 留痕');
});

check('配置完整性 · 必需骨架键齐备，严格校验可显式抛出', function () use ($root): void {
    $c = new Config($root);
    foreach (['site.name', 'api.prefix', 'api.version', 'database.dsn', 'database.user',
              'security.session.ttl', 'lifecycle.require_checksum', 'lifecycle.require_signature'] as $k) {
        truthy($c->get($k) !== null, '必需骨架键缺失：' . $k);
    }
    $c->assertIntegrity();
    $broken = env83('WX_DATABSE__PASS', 'evil', static fn() => new Config($root));
    $threw = false;
    try { $broken->assertIntegrity(); } catch (RuntimeException $e) { $threw = true; }
    truthy($threw, '存在被拒注入时严格校验未显式抛出');
});

check('文案结构化 · TABLE 一字未动，稳定键由 code 恒定派生', function () use ($src): void {
    truthy(Envelope::TABLE[40401][1] === '接口不存在', '中文默认档不再是 TABLE 原文');
    truthy(count(Envelope::TABLE) === 36, '错误码表数量变化');
    truthy(Envelope::detailKey(40401) === 'error.40401', '稳定键形状不对');
    truthy(MessageCatalog::detailKey(42201) === Envelope::detailKey(42201), '语言包另立了一份稳定键前缀');
    foreach (Envelope::TABLE as $code => $_) {
        truthy(Envelope::detailKey((int) $code) === 'error.' . $code, '稳定键与 code 未一一对应：' . $code);
    }
});

check('文案结构化 · 默认 zh-CN 逐字回落，en-US 命中包，缺项不冒充翻译', function (): void {
    truthy(MessageCatalog::message(40401, 'zh-CN') === Envelope::TABLE[40401][1], '默认档未逐字回落 TABLE');
    truthy(MessageCatalog::message(40401, 'en-US') === 'Endpoint not found', 'en-US 文案未生效');
    truthy(MessageCatalog::message(40401, 'fr-FR') === Envelope::TABLE[40401][1], '未知语言未回落中文');
    $en = (string) file_get_contents(__DIR__ . '/../../systems/Api/MessageCatalog.php');
    foreach (Envelope::TABLE as $code => $_) {
        truthy(str_contains($en, (string) $code . ' =>'), '语言包缺 en-US 条目：' . $code);
    }
});

check('locale 协商 · q 加权 / 前缀命中 / 未知与畸形头一律回落默认', function (): void {
    truthy(MessageCatalog::negotiate('en-US,en;q=0.9,zh;q=0.5', 'zh-CN') === 'en-US', 'q 加权协商失败');
    truthy(MessageCatalog::negotiate('en;q=0.9,zh-CN;q=0.4', 'zh-CN') === 'en-US', '语言前缀未命中');
    truthy(MessageCatalog::negotiate('zh-CN,zh;q=0.8', 'zh-CN') === 'zh-CN', '中文档协商异常');
    truthy(MessageCatalog::negotiate(null, 'zh-CN') === 'zh-CN', '空头未回落默认');
    truthy(MessageCatalog::negotiate('xx-YY', 'zh-CN') === 'zh-CN', '未知标签未回落');
    truthy(MessageCatalog::negotiate(';;;', 'zh-CN') === 'zh-CN', '畸形头改变了默认档');
    truthy(MessageCatalog::negotiate(null, 'bogus') === 'zh-CN', '非法配置默认未兜底');
});

check('locale 贯通 · 同一错误在 zh/en 下逐字可期，成功信封零改写', function () use ($root): void {
    $k = new Kernel($root);
    $zh = json_decode((string) $k->handle(new Request('GET', '/api/v1/no-such-xyz'))->body(), true);
    $en = json_decode((string) $k->handle(new Request('GET', '/api/v1/no-such-xyz', [],
        ['Accept-Language' => 'en-US']))->body(), true);
    truthy(($zh['code'] ?? null) === 40401 && ($en['code'] ?? null) === 40401, '错误码被本地化改写');
    truthy(($zh['message'] ?? null) === '接口不存在', '默认档 message 不再是中文原文');
    truthy(($en['message'] ?? null) === 'Endpoint not found', 'en-US 未贯通到响应');
    truthy(($zh['meta']['detail_key'] ?? null) === 'error.40401', '默认档缺稳定键');
    truthy(($en['meta']['detail_key'] ?? null) === 'error.40401'
        && ($en['meta']['locale'] ?? null) === 'en-US', '协商档缺稳定键或 locale 标注');
    truthy(!isset($zh['meta']['locale']), '默认档不应标注 locale');
    $ok = json_decode((string) $k->handle(new Request('GET', '/api/v1/system/info'))->body(), true);
    truthy(($ok['code'] ?? null) === 0 && !isset($ok['meta']['detail_key']), '成功信封被错误地改写');
});

check('静态落点 · core 仍 24 文件、预算合规、语言包外置且被出口引用', function () use ($root, $src): void {
    $core = count(glob($root . '/core/*.php') ?: []) + count(glob($root . '/core/*/*.php') ?: []);
    truthy($core === 24, 'core 文件数变化：' . $core);
    foreach (['core/Config.php', 'systems/Api/Envelope.php', 'systems/Api/MessageCatalog.php',
              'systems/Api/ApiDispatcher.php', 'config/app.php'] as $f) {
        $m = \Wx\Tools\Audit\Policy::metrics($root . '/' . $f, $f);
        truthy($m['violations'] === [], $f . ' 职责预算违规');
    }
    truthy(str_contains($src('systems/Api/ApiDispatcher.php'), 'MessageCatalog::localize(')
        && str_contains($src('systems/Api/ApiDispatcher.php'), 'Accept-Language'), '出口未贯通协商');
    truthy(str_contains($src('systems/Api/Envelope.php'), 'function detailKey('), '稳定键真源缺失');
    truthy(str_contains($src('core/Config.php'), 'assertIntegrity('), '严格校验入口缺失');
    truthy(is_file($root . '/systems/Api/MessageCatalog.php'), '语言包未外置成独立文件');
});
