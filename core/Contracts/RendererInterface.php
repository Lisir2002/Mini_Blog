<?php
declare(strict_types=1);
namespace Wx\Contracts;

use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 渲染契约 —— 全站唯一的「表现层入口」。
 * 只有主题包可以绑定它。核心不提供默认实现、不提供兜底模板。
 */
interface RendererInterface
{
    public function render(Response $response, Kernel $kernel): Response;
}
