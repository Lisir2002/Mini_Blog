<?php
declare(strict_types=1);
namespace Wx\Pages\Systems;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 系统状态页 —— 只读运维视图。
 *
 * 只读是刻意的：安装、升级、回滚、卸载会写文件并执行包内容，
 * 搬到网页上等于把 RCE 面挪进后台。这一页回答的是「现在是什么状态」，
 * 不提供「让它变成另一个状态」的按钮。
 *
 * 逐项取数、逐项降级：健康检查失败时版本与装配顺序仍然可见，
 * 否则「页面打不开」与「站点真的坏了」会变成同一种症状。
 */
final class SystemsPage implements PackageInterface
{
    private const PATH = '/console/system';
    private const PERMISSION = 'system.config.read';

    public function slug(): string { return 'systems'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            // 只读页：GET 之外的方法没有语义，明确拒绝，避免看起来像能提交。
            if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
                $response->setStatus(405)->setHeader('Allow', 'GET, HEAD')->setPayload([
                    'title' => '只读页面', 'page' => 'systems', 'view' => 'systems',
                    'state' => 'deny', 'blocks' => [[
                        'type' => 'notice', 'id' => 'sys-method', 'tone' => 'warn',
                        'state' => 'denied', 'title' => '这个页面不接受提交',
                        'value' => '系统状态是只读视图：安装、升级、回滚在命令行执行。',
                    ]],
                ]);
                return;
            }
            $data = SystemsQuery::fetch($kernel);
            $blocks = array_merge(SystemsBlocks::build($data), SystemsCatalog::build($data));
            $response->setStatus(200)->setLayout('admin')->setPayload([
                'title' => '系统状态', 'page' => 'systems', 'view' => 'systems',
                'state' => null, 'blocks' => $blocks,
            ]);
        }, 10);
    }
}
