<?php
declare(strict_types=1);

/**
 * 一键部署流水线 CLI —— 把「装环境 → 传代码 → 配库 → 装主题 → 起站点 → 验收」
 * 固化为一条可重跑命令，面向 Ubuntu / Debian + nginx + php-fpm + MariaDB。
 *
 *   php tools/deploy.php plan                  只读预演：目标 / 版本 / 步骤 / 凭据就绪（不回显口令）
 *   php tools/deploy.php env                   远程装运行时：nginx、php-fpm、MariaDB、建库建用户
 *   php tools/deploy.php push                  本地打 tar → scp → 远程解包到部署根
 *   php tools/deploy.php serve                 写 nginx 站点并启用（文档根指向 public/）
 *   php tools/deploy.php install               走五步向导装库 / 管理员 / 迁移基线 / 主题 / 加锁
 *   php tools/deploy.php verify                远程只读验收（接口 / 首页 / 主题 / 建表 / 锁）
 *   php tools/deploy.php run --yes             串起 env→push→serve→install→verify 全流程
 *
 * 纪律：
 * - 版本唯一真源 core/version.json；部署根版本回读必须与它逐字一致；
 * - 所有口令经环境变量注入（DP_SSH_PASS / DP_DB_PASS / DP_ADMIN_PASS），
 *   plan 与日志只显示「已提供 / 缺失」，绝不回显任何值，工具本身零明文凭据；
 * - 远程动作全部是外置脚本模板（tools/deploy/*.stub），本入口只做编排与分派；
 * - 写操作幂等：装包已存在即跳过、库 / 用户已存在即复用、配置已对齐即不重写；
 * - 不引导运行时内核：部署是运维事实，与站点装配结果无关；
 * - run 可重跑：向导加 install.lock 后 install 会识别「已安装」并只校准主题。
 */

require_once __DIR__ . '/deploy/Remote.php';

function dp_root(): string { return dirname(__DIR__); }

/** 解析 --key value / --key=value / --flag；同名以最后出现为准。 */
function dp_options(array $args): array
{
    $out = [];
    for ($i = 0; $i < count($args); $i++) {
        $a = $args[$i];
        if (!str_starts_with($a, '--')) continue;
        $k = substr($a, 2);
        if (str_contains($k, '=')) {
            [$k, $v] = explode('=', $k, 2);
            $out[$k] = $v;
        } elseif (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '--')) {
            $out[$k] = $args[++$i];
        } else {
            $out[$k] = true;
        }
    }
    return $out;
}

/** 环境变量缺省、命令行覆盖；口令类只从这两处读，绝不写死。 */
function dp_opt(array $o, string $key, string $env, string $default = ''): string
{
    if (isset($o[$key]) && is_string($o[$key])) return $o[$key];
    $v = getenv($env);
    return $v === false ? $default : $v;
}

function dp_version(): string
{
    $v = json_decode((string) file_get_contents(dp_root() . '/core/version.json'), true);
    $s = (string) ($v['version'] ?? '');
    if ($s === '') throw new RuntimeException('core/version.json 缺 version');
    return $s;
}

/** 汇总配置：主机等有合理默认，三处口令必须显式注入。 */
function dp_config(array $o): array
{
    return [
        'host'       => dp_opt($o, 'host', 'DP_HOST', '127.0.0.1'),
        'user'       => dp_opt($o, 'user', 'DP_USER', 'root'),
        'port'       => (int) dp_opt($o, 'port', 'DP_PORT', '22'),
        'root'       => dp_opt($o, 'root', 'DP_ROOT', '/var/www/miniblog'),
        'domain'     => dp_opt($o, 'domain', 'DP_DOMAIN', ''),
        'db'         => dp_opt($o, 'db', 'DP_DB', 'wx_blog'),
        'db_user'    => dp_opt($o, 'db-user', 'DP_DB_USER', 'wx'),
        'admin'      => dp_opt($o, 'admin', 'DP_ADMIN', 'admin'),
        'theme'      => dp_opt($o, 'theme', 'DP_THEME', 'glass-front'),
        'ssh_pass'   => dp_opt($o, 'ssh-pass', 'DP_SSH_PASS'),
        'db_pass'    => dp_opt($o, 'db-pass', 'DP_DB_PASS'),
        'admin_pass' => dp_opt($o, 'admin-pass', 'DP_ADMIN_PASS'),
        'version'    => dp_version(),
    ];
}

/** 已提供 / 缺失的布尔判定，绝不打印口令本身。 */
function dp_ready(string $v): string { return $v !== '' ? '已提供' : '缺失'; }

