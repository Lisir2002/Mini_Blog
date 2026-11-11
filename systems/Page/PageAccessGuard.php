<?php
declare(strict_types=1);
namespace Wx\Systems\Page;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiAuthenticatorInterface;
use Wx\Core\Http\Request;
use Wx\Core\Http\Response;
use Wx\Core\Kernel;

/**
 * 页面访问守卫 —— 与 API 的 RequestGuard 共用同一套身份与授权契约。
 *
 * 两级裁决：ApiAuthenticatorInterface::allows() 判「你是谁」，
 * AccessControlInterface::allows() 判「你能不能访问这个页面」。
 * 与 API 完全同源：同一个认证器、同一个权限决策器、同一套 fail-closed 语义。
 *
 * 拒绝时只产结构化数据 + deny 布局槽位，绝不输出 HTML / CSS / JS（规矩 J）：
 * 主题有 deny 布局则由主题渲染，没有则核心退化为纯 JSON。
 */
final class PageAccessGuard
{
    /** @var array<int, array<string, mixed>|null> 请求对象 id → 身份，同一次请求只认证一次 */
    private array $identityByRequest = [];

    public function __construct(private Kernel $kernel, private ?FormTicket $ticket = null) {}

    public function role(): string { return 'page_access_guard'; }

    /** route.access 阶段的入口；放行原样返回，拒绝则写入 401 / 403 的结构化响应 */
    public function guard(Response $response, Request $request, PagePolicy $policy): Response
    {
        $rule   = $policy->forPath($request->path());
        $result = $this->decide($request, $rule);
        $this->identityByRequest[spl_object_id($request)] = $result['identity'];

        if ($rule !== null) {
            // 每次页面访问裁决都留痕，交由内核可观测性出口（日志系统消费）
            $this->kernel->trace('页面访问裁决', [
                'path'       => $request->path(),
                'page'       => $rule['page'],
                'auth'       => $rule['auth'],
                'permission' => $rule['permission'],
                'reason'     => $result['reason'],
                'allowed'    => $result['allowed'],
                'actor'      => (string) ($result['identity']['username'] ?? 'anonymous'),
            ]);
        }
        return $result['allowed'] ? $response : $this->deny($response, $request, $result);
    }

    /** 供 route.response 把身份注入页面数据；独立页本身不认识任何令牌 */
    public function identityFor(Request $request): ?array
    {
        return $this->identityByRequest[spl_object_id($request)] ?? null;
    }

    /** @return array{allowed:bool, identity:array<string,mixed>|null, reason:string, rule:array<string,mixed>|null} */
    private function decide(Request $request, ?array $rule): array
    {
        $identity = $this->identity($request);
        if ($rule === null) {
            // 未在页面清单声明的路径（API / 404 / 插件自有路由）不归页面系统裁决
            return ['allowed' => true, 'identity' => $identity, 'reason' => 'not_a_page', 'rule' => null];
        }

        // 表单票据：非 GET 才启用。Cookie 不参与非 GET（CSRF 边界不变），
        // 身份改由「GET 时签发的、绑定路径与权限的短期票据」证明。
        // 注意必须用工方法判定，而不是「声明了 form_ticket 就算尝试过」：
        // 否则匿名 GET 会被判成「票据失效」，把「请先登录」错说成「表单过期」。
        $ticketTried = false;
        if ($identity === null && $rule['form_ticket'] !== ''
            && !in_array($request->method(), ['GET', 'HEAD'], true)) {
            $ticketTried = true;
            $identity = $this->ticketIdentity($request, $rule);
        }

        // 1) 身份级别：复用 API 的同一个 allows()
        $level = (string) $rule['auth'];
        if ($level !== 'public') {
            if ($identity === null) {
                return $this->no($identity, $ticketTried ? 'ticket_invalid' : 'unauthenticated', $rule);
            }
            $auth = $this->authenticator();
            if ($auth === null || !$auth->allows($level, $identity)) {
                return $this->no($identity, 'level_denied', $rule);
            }
        }

        // 2) 本机来源：安装向导这类敏感页不暴露公网
        if ($rule['local_only'] && !$request->isLocal()) {
            return $this->no($identity, 'local_only', $rule);
        }

        // 3) 细粒度授权：权限层缺席 / abstain 一律 fail-closed
        foreach ([(string) $rule['permission'], (string) $rule['extra_permission']] as $permission) {
            if ($permission === '') continue;
            $acl = $this->accessControl();
            if ($acl === null || !$acl->allows($identity, $permission)) {
                return $this->no($identity, 'permission_denied', $rule);
            }
        }
        return ['allowed' => true, 'identity' => $identity, 'reason' => 'allowed', 'rule' => $rule];
    }

    /** @param array<string, mixed>|null $identity @param array<string, mixed> $rule */
    private function no(?array $identity, string $reason, array $rule): array
    {
        return ['allowed' => false, 'identity' => $identity, 'reason' => $reason, 'rule' => $rule];
    }

