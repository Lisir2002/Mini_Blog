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
 */
final class Config
{
    private array $data;
    /** @var list<string> 本次由 env 注入的点路径；值本身不留在内存清单里 */
    private array $envApplied = [];
    private bool  $dotenvLoaded = false;

    /** @param array<string, mixed> $override 运行期覆盖，用于多站点 / 嵌入 / 测试 */
    public function __construct(private string $root, array $override = [])
    {
        $file = $root . '/config/app.php';
        $loaded = is_file($file) ? require $file : [];
        $this->data = is_array($loaded) ? $loaded : [];

        // 安装器 / 运维只写 config/local.php：不覆盖带注释的主配置，删掉即回到默认。
        $local = $root . '/config/local.php';
        if (is_file($local)) {
            $localData = require $local;
            if (is_array($localData)) {
                $this->data = array_replace_recursive($this->data, $localData);
            }
        }

        foreach ($this->envPairs() as $path => $value) {
            $this->put($path, $value);
            $this->envApplied[] = $path;
        }

        if ($override !== []) {
            $this->data = array_replace_recursive($this->data, $override);
        }
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

    /** 只读诊断：注入是否生效、验签信任根是否就绪；绝不返回任何值，避免凭据进日志。 */
    public function envReport(): array
    {
        return [
            'applied'      => $this->envApplied,
            'dotenv'       => $this->dotenvLoaded,
            'signature_ok' => (string) $this->get('lifecycle.signature_secret', '') !== '',
        ];
    }

    public function all(): array { return $this->data; }
    public function root(): string { return $this->root; }
}
