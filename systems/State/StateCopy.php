<?php
declare(strict_types=1);
namespace Wx\Systems\State;

use Wx\Core\HookBus;

/**
 * 状态文案表 —— 核心只给「键 + 默认中文」，主题与插件经 state.copy 钩子覆写。
 *
 * 为什么文案不放在主题里：同一句「数据库连不上」如果每套主题各写一遍，
 * 换主题就换掉事实描述，模型也读不到稳定语义。键是契约，中文是兜底，外观才是主题的事。
 */
final class StateCopy
{
    /** @var array<string, array{title: string, message: string, hint: string, actions?: list<array<string, mixed>>}> */
    public const DEFAULTS = [
        'state.pending' => [
            'title' => '已受理',
            'message' => '请求已经受理，还没有开始处理。',
            'hint' => '不需要操作，稍候会自动继续。',
        ],
        'state.running' => [
            'title' => '正在处理',
            'message' => '操作正在进行，请不要重复提交。',
            'hint' => '完成后本页会自动更新。',
        ],
        'state.waiting.system' => [
            'title' => '等待外部条件',
            'message' => '系统正在等待队列、网络或第三方服务返回。',
            'hint' => '这通常不需要你介入；超时后可以重试。',
            'actions' => [
                ['key' => 'retry', 'label' => '立即重试', 'kind' => 'secondary', 'href' => '', 'method' => 'POST'],
            ],
        ],
        'state.waiting.user' => [
            'title' => '需要你确认',
            'message' => '操作已暂停，等待你的选择或补充信息。',
            'hint' => '处理完成后流程会继续。',
        ],
        'state.done' => [
            'title' => '已完成',
            'message' => '操作已经完成。',
            'hint' => '',
        ],
        'state.error.validation' => [
            'title' => '填写内容有误',
            'message' => '提交的内容未通过校验，操作没有执行。',
            'hint' => '按提示修正后重新提交。',
        ],
        'state.error.auth' => [
            'title' => '需要先登录',
            'message' => '当前身份无法继续，操作没有执行。',
            'hint' => '登录后回到本页重试。',
            'actions' => [
                ['key' => 'login', 'label' => '去登录', 'kind' => 'primary', 'href' => '/login', 'method' => 'GET'],
            ],
        ],
        'state.error.permission' => [
            'title' => '没有执行权限',
            'message' => '当前账号不具备执行该操作的权限。',
            'hint' => '如需开通，请联系站点管理员。',
        ],
        'state.error.not_found' => [
            'title' => '内容不存在',
            'message' => '要找的记录可能已被删除，或地址有误。',
            'hint' => '返回上一页检查地址。',
            'actions' => [
                ['key' => 'back', 'label' => '返回上一页', 'kind' => 'secondary', 'href' => '/', 'method' => 'GET'],
            ],
        ],
        'state.error.conflict' => [
            'title' => '状态已经变化',
            'message' => '操作前提已不成立，为避免覆盖他人修改，本次没有执行。',
            'hint' => '刷新后确认最新状态再操作。',
            'actions' => [
                ['key' => 'reload', 'label' => '刷新查看', 'kind' => 'secondary', 'href' => '', 'method' => 'GET'],
            ],
        ],
        'state.error.rate_limit' => [
            'title' => '操作太频繁',
            'message' => '短时间内请求过多，已被临时限制。',
            'hint' => '等待一会儿再试。',
            'actions' => [
                ['key' => 'retry', 'label' => '稍后重试', 'kind' => 'secondary', 'href' => '', 'method' => 'POST'],
            ],
        ],
        'state.error.unavailable' => [
            'title' => '依赖暂时不可用',
            'message' => '数据库或外部服务没有响应，操作没有完成。',
            'hint' => '可以重试；持续失败请把追踪编号提供给管理员。',
            'actions' => [
                ['key' => 'retry', 'label' => '重试', 'kind' => 'primary', 'href' => '', 'method' => 'POST'],
            ],
        ],
        'state.error.internal' => [
            'title' => '操作没有完成',
            'message' => '服务器内部错误，本次请求未被处理。',
            'hint' => '可以重试；若持续失败，请把追踪编号提供给管理员。',
            'actions' => [
                ['key' => 'retry', 'label' => '重试', 'kind' => 'primary', 'href' => '', 'method' => 'POST'],
            ],
        ],
        'state.install.preflight_failed' => [
            'title' => '环境还不满足安装条件',
            'message' => '前置检查存在未通过的项，安装流程不会继续。',
            'hint' => '按检查表的说明修正后刷新本页。',
            'actions' => [
                ['key' => 'reload', 'label' => '重新检查', 'kind' => 'primary', 'href' => '/install', 'method' => 'GET'],
            ],
        ],
        'state.install.need_input' => [
            'title' => '等待站长填写',
            'message' => '环境已就绪，接下来需要你提供数据库与管理员信息。',
            'hint' => '按左侧步骤顺序提交，令牌只在本机可见。',
        ],
        'state.install.installed' => [
            'title' => '站点已安装',
            'message' => '安装锁已存在，向导不会再执行任何写操作。',
            'hint' => '如需重装，请先手动删除 storage/install.lock 与 config/local.php。',
        ],
    ];

    public function __construct(private HookBus $hooks) {}

    /** @param array<string, mixed> $overrides 调用方就地覆写（页面已知更精确的措辞时使用） */
    public function resolve(string $key, array $overrides = []): array
    {
        $table = (array) $this->hooks->apply('state.copy', self::DEFAULTS);
        $entry = (array) ($table[$key] ?? self::DEFAULTS['state.error.internal']);
        return array_replace(['key' => $key], $entry, $overrides);
    }

    public function has(string $key): bool
    {
        return isset(self::DEFAULTS[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }
}
