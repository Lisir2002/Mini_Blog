<?php
declare(strict_types=1);
namespace Wx\Systems\Lifecycle;

use Wx\Core\HookBus;

/**
 * 启动期完整性门禁 —— F6 收口，承载包装载前的最后一道裁决。
 *
 * 落点：package.enabled 过滤钩子（PackageLoader 在 new $class() / class_exists
 * 之前调用），priority 20 排在装卸系统状态裁决（priority 10）之后。core/ 24 文件
 * 零改动，也不改装配器：新增的只是系统层一个订阅者 + 台账里的落位基线。
 *
 * 三态判定（诚实分层，不假装验过）：
 *   - managed：台账登记了落位基线 → 重算目录载荷摘要，与基线逐字比对；
 *     不符即拒绝装载并广播 package.integrity.rejected（fail-closed）。
 *   - unmanaged：出厂包 / 历史包无基线（随代码仓库分发，信任根是部署完整性，
 *     不是包签名）→ 放行，广播 package.integrity.unmanaged 如实留痕，绝不冒充已验。
 *   - disabled：config.lifecycle.boot_integrity=false 显式关闭（应急），广播一次。
 *
 * 为什么只对「有基线的包」复验：出厂包（themes / plugins / pages / systems）
 * 全部不带 manifest.sha256 / signature.json，一刀切强制会让整站启动即崩。
 * 基线只在 lifecycle 的 install / upgrade / rollback 成功落位后写入，恰好把
 * 「经发版链路进入的包」与「随仓库出厂的包」分开——前者才需要运行时防篡改。
 *
 * 边界（如实标注，不夸大）：
 *   - 基线存于站点可写的 storage/lifecycle/state.json。secret 非空时基线带
 *     HMAC，可挡「只改台账不改包」；secret 为空时只做 SHA-256 比对，能挡
 *     「改包不改台账」，但挡不住同时改两者的攻击者（需外置信任根，见生产化方向）。
 *   - 只比对载荷文件摘要；包内自带 manifest / signature 由 hashDir 排除，
 *     因为那两份文件会随包一起被改写，不构成可信锚。
 *   - 进程内按 type:slug 缓存判定，一次请求同一包只算一次；跨请求不缓存
 *     （PHP 无共享态），故每请求对受管包做一次 hashDir——包体小，开销可控。
 */
final class BootIntegrity
{
    /** @var array<string, bool> */
    private array $verdict = [];

    /** @var array<string, bool> */
    private array $announced = [];

    /**
     * @param array<string, mixed> $options    config.lifecycle
     * @param array<string, array<string, mixed>> $records 台账 units 快照（key => record）
     */
    public function __construct(
        private string $root,
        private HookBus $hooks,
        private array $options,
        private array $records
    ) {}

    public function role(): string { return 'boot_integrity'; }

    /** package.enabled 订阅者：前置裁决已拒绝则直接短路，不再做无谓的哈希。 */
    public function guard(bool $allowed, string $slug, string $manifest): bool
    {
        if (!$allowed) return false;
        if (($this->options['boot_integrity'] ?? true) !== true) {
            $this->announceOnce('disabled', '启动期完整性复验已显式关闭（boot_integrity=false）');
            return true;
        }
        $type = match ($manifest) {
            'theme.json'  => 'theme',
            'plugin.json' => 'plugin',
            'page.json'   => 'page',
            default       => null,
        };
        if ($type === null) return true; // 非承载包清单，不属本门禁职责
        $key = $type . ':' . $slug;
        if (isset($this->verdict[$key])) return $this->verdict[$key];
        return $this->verdict[$key] = $this->decide($key, (array) ($this->records[$key] ?? []));
    }

    /** @param array<string, mixed> $record */
    private function decide(string $key, array $record): bool
    {
        $this->hooks->emit('lifecycle.collab', $this->role(), 'decide');
        $baseline = $record['integrity'] ?? null;
        if (!is_array($baseline) || ($baseline['digest'] ?? '') === '') {
            $this->announceOnce('unmanaged:' . $key, '无落位基线（出厂 / 历史包），放行但不标记为已验');
            $this->hooks->emit('package.integrity.unmanaged', $key);
            return true;
        }

        $rel = (string) ($record['path'] ?? '');
        $dir = $rel === '' ? '' : $this->root . '/' . $rel;
        if ($dir === '' || !is_dir($dir)) {
            // 台账说有基线、目录却不在了：交给 PackageLoader 自己的类缺失路径处理，
            // 但这是异常态，必须留痕，不能静默当作通过。
            $this->hooks->emit('package.integrity.rejected', $key, 'missing_dir', $dir);
            return false;
        }

        $secret = (string) ($this->options['signature_secret'] ?? '');
        $fresh  = Fs::baseline($dir, $secret);
        $reason = '';
        if (!hash_equals((string) $baseline['digest'], $fresh['digest'])) {
            $reason = 'digest_mismatch';
        } elseif (isset($baseline['mac'])
            && !hash_equals((string) $baseline['mac'], (string) ($fresh['mac'] ?? ''))) {
            $reason = 'baseline_mac_mismatch';
        } elseif (!isset($baseline['mac']) && $secret !== '') {
            // 站点现在配了密钥，基线却是无密钥时代写的：不假装它受 HMAC 保护。
            $reason = 'baseline_unsigned';
        }

        if ($reason !== '') {
            $this->hooks->emit('package.integrity.rejected', $key, $reason, $dir);
            return false;
        }
        $this->hooks->emit('package.integrity.verified', $key, $fresh['files']);
        return true;
    }

    private function announceOnce(string $tag, string $message): void
    {
        if (isset($this->announced[$tag])) return;
        $this->announced[$tag] = true;
        $this->hooks->emit('kernel.trace', $message, ['source' => 'boot_integrity']);
    }
}
