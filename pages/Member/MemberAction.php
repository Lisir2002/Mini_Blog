<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Core\Kernel;
use Wx\Pages\Shared\ApiFailure;
use Wx\Systems\Api\ApiOperationException;

/**
 * 成长数据的写入口 —— 发经验、定规则、定头衔、授头衔、定等级。
 *
 * 页面声明的是 member.read（能看），五个写动作在这里**各问一次自己的写权限点**：
 * 页面直调执行者会绕过 API 分发器的权限检查，权限层缺席时一律 fail-closed。
 * 一张票据只证明「谁从哪一页提交」，不能替代这五次裁决。
 *
 * 两类「接口不报错、页面必须管」的脏数据：
 *  1. 授予目录里没有的 title_key —— 授予关系落库，档案里永远看不见，且不报错；
 *  2. 给既无账号也无档案的 ID 发经验 —— 会凭空建一份档案，榜单上多一个空名字。
 *     这一条不拦（写路径放宽是刻意设计：插件可给站点外主体记账），但如实说出来。
 */
final class MemberAction
{
    public const ACTIONS = ['award', 'xp_rule', 'title_def', 'title_grant', 'level_def'];

    /** 每个动作一个写权限点，不能用 member.read 或别人的权限点顶替。 */
    public const PERMISSIONS = [
        'award'       => 'member.xp.award',
        'xp_rule'     => 'member.xp.rule.manage',
        'title_def'   => 'member.title.manage',
        'title_grant' => 'member.title.grant',
        'level_def'   => 'member.level.manage',
    ];

    private const OPERATIONS = [
        'award'       => 'member.xp.award',
        'xp_rule'     => 'member.xp.rule',
        'title_def'   => 'member.title',
        'title_grant' => 'member.title.grant',
        'level_def'   => 'member.level',
    ];

    /** @param array<string, mixed>|null $identity
     *  @return array<string, mixed> */
    public static function run(Kernel $kernel, Request $request, ?array $identity): array
    {
        $body   = $request->body();
        $action = trim((string) ($body['action'] ?? ''));
        if (!in_array($action, self::ACTIONS, true)) {
            return self::fail(422, 42201, '未知操作「' . ($action === '' ? '(空)' : $action) . '」。');
        }
        if ($identity === null) {
            return self::fail(401, 40101, '身份未建立：表单票据未通过校验，请刷新页面后重试。');
        }

        $container = $kernel->container();
        $acl = $container->has(AccessControlInterface::class)
            ? $container->resolve(AccessControlInterface::class) : null;
        $permission = self::PERMISSIONS[$action];
        if ($acl === null || !$acl instanceof AccessControlInterface
            || !$acl->allows($identity, $permission)) {
            return self::fail(403, 40303, '缺少 ' . $permission
                . ' 权限点，写操作被拒绝（fail-closed）。能看成长数据不等于能改它。');
        }
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, 50301, '接口清单未装配，无法提交。');
        }

        $input = MemberInput::normalize($action, $body);
        if (($input['ok'] ?? false) !== true) {
            return self::fail(422, 42201, (string) ($input['message'] ?? '参数不合法。'));
        }
        /** @var array<string, mixed> $in */
        $in = $input['in'];
        $key = (string) $input['key'];
        $op  = (string) $input['op'];
        $registry = $container->resolve(ApiRegistryInterface::class);

        if ($action === 'title_grant') {
            $guard = self::titleExists($registry, (string) $in['title_key']);
            if ($guard !== '') return self::fail(422, 42201, $guard);
        }
        $hint = in_array($action, ['award', 'title_grant'], true)
            ? self::orphanHint($registry, (string) $in['user_id']) : '';

        $in['_identity'] = $identity;
        try {
            $out = $registry->execute(self::OPERATIONS[$action], $in);
        } catch (\Throwable $e) {
            return self::translate(ApiFailure::describe($e), $action, $in);
        }
        $result = MemberOutcome::explain($action, (array) $out, $in, $key);
        return ['ok' => true, 'status' => 200, 'code' => 0, 'message' => 'ok',
            'action' => $action, 'op' => $op, 'key' => $key,
            'outcome' => $result['outcome'], 'changed' => $result['changed'],
            'detail' => $result['detail'], 'hint' => $hint];
    }

    /** 目录里没有这个头衔就别写：授予关系不会报错，但档案里永远看不到。 */
    private static function titleExists(ApiRegistryInterface $registry, string $key): string
    {
        try {
            $rows = (array) ($registry->execute('member.titles',
                ['include_hidden' => true])['titles'] ?? []);
        } catch (\Throwable $e) {
            return '读不到头衔目录，无法确认「' . $key . '」是否存在 —— 本次提交被拒绝（fail-closed）。'
                . '原因：' . $e->getMessage();
        }
        $keys = [];
        foreach ($rows as $row) {
            if (is_array($row)) $keys[] = (string) ($row['title_key'] ?? '');
        }
        if (!in_array($key, $keys, true)) {
            return '头衔「' . $key . '」不在目录里。授予一个不存在的头衔不会报错，'
                . '但档案里永远看不到它 —— 请先在「头衔定义」里建好，或检查拼写。'
                . ($keys === [] ? '当前目录是空的。' : '现有：' . implode('、', $keys) . '。');
        }
        return '';
    }

    /** 写路径允许给站点外主体记账，但页面必须说明「这会凭空建一份档案」。 */
    private static function orphanHint(ApiRegistryInterface $registry, string $identifier): string
    {
        try {
            $profile = $registry->execute('member.profile', ['user_id' => $identifier]);
        } catch (ApiOperationException $e) {
            if ($e->apiCode === 40403) {
                return '注意：「' . $identifier . '」既查不到账号、也没有成长档案。'
                    . '发经验 / 授头衔不校验账号存在，这次会凭空建一份没人能认领的档案，'
                    . '排行榜上会多一行空名字。';
            }
            return '';
        } catch (\Throwable $e) {
            return '';
        }
        return (bool) ($profile['orphan'] ?? false)
            ? '注意：「' . $identifier . '」的成长档案还在，但账号已经查不到了（orphan）。' : '';
    }

    /**
     * 把系统层的英文枚举 / 通用错误补上「下一步怎么办」，不改错误码。
     *
     * @param array{ok: false, status: int, code: int, message: string} $failure
     * @param array<string, mixed> $in
     * @return array{ok: false, status: int, code: int, message: string}
     */
    private static function translate(array $failure, string $action, array $in): array
    {
        $message = (string) $failure['message'];
        if ($action === 'award' && str_contains($message, '未配置该动作的经验规则')) {
            $failure['message'] = '动作「' . (string) ($in['action'] ?? '')
                . '」没有经验规则，接口拒绝凭空决定加多少分。'
                . '要么在「经验规则」里定义它，要么在本次表单里显式填写 amount。';
        }
        return $failure;
    }

    /** @return array{ok: false, status: int, code: int, message: string} */
    private static function fail(int $status, int $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