    /** @param array{allowed:bool, identity:array<string,mixed>|null, reason:string, rule:array<string,mixed>|null} $result */
    private function deny(Response $response, Request $request, array $result): Response
    {
        $rule   = (array) $result['rule'];
        $reason = (string) $result['reason'];
        // 未认证 / 票据失效统一 401，其余（级别不足 / 非本机 / 权限未放行）统一 403
        $status = in_array($reason, ['unauthenticated', 'ticket_invalid'], true) ? 401 : 403;
        $labels = [
            'unauthenticated'   => '该页面需要登录。请先登录；脚本可携带站点签发的 Bearer 令牌（Authorization 请求头或 wx_token Cookie）。',
            'ticket_invalid'    => '表单已过期或不属于本页：票据有效期 20 分钟，且只能用于签发它的路径。请刷新页面后重新提交。',
            'level_denied'      => '当前身份级别不足以访问该页面。',
            'local_only'        => '该页面仅允许本机来源访问。',
            'permission_denied' => '权限决策未放行（fail-closed）：缺少所需权限点。',
        ];
        $permission = (string) $rule['permission'];
        $title = $reason === 'ticket_invalid' ? '表单已失效' : ($status === 401 ? '需要登录' : '无权访问');

        return $response
            ->setStatus($status)
            ->setLayout((string) $rule['deny_layout'])
            ->setPayload([
                'title'          => $title,
                'page'           => 'access_denied',
                'status'         => $status,
                'reason'         => $reason,
                'auth_level'     => (string) $rule['auth'],
                'permission'     => $permission,
                'login_endpoint' => (string) $this->kernel->config()->get('api.prefix', '/api/v1') . '/auth/token',
                'session_endpoint' => (string) $this->kernel->config()->get('api.prefix', '/api/v1') . '/auth/session',
                'return_to'      => $request->path(),
                'identity'       => $result['identity'],
                'blocks'         => [
                    ['type' => 'text', 'title' => '访问被拒绝',
                     'value' => (string) ($labels[$reason] ?? '访问被拒绝')],
                    ['type' => 'table', 'title' => '本次裁决', 'rows' => [
                        ['k' => '请求路径', 'v' => $request->path(), 'ok' => false],
                        ['k' => '要求身份', 'v' => (string) $rule['auth']],
                        ['k' => '要求权限', 'v' => $permission !== '' ? $permission : '无'],
                        ['k' => '裁决原因', 'v' => $reason],
                    ]],
                    // 只给「去哪里登录」，不渲染一个没有 action 的表单：
                    // 旧版这里是一个点不动的假登录框，比没有更误导。
                    ['type' => 'links', 'title' => '登录入口', 'items' => [
                        ['label' => '去登录', 'href' => '/login?next='
                            . rawurlencode($request->path()), 'note' => '登录后回到本页'],
                        ['label' => '注册', 'href' => '/register', 'note' => '站点开放注册时可用'],
                    ]],                ],
            ]);
    }

    /**
     * 只对非 GET 生效：GET 的身份来自 Cookie / 请求头，不需要票据。
     * 票据里的权限点必须与清单声明的逐字一致 —— 这是「票据不能跨页挪用」的关键，
     * 光靠路径绑定不够：同一路径下未来可能追加不同权限的操作。
     * 返回的 role 是签发时的快照，因此角色被降级后最迟 TTL 生效；权限点仍每次现场判定。
     * @param array<string, mixed> $rule
     * @return array<string, mixed>|null
     */
    private function ticketIdentity(Request $request, array $rule): ?array
    {
        if (in_array($request->method(), ['GET', 'HEAD'], true)) return null;
        if ($this->ticket === null) return null;

        // 验签、路径绑定、权限点比对都在票据内部完成：守卫只负责从请求里取字符串，
        // 不必认识 HMAC 细节，也不会出现「守卫与票据各判一半」的偏差。
        return $this->ticket->identityFor(
            (string) ($request->body()['_form_ticket'] ?? ''), (string) $rule['permission'], $request->path()
        );
    }

    private function identity(Request $request): ?array
    {
        $token = $this->token($request);
        if ($token === '') return null;
        $auth = $this->authenticator();
        return $auth === null ? null : $auth->authenticate($token);
    }

    /** 身份载体与 API 同源；Cookie 只为浏览器直访后台提供便利，不引入第二套认证算法 */
    private function token(Request $request): string
    {
        $bearer = (string) ($request->header('Authorization') ?? '');
        if (stripos($bearer, 'Bearer ') === 0) return trim(substr($bearer, 7));
        $alt = trim((string) ($request->header('X-Api-Token') ?? ''));
        if ($alt !== '') return $alt;

        // Cookie 只用于页面读取：写操作必须用请求头令牌，浏览器跨站无法自动附加
        // Authorization，CSRF 面因此天然关闭（v0.9 页面会话）。
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) return '';

        $cookie = (string) ($request->header('Cookie') ?? '');
        if ($cookie === '') return '';
        // 逐段解析而不是正则：这里的输入是不可信请求头，行数少、行为更容易穷举；
        // 同时让本类的职权域停在 http + security 两域（守卫自己也要守职责预算）。
        foreach (explode(';', $cookie) as $piece) {
            $pair = explode('=', trim($piece), 2);
            if (count($pair) !== 2) continue;
            if (!in_array($pair[0], ['wx_session', 'wx_token'], true)) continue;
            $value = rawurldecode(trim($pair[1]));
            if ($value !== '') return $value;
        }
        return '';
    }

    private function authenticator(): ?ApiAuthenticatorInterface
    {
        $c = $this->kernel->container();
        return $c->has(ApiAuthenticatorInterface::class)
            ? $c->resolve(ApiAuthenticatorInterface::class) : null;
    }

    private function accessControl(): ?AccessControlInterface
    {
        $c = $this->kernel->container();
        return $c->has(AccessControlInterface::class)
            ? $c->resolve(AccessControlInterface::class) : null;
    }
}
