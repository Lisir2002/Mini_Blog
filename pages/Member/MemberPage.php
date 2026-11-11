<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 成员成长页 —— 只读视图 + 五个写动作的操作台（v0.42 起）。
 *
 * 成功的写操作一律 303 回到本页：既不因刷新重复提交，也让管理员立刻看到
 * 改完之后的等级表 / 规则 / 榜单。**失败绝不 303** —— 表单里的输入要原样留着，
 * 否则一次手误就得把二十个字段重新填一遍。
 *
 * 页面只声明 member.read；五个写动作各自在 MemberAction 里再问一次写权限点。
 * 票据按页面级的 member.read 签发，写权限不写进票据 —— 权限每次现场裁决。
 */
final class MemberPage implements PackageInterface
{
    private const PATH = '/console/member';
    private const PERMISSION = 'member.read';

    public function slug(): string { return 'member'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            $identity = ConsoleTicket::identity($kernel, $request);

            if ($request->method() === 'POST') {
                $this->submit($kernel, $request, $response, $identity);
                return;
            }
            if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
                $response->setStatus(405)->setHeader('Allow', 'GET, HEAD, POST')->setPayload([
                    'title' => '方法不支持', 'page' => 'member', 'view' => 'member',
                    'state' => 'deny', 'blocks' => [[
                        'type' => 'notice', 'id' => 'member-method', 'tone' => 'warn',
                        'state' => 'denied', 'title' => '这个页面只接受 GET 与 POST',
                        'value' => '查看走查询参数，改动走带票据的表单提交；其余方法没有对应语义。',
                    ]],
                ]);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, []);
        }, 10);
    }

    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $result = MemberAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $query = [
                'saved'   => (string) ($result['action'] ?? ''),
                'op'      => (string) ($result['op'] ?? ''),
                'key'     => (string) ($result['key'] ?? ''),
                'outcome' => (string) ($result['outcome'] ?? ''),
                'changed' => ($result['changed'] ?? false) ? '1' : '0',
                'detail'  => (string) ($result['detail'] ?? ''),
                'hint'    => (string) ($result['hint'] ?? ''),
                'panel'   => (string) ($result['action'] ?? ''),
            ];
            foreach (['user', 'offset'] as $keep) {
                $value = (string) ($request->query()[$keep] ?? '');
                if ($value !== '') $query[$keep] = $value;
            }
            // 不把表单正文带回 URL：票据、备注、经验值都不该留在地址栏里。
            $response->setStatus(303)->setHeader('Location', self::PATH . '?' . http_build_query($query));
            return;
        }
        $this->view($kernel, $request, $response, $identity,
            (int) ($result['status'] ?? 422), $result, $request->body());
    }

    /**
     * @param array<string, mixed>|null $identity
     * @param array<string, mixed>|null $error
     * @param array<string, mixed> $post
     */
    private function view(Kernel $kernel, Request $request, Response $response, ?array $identity,
        int $status, ?array $error, array $post): void
    {
        $query = $request->query();
        $panel = (string) ($query['panel'] ?? 'award');
        if ($error !== null) $panel = (string) ($post['action'] ?? $panel);

        $data = MemberQuery::fetch($kernel, $identity, $query);
        $data['panel'] = $panel;
        $data['ticket'] = ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $data['post'] = $post;
        $data['saved'] = $query;
        // MemberQuery::fail() 已经把查询错误文案放在 data['error']（字符串）里；
        // 只有写失败才用错误对象覆盖，否则「offset 越界」会显示成「未知原因」。
        if ($error !== null) $data['error'] = $error;

        // 查询参数错时 MemberQuery 自己给出 4xx；写失败时错误对象带状态。
        // 两者不能塌成同一个 200，否则「offset 非法」会看起来像一次正常渲染。
        $httpStatus = $error !== null ? $status : (int) ($data['status'] ?? $status);
        $response->setStatus($httpStatus)->setLayout('admin')->setPayload([
            'title' => '成员成长', 'page' => 'member', 'view' => 'member',
            'state' => ($data['ok'] ?? false) === true ? null : 'error',
            'blocks' => MemberBlocks::build($data),
        ]);
    }
}
