<?php
declare(strict_types=1);
namespace Wx\Systems\Theme;

use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 本地覆盖层写入 —— 只写 config/local.php，不碰带注释的主配置。
 *
 * core/Config 早已支持 local.php 覆盖：主配置 theme.active 恒为 null（Headless 根基），
 * 删掉 local.php 即回到默认，天然具备回退路径。写入是临时文件 + rename 的原子替换，
 * 失败时原文件不动；写入前经 package.enabled 询问装卸系统准入，避免切到被停用的
 * 主题后整站静默退化成纯数据。
 */
final class LocalThemeActivation implements ThemeActivationInterface
{
    private const EFFECTIVE = 'next_request';

    public function __construct(
        private Kernel $kernel,
        private ThemeCatalogInterface $catalog
    ) {}

    public function activate(?string $slug): array
    {
        $previous = $this->catalog->active();
        $target = ($slug === null || $slug === '') ? null : $slug;

        if ($target !== null) {
            if (!$this->catalog->has($target)) {
                throw new ApiOperationException('主题不存在或未启用：' . $target, 40403);
            }
            // 装配准入归装卸系统裁决；这里只询问既有钩子，不硬依赖 lifecycle 层。
            $allowed = (bool) $this->kernel->hooks()->apply(
                'package.enabled', true, $target, 'theme.json', $this->kernel
            );
            if (!$allowed) {
                throw new ApiOperationException('该主题已被装卸系统停用，请先启用后再切换', 40901);
            }
        }

        if ($target === $previous) {
            return ['active' => $previous, 'previous' => $previous,
                    'changed' => false, 'effective' => self::EFFECTIVE];
        }

        $this->writeLocal($target);
        $this->kernel->trace('主题启用态已变更', [
            'from' => $previous, 'to' => $target, 'effective' => self::EFFECTIVE,
        ]);
        $this->kernel->hooks()->emit('theme.activated', $previous, $target, $this->kernel);

        return ['active' => $target, 'previous' => $previous,
                'changed' => true, 'effective' => self::EFFECTIVE];
    }

    private function writeLocal(?string $slug): void
    {
        $dir  = $this->kernel->root() . '/config';
        $file = $dir . '/local.php';
        $existing = is_file($file) ? require $file : [];
        if (!is_array($existing)) $existing = [];

        if ($slug === null) {
            unset($existing['theme']['active']);
            if (($existing['theme'] ?? null) === []) unset($existing['theme']);
        } else {
            $existing['theme']['active'] = $slug;
        }

        $body = "<?php\n// 由 theme.activate 维护；删除本文件即回到 config/app.php 的默认（Headless）。\n"
              . 'return ' . var_export($existing, true) . ";\n";
        $tmp = $dir . '/.local-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
            throw new ApiOperationException('本地覆盖层写入失败', 50001);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new ApiOperationException('本地覆盖层替换失败', 50001);
        }
    }
}