function dp_print_plan(array $c): void
{
    echo "=== 部署计划（只读，版本真源 core/version.json） ===\n";
    printf("  目标      %s@%s:%d\n", $c['user'], $c['host'], $c['port']);
    printf("  部署根    %s\n", $c['root']);
    printf("  域名      %s（留空则用 IP）\n", $c['domain'] === '' ? $c['host'] : $c['domain']);
    printf("  版本      %s（回读须逐字一致）\n", $c['version']);
    printf("  数据库    %s / 用户 %s / 前缀 wx_\n", $c['db'], $c['db_user']);
    printf("  管理员    %s / 主题 %s\n", $c['admin'], $c['theme']);
    echo "  凭据      SSH=" . dp_ready($c['ssh_pass']) . ' DB=' . dp_ready($c['db_pass'])
        . ' ADMIN=' . dp_ready($c['admin_pass']) . "（只显示就绪态，不回显值）\n";
    echo "  步骤      env → push → serve → install → verify\n";
    echo "  待办      非 plan 子命令需口令齐备；run 需显式 --yes\n";
}

/** 本地打包：git 归档只含受控文件，天然排除 storage 运行时 / local.php / .env。 */
function dp_pack(array $c): string
{
    $archive = sys_get_temp_dir() . '/miniblog-' . $c['version'] . '.tar.gz';
    $cmd = 'git -C ' . escapeshellarg(dp_root()) . ' archive --format=tar.gz HEAD -o ' . escapeshellarg($archive) . ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !is_file($archive)) throw new RuntimeException('打包失败：' . implode(' ', $out));
    echo '  已打包：' . basename($archive) . '（' . filesize($archive) . " 字节，受控文件）\n";
    return $archive;
}

$command = (string) ($argv[1] ?? 'plan');
$opt = dp_options(array_slice($argv, 2));
$known = ['plan', 'env', 'push', 'serve', 'install', 'verify', 'run'];
if (!in_array($command, $known, true)) {
    fwrite(STDERR, "用法：php tools/deploy.php [plan|env|push|serve|install|verify|run] [选项]\n");
    exit(2);
}
$c = dp_config($opt);
if ($c['domain'] === '') $c['domain'] = $c['host'];

$needSecret = $command !== 'plan';
if ($needSecret && ($c['ssh_pass'] === '' || $c['db_pass'] === '' || $c['admin_pass'] === '')) {
    fwrite(STDERR, "口令未齐：需注入 DP_SSH_PASS / DP_DB_PASS / DP_ADMIN_PASS（或用 --*-pass）。plan 可只读预演。\n");
    exit(2);
}
if ($command === 'run' && ($opt['yes'] ?? false) !== true) {
    fwrite(STDERR, "run 会真实变更远程服务器，请显式加 --yes。\n");
    exit(2);
}

$exit = 0;
if ($command === 'plan') {
    dp_print_plan($c);
} elseif ($command === 'env') {
    dp_report('远程运行时安装 + 建库建用户', dp_retry('env', static fn() => dp_run($c, 'env.stub')));
} elseif ($command === 'push') {
    $archive = dp_pack($c);
    $remote = '/tmp/miniblog-' . $c['version'] . '.tar.gz';
    if (!dp_upload($c, $archive, $remote)) { fwrite(STDERR, "上传失败\n"); exit(1); }
    dp_report('远程解包到部署根', dp_run($c, 'unpack.stub', ['{{ARCHIVE}}' => $remote]));
    @unlink($archive);
} elseif ($command === 'serve') {
    dp_report('写入并启用 nginx 站点', dp_run($c, 'serve.stub'));
} elseif ($command === 'install') {
    dp_report('五步向导 + 主题 + 加锁', dp_run($c, 'install.stub'));
} elseif ($command === 'verify') {
    $r = dp_retry('verify', static fn() => dp_run($c, 'accept.stub'));
    dp_report('远程只读验收', $r);
    dp_checks($r['out']);
} else {
    dp_print_plan($c);
    $steps = [
        'env'     => static fn() => dp_retry('env', static fn() => dp_run($c, 'env.stub')),
        'push'    => static function () use ($c): array {
            $a = dp_pack($c);
            $remote = '/tmp/miniblog-' . $c['version'] . '.tar.gz';
            if (!dp_upload($c, $a, $remote)) return ['rc' => 1, 'out' => '上传失败'];
            $r = dp_run($c, 'unpack.stub', ['{{ARCHIVE}}' => $remote]);
            @unlink($a);
            return $r;
        },
        'serve'   => static fn() => dp_run($c, 'serve.stub'),
        'install' => static fn() => dp_run($c, 'install.stub'),
        'verify'  => static fn() => dp_retry('verify', static fn() => dp_run($c, 'accept.stub')),
    ];
    foreach ($steps as $name => $fn) {
        echo "\n--- {$name} ---\n";
        $r = $fn();
        if (!dp_report($name, $r)) { $exit = 1; break; }
        if ($name === 'verify') dp_checks($r['out']);
    }
}
exit($exit);
