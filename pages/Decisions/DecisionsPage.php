<?php
declare(strict_types=1);
namespace Wx\Pages\Decisions;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 决策轨迹页 —— 解释「这一次请求为什么被允许」。
 *
 * 只读：现场裁决走 permission.check，不写库、不改授权，因此不需要表单票据。
 * 页面声明 permission.audit.read（看留痕）而不是 permission.read（看授权）：
 * 决策链会带出 actor 与资源，是审计信息。
 *
 * 页面不查库：permission.decisions 给的是进程内快照，历史在 permission_audit 表里，
 * 这一页如实说明边界，不假装自己是审计日志。
 */
final class DecisionsPage implements PackageInterface
{
    private const PATH = '/console/decisions';
    private const PERMISSION = 'permission.audit.read';

    public function slug(): string { return 'decisions'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            $identity = ConsoleTicket::identity($kernel, $request);
            $query = $request->query();
            $data = DecisionsQuery::fetch($kernel, $query, $identity);

            $response->setStatus(200)->setLayout('admin')->setPayload([
                'title'  => '决策轨迹',
                'page'   => 'decisions',
                'view'   => 'decisions',
                'state'  => null,
                'blocks' => DecisionsBlocks::build($data, self::hint($query)),
            ]);
        }, 10);
    }

    /** 动作名过长直接提示：这类输入不会命中任何权限点，跑一次裁决只会浪费一次调用。 */
    private static function hint(array $query): string
    {
        $action = trim((string) ($query['action'] ?? ''));
        if ($action === '') return '';
        if (strlen($action) > 120) return '动作名超过 120 字节，裁决结果没有意义，请检查是否粘贴错了。';
        return '';
    }
}
