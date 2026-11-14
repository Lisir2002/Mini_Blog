<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 源包安全协作者 —— 路径边界、SHA-256 完整性清单、HMAC-SHA256 验签。
 * 纯原生 PHP（hash_hmac + hash_equals），不引入任何依赖。
 */
final class PackageVerifier
{
    /** @param array<string, mixed> $options */
    public function __construct(
        private string $root,
        private HookBus $hooks,
        private array $options = []
    ) {}

    public function role(): string { return 'package_verifier'; }

    /** 源包必须真实存在且位于站点根目录内，杜绝任意路径读写。 */
    public function assertSource(string $source): string
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'assert_source');
        $real = realpath($source);
        if ($real === false || !is_dir($real)) {
            throw new LifecycleException('源包目录不存在：' . $source, 42201);
        }
        if (!Fs::within($real, $this->root)) {
            throw new LifecycleException('安全拒绝：源包必须位于站点根目录内', 40301);
        }
        return $real;
    }

    public function assertIntegrity(string $dir): void
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'assert_integrity');
        $file = $dir . '/manifest.sha256';
        if (!is_file($file)) {
            if (($this->options['require_checksum'] ?? false) === true) {
                throw new LifecycleException('源包缺少 manifest.sha256 完整性清单', 42201);
            }
        } else {
            $expected = json_decode((string) file_get_contents($file), true);
            if (!is_array($expected)) {
                throw new LifecycleException('哈希清单格式错误：' . $file, 42201);
            }
            $actual = Fs::hashDir($dir);
            foreach ($expected as $rel => $hash) {
                if (($actual[$rel] ?? null) !== $hash) {
                    throw new LifecycleException('完整性校验失败：' . $rel, 42201);
                }
            }
            // 清单外文件：只遍历 expected 会漏掉「往源包里塞一个清单没登记的 PHP 文件」。
            // 源包此刻尚未执行 on_install 钩子，清单必须完整覆盖全部载荷文件，故严格拒绝。
            // （落位后的 verify() 不做此检测：运行期新增文件是合法的，见 BootIntegrity 边界说明。）
            $extra = array_values(array_diff(array_keys($actual), array_keys($expected)));
            if ($extra !== []) {
                throw new LifecycleException(
                    '完整性校验失败：清单外文件 ' . implode(', ', $extra), 42201
                );
            }
        }
        $this->assertSignature($dir);
    }

    /** 待签串是规范化后的「文件 → SHA-256」清单，因此任何文件改动都会让验签失败。 */
    public function assertSignature(string $dir): void
    {
        $file = $dir . '/signature.json';
        if (!is_file($file)) {
            if (($this->options['require_signature'] ?? false) === true) {
                throw new LifecycleException('源包缺少 signature.json 签名文件', 42201);
            }
            return;
        }
        $signature = json_decode((string) file_get_contents($file), true);
        if (!is_array($signature) || ($signature['algorithm'] ?? '') !== 'HMAC-SHA256') {
            throw new LifecycleException('不支持的签名算法，仅接受 HMAC-SHA256', 42201);
        }
        $secret = (string) ($this->options['signature_secret'] ?? '');
        if ($secret === '') {
            throw new LifecycleException('未配置 lifecycle.signature_secret，无法验签', 40301);
        }
        if (!hash_equals(hash_hmac('sha256', Fs::canonical($dir), $secret), (string) ($signature['signature'] ?? ''))) {
            throw new LifecycleException('签名校验失败：包内容可能被篡改', 42201);
        }
    }

    /** @return array<string, mixed> 完整性校验报告 */
    public function verify(string $dir): array
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'verify');
        $actual = Fs::hashDir($dir);
        $file = $dir . '/manifest.sha256';
        if (!is_file($file)) {
            return ['ok' => true, 'mode' => 'no-manifest', 'files' => count($actual),
                'note' => '该单元未提供哈希清单，仅统计文件摘要'];
        }
        $expected = json_decode((string) file_get_contents($file), true);
        if (!is_array($expected)) {
            throw new LifecycleException('哈希清单格式错误：' . $file, 42201);
        }
        $mismatch = [];
        foreach ($expected as $rel => $hash) {
            if (($actual[$rel] ?? null) !== $hash) $mismatch[] = (string) $rel;
        }
        return ['ok' => $mismatch === [], 'mode' => 'sha256', 'files' => count($actual),
            'checked' => count($expected), 'mismatch' => $mismatch];
    }
}
