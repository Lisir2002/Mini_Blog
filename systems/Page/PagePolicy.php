<?php
declare(strict_types=1);
namespace Wx\Systems\Page;

use Wx\Core\Kernel;

/**
 * 页面访问策略表 —— 把「谁能访问哪个页面」变成清单数据，
 * 而不是散落在每个独立页里的 if。
 *
 * 缺省 fail-closed：页面声明了 routes 却没写 access，按 auth=user 处理并暴露告警，
 * 公开页必须显式声明 public。可编程裁决点 page.access.policy 只允许收紧，不能放宽。
 */
final class PagePolicy
{
    /** 与 ApiAuthenticatorInterface::allows() 的级别语义一致，数值用于比较严格程度 */
    private const LEVEL = ['public' => 0, 'user' => 1, 'admin' => 2];

    /** @var array<string, array{page:string,auth:string,permission:string,extra_permission:string,local_only:bool,deny_layout:string}> */
    private array $byPath = [];
    /** @var list<string> */
    private array $undeclared = [];

    public function __construct(private Kernel $kernel) {}

    public function role(): string { return 'page_policy'; }

    /** @param array<string, mixed> $meta 独立页清单 */
    public function add(string $slug, array $meta): void
    {
        foreach ((array) ($meta['routes'] ?? []) as $path) {
            $raw = (array) ($meta['access'] ?? []);
            if ($raw === []) {
                $this->undeclared[] = $slug;
                $raw = ['auth' => 'user'];
            }
            $this->byPath[(string) $path] = [
                'page'             => $slug,
                'auth'             => $this->level((string) ($raw['auth'] ?? 'user')),
                'permission'       => (string) ($raw['permission'] ?? ''),
                'extra_permission' => '',
                'local_only'       => (bool) ($raw['local_only'] ?? false),
                'deny_layout'      => (string) ($raw['deny_layout'] ?? 'deny'),
            ];
        }
    }

    /** @return list<string> 未声明 access 的页面（可观测告警，不静默放行） */
    public function undeclared(): array { return array_values(array_unique($this->undeclared)); }

    /** @return list<string> */
    public function paths(): array { return array_keys($this->byPath); }

    /** 清单策略 + 可编程收紧；表里没有的路径返回 null —— 页面系统不裁决 API / 404 */
    public function forPath(string $path): ?array
    {
        $base = $this->byPath[$path] ?? null;
        if ($base === null) return null;
        $candidate = (array) $this->kernel->hooks()->apply(
            'page.access.policy', $base, $path, $this->kernel
        );
        return $this->tighten($base, $candidate);
    }

    /** 只允许更严：提升 auth、追加 extra_permission、打开 local_only。放宽一律忽略。 */
    private function tighten(array $base, array $candidate): array
    {
        $out  = $base;
        $want = (string) ($candidate['auth'] ?? $base['auth']);
        if (isset(self::LEVEL[$want], self::LEVEL[$base['auth']])
            && self::LEVEL[$want] > self::LEVEL[$base['auth']]) {
            $out['auth'] = $want;
        }
        $extra = (string) ($candidate['extra_permission'] ?? '');
        if ($extra !== '') $out['extra_permission'] = $extra;
        if (($candidate['local_only'] ?? false) === true) $out['local_only'] = true;
        return $out;
    }

    /** 未知 / 缺失级别一律归到 user（fail-closed），绝不因拼写错误变成 public */
    private function level(string $name): string
    {
        return isset(self::LEVEL[$name]) ? $name : 'user';
    }
}
