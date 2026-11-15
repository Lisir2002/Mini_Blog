<?php
declare(strict_types=1);
namespace Wx\Core;

/**
 * 配置读取器。核心不解释配置项的含义，含义由各系统层自己定义。
 *
 * 合并顺序（后者覆盖前者）：
 *   config/app.php（带注释的主配置，入库）
 *   → config/local.php（本机覆盖层，不入库，删掉即回默认）
 *   → 环境变量 / config/.env（WX_ 前缀，部署注入，见 .env.example）
 *   → 构造期 override（多站点 / 嵌入 / 测试，优先级最高）
 *
 * v0.68 C3：凭据只经环境注入，主配置不留明文。env 一律按字符串注入、不猜类型
 * （"123456" 不能被转成整数）；布尔 / 数值型配置仍走 local.php 或 override。
 *
 * v0.69 完整性收口：白名单由入库主配置 app.php 的声明动态展开（单一事实源，零手写清单），
 * env 键必须精确命中声明的标量叶子——拼错键与「字符串覆盖数组槽位」一律显式拒绝并留痕，
 * 不再让 put() 静默造出一条配置分支。构造期只拒绝、不抛异常（删光配置仍可启动），
 * 需严格校验的调用方显式调 assertIntegrity()；诊断接口绝不返回任何值。
 */
final class Config
{
    /** 框架骨架必需键：缺一即说明主配置被改坏，不能静默拿默认值顶上。 */
    private const REQUIRED = ['site.name', 'api.prefix', 'api.version', 'database.dsn', 'database.user',
        'security.session.ttl', 'lifecycle.require_checksum', 'lifecycle.require_signature'];

    private array $data;
    /** @var list<string> 本次由 env 注入的点路径；值本身不留在内存清单里 */
    private array $envApplied = [];
    /** @var array<string, string> 被拒注入：点路径 => unknown|structure；只记路径与原因，不记值 */
    private array $rejected = [];
    /** @var array<string, string> app.php 声明的点路径 => leaf|branch */
    private array $declared = [];
    private bool  $dotenvLoaded = false;

    /** @param array<string, mixed> $override 运行期覆盖，用于多站点 / 嵌入 / 测试 */
    public function __construct(private string $root, array $override = [])
    {
        $file = $root . '/config/app.php';
        $loaded = is_file($file) ? require $file : [];
        $this->data = is_array($loaded) ? $loaded : [];
        $this->declared = self::paths($this->data);

        // 安装器 / 运维只写 config/local.php：本机显式覆盖层，允许新增键，不受 env 白名单约束。
        $local = $root . '/config/local.php';
        if (is_file($local)) {
            $localData = require $local;
            if (is_array($localData)) $this->data = array_replace_recursive($this->data, $localData);
        }

        foreach ($this->envPairs() as $path => $value) {
            $kind = $this->declared[$path] ?? null;
            if ($kind !== 'leaf') {
                $this->rejected[$path] = $kind === 'branch' ? 'structure' : 'unknown';
                continue;
            }
            $this->put($path, $value);
            $this->envApplied[] = $path;
        }

        if ($override !== []) $this->data = array_replace_recursive($this->data, $override);
    }

    /** 展开声明：标量记 leaf（可注入），数组记 branch（字符串不得覆盖）。 */
    private static function paths(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $k => $v) {
            $p = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            $out[$p] = is_array($v) ? 'branch' : 'leaf';
            if (is_array($v)) $out = array_replace($out, self::paths($v, $p));
        }
        return $out;
    }

    /** @return array<string, string> 点路径 => 值；进程环境变量优先于 config/.env 同名键 */
    private function envPairs(): array
    {
        $raw = [];
        $dotenv = $this->root . '/config/.env';
        if (is_file($dotenv)) {
            $this->dotenvLoaded = true;
            foreach (explode("\n", (string) file_get_contents($dotenv)) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                $eq = strpos($line, '=');
                if ($eq === false) continue;
                $key = trim(substr($line, 0, $eq));
                if ($key !== '') $raw[$key] = trim(trim(substr($line, $eq + 1)), "\"'");
            }
        }
        foreach (getenv() ?: [] as $key => $val) {
            if (is_string($key) && is_string($val)) $raw[$key] = $val;
        }

        $out = [];
        foreach ($raw as $key => $val) {
            if (!str_starts_with($key, 'WX_')) continue;
            $segments = explode('__', strtolower(substr($key, 3)));
            if ($segments === [] || in_array('', $segments, true)) continue;
            $out[implode('.', $segments)] = $val;
        }
        return $out;
    }

    /** 把点路径写入嵌套配置；段内单下划线保留，对应 signature_secret 这类键名。 */
    private function put(string $path, string $value): void
    {
        $ref = &$this->data;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($ref)) return;
            if (!array_key_exists($seg, $ref) || !is_array($ref[$seg])) $ref[$seg] = [];
            $ref = &$ref[$seg];
        }
        $ref = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cur = $this->data;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) return $default;
            $cur = $cur[$seg];
        }
        return $cur;
    }

    /** @return list<string> 只读诊断：缺失的必需骨架键 + 被拒的注入键；不含任何值。 */
    public function issues(): array
    {
        $out = [];
        foreach (self::REQUIRED as $key) if ($this->get($key) === null) $out[] = "缺少必需键 {$key}";
        foreach ($this->rejected as $path => $why) {
            $out[] = "注入键 {$path} " . ($why === 'structure' ? '指向数组槽位' : '未在 app.php 声明');
        }
        return $out;
    }

    /** 严格校验：问题非空即显式抛出，不再静默降级；供运维 / 守卫 / 启动自检调用。 */
    public function assertIntegrity(): void
    {
        $problems = $this->issues();
        if ($problems !== []) throw new \RuntimeException('配置完整性校验失败：' . implode('; ', $problems));
    }

    /** 只读诊断：注入是否生效、声明规模、信任根是否就绪；绝不返回任何值，避免凭据进日志。 */
    public function envReport(): array
    {
        return [
            'applied'      => $this->envApplied,
            'rejected'     => $this->rejected,
            'declared'     => count($this->declared),
            'integrity_ok' => $this->issues() === [],
            'dotenv'       => $this->dotenvLoaded,
            'signature_ok' => (string) $this->get('lifecycle.signature_secret', '') !== '',
        ];
    }

    public function all(): array { return $this->data; }
    public function root(): string { return $this->root; }
}
