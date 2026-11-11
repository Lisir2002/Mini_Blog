<?php
declare(strict_types=1);
namespace Wx\Pages\Logs;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 日志页 —— 只读运维视图，消费 logs.query。
 *
 * 只有一个读取接口，没有表单票据：这一页不写任何东西。
 * 但「只读」不等于「总是 200」：查询条件写错是 422，日志读不到是 503，
 * 两者都渲染成可读页面，而不是让人对着一张空白页猜。
 */
final class LogsPage implements PackageInterface
{
    private const PATH = '/console/logs';

    public function slug(): string { return 'logs'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
                $response->setStatus(405)->setHeader('Allow', 'GET, HEAD')->setPayload([
                    'title' => '只读页面', 'page' => 'logs', 'view' => 'logs',
                    'state' => 'deny', 'blocks' => [[
                        'type' => 'notice', 'id' => 'log-method', 'tone' => 'warn',
                        'state' => 'denied', 'title' => '这个页面不接受提交',
                        'value' => '日志是只读视图：筛选走查询参数，没有可提交的表单。',
                    ]],
                ]);
                return;
            }

            $data = LogsQuery::fetch($kernel, $request->query());
            $response->setStatus((int) ($data['status'] ?? 200))->setLayout('admin')->setPayload([
                'title' => '系统日志', 'page' => 'logs', 'view' => 'logs',
                'state' => ($data['ok'] ?? false) === true ? null : 'error',
                'blocks' => LogsBlocks::build($data),
            ]);
        }, 10);
    }
}
