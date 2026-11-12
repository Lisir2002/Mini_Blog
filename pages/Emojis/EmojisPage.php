<?php
declare(strict_types=1);
namespace Wx\Pages\Emojis;

use Wx\Contracts\PackageInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ConsoleTicket;

/**
 * 表情目录（v0.46）—— 只产结构化数据，标签与样式全归主题的 admin 布局。
 *
 * 成功 303 回到同一类型筛选并回报真实结果，失败绝不跳转且回填。
 * 页面声明 content.read；登记与删除现场问 emoji.manage，票据只绑页面级权限点，
 * 不替代写裁决。GET 签发票据、POST 由守卫验票，页面自身不碰 Cookie、不自造身份。
 */
final class EmojisPage implements PackageInterface
{
    private const PATH = '/console/emojis';
    private const PERMISSION = 'content.read';

    public function slug(): string { return 'emojis'; }

    public function register(Kernel $kernel): void
    {
        $kernel->hooks()->on('route.resolve', function (Request $request, Response $response) use ($kernel): void {
            if ($request->path() !== self::PATH) return;
            $method = $request->method();
            if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
                $response->setStatus(405)->setHeader('Allow', 'GET, HEAD, POST')->setLayout('admin')
                    ->setPayload(['title' => '方法不支持', 'page' => 'emojis', 'view' => 'emojis',
                        'blocks' => [[
                            'type' => 'notice', 'id' => 'emojis-method', 'tone' => 'warn',
                            'state' => 'denied', 'title' => '这个页面只接受 GET 与 POST',
                            'value' => '切换类型走查询参数，登记 / 删除走带票据的表单提交。',
                        ]]]);
                return;
            }
            $identity = ConsoleTicket::identity($kernel, $request);
            if ($method === 'POST') {
                $this->submit($kernel, $request, $response, $identity);
                return;
            }
            $this->view($kernel, $request, $response, $identity, 200, null, []);
        }, 10);
    }

    /** @param array<string, mixed>|null $identity */
    private function submit(Kernel $kernel, Request $request, Response $response, ?array $identity): void
    {
        $result = EmojisAction::run($kernel, $request, $identity);
        if (($result['ok'] ?? false) === true) {
            $query = [
                'saved'   => '1',
                'op'      => (string) ($result['op'] ?? ''),
                'outcome' => (string) ($result['outcome'] ?? ''),
                'changed' => ($result['changed'] ?? false) ? '1' : '0',
                'detail'  => (string) ($result['detail'] ?? ''),
                'hint'    => (string) ($result['hint'] ?? ''),
            ];
            $kind = (string) ($request->body()['kind'] ?? $request->query()['kind'] ?? 'any');
            if (in_array($kind, EmojisQuery::KINDS, true)) $query['kind'] = $kind;
            // PRG：刷新不会重复登记 / 删除，地址栏也不会停在 POST 结果上。
            $response->setStatus(303)->setHeader('Location', self::PATH . '?' . http_build_query($query));
            return;
        }
        $this->view($kernel, $request, $response, $identity,
            (int) ($result['status'] ?? 422), $result, $request->body());
    }

    /** @param array<string, mixed>|null $identity @param array<string, mixed>|null $failed
     *  @param array<string, mixed> $form */
    private function view(Kernel $kernel, Request $request, Response $response, ?array $identity,
        int $status, ?array $failed, array $form): void
    {
        $query = $request->query();
        $data = EmojisQuery::fetch($kernel, $identity, $query);
        $data['ticket'] = ConsoleTicket::issue($kernel, $request, self::PERMISSION, self::PATH);
        $data['form'] = $form;
        $data['failed'] = $failed;
        $data['saved'] = (string) ($query['saved'] ?? '') === '1' ? $query : null;

        // 失败态必须自己固定 admin 布局：4xx 会被 render.layout 跳过路径映射，
        // 否则会被默认 site 槽位渲染成「带门户导航的空页面」，错误说明一个字看不到。
        $httpStatus = $failed !== null ? $status : (int) ($data['status'] ?? $status);
        // 失败详情由 EmojisNotice 区块完整回显；这里只留标量标记，避免主题把同一句话说两遍。
        $errorState = $failed !== null || (string) ($data['error'] ?? '') !== '';
        $response->setStatus($httpStatus)->setLayout('admin')->setPayload([
            'title'       => '表情目录',
            'page'        => 'emojis',
            'view'        => 'emojis',
            'state'       => $errorState ? 'error' : null,
            'blocks'      => EmojisBlocks::build($data),
            'form_ticket' => (string) $data['ticket'],
        ]);
    }
}
