<?php
declare(strict_types=1);
namespace Wx\Systems\Theme;

use Wx\Contracts\ApiRegistryInterface;

/**
 * 主题系统自报接口 —— 读清单（公开）与改启用态（admin）。
 *
 * 接口归系统层，不归主题包：主题包是 HTML/CSS/JS 的唯一合法住所（规矩 J），
 * 它消费接口、不注册接口。这样后台 UI、安装向导、CLI 共用同一个事实源。
 */
final class ThemeOperations
{
    public function __construct(
        private ThemeCatalogInterface $catalog,
        private ThemeActivationInterface $activation
    ) {}

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('theme.list', function (array $in): array {
            $items = $this->catalog->all();
            return [
                'active'   => $this->catalog->active(),
                'headless' => $this->catalog->active() === null,
                'total'    => count($items),
                'items'    => $items,
            ];
        });

        $registry->registerOperation('theme.activate', function (array $in): array {
            $raw = $in['slug'] ?? '';
            $slug = ($raw === null || $raw === '' || $raw === 'null') ? null : (string) $raw;
            return $this->activation->activate($slug);
        });
    }
}
