<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 成员成长页 —— 把「members.* 十个接口一个都没人调」变成看得见的一页。
 *
 * 这一版只读：等级表、头衔目录、经验规则、排行榜、单人档案。写操作（发经验 / 授头衔 /
 * 改等级与规则）留给下一版 —— 它们各自有「重复提交」「没配规则必须显式 amount」
 * 这类需要单独决策的问题，塞进同一版只会让每条都做不干净。
 *
 * 只读页仍需要「身份」：经验规则要按权限裁剪，档案查询要知道你是谁。
 * 但写方法一律不接 —— 带 Cookie 的 POST 在守卫层就是 401，带 Bearer 才是 405。
 */
final class MemberPage implements PackageInterface
{
    private const PATH = '/console/member';

    public function slug(): string { return 'member'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
                $response->setStatus(405)->setHeader('Allow', 'GET, HEAD')->setPayload([
                    'title' => '只读页面', 'page' => 'member', 'view' => 'member',
                    'state' => 'deny', 'blocks' => [[
                        'type' => 'notice', 'id' => 'member-method', 'tone' => 'warn',
                        'state' => 'denied', 'title' => '这个页面不接受提交',
                        'value' => '成长视图只读：翻页与查人走查询参数，没有可提交的表单。',
                    ]],
                ]);
                return;
            }

            $data = MemberQuery::fetch($kernel, ConsoleTicket::identity($kernel, $request), $request->query());
            $response->setStatus((int) ($data['status'] ?? 200))->setLayout('admin')->setPayload([
                'title' => '成员成长', 'page' => 'member', 'view' => 'member',
                'state' => ($data['ok'] ?? false) === true ? null : 'error',
                'blocks' => MemberBlocks::build($data),
            ]);
        }, 10);
    }
}
