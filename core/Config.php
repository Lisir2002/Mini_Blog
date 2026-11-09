<?php
declare(strict_types=1);
namespace Wx\Core;

/** 配置读取器。核心不解释配置项的含义，含义由各系统层自己定义。 */
final class Config
{
    private array $data;

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

        if ($override !== []) {
            $this->data = array_replace_recursive($this->data, $override);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cur = $this->data;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return $default;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }

    public function all(): array { return $this->data; }
    public function root(): string { return $this->root; }
}
