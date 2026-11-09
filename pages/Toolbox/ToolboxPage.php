<?php
declare(strict_types=1);
namespace Wx\Pages\Toolbox;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/** 工具独立页：只产出数据与 tool 布局意图，交互与外观全部由主题包提供。 */
final class ToolboxPage implements PackageInterface
{
    public function slug(): string { return 'toolbox'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', static function (Request $request, Response $response): void {
            if ($request->path() !== '/toolbox') return;
            $response->setPayload([
                'title'  => '文本小工具',
                'page'   => 'toolbox',
                'blocks' => [
                    ['type' => 'form', 'title' => '文本转换', 'fields' => [
                        ['tag' => 'textarea', 'id' => 'tool-input', 'placeholder' => '粘贴文本…'],
                        ['tag' => 'button', 'id' => 'tool-upper', 'label' => '转大写'],
                        ['tag' => 'button', 'id' => 'tool-lower', 'label' => '转小写'],
                        ['tag' => 'button', 'id' => 'tool-clear', 'label' => '清空'],
                    ]],
                    ['type' => 'pre', 'title' => '结果', 'id' => 'tool-out', 'value' => ''],
                ],
            ]);
        }, 10);
    }
}
