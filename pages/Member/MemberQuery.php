<?php
declare(strict_types=1);
namespace Wx\Pages\Member;

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Kernel;
use Wx\Systems\Api\ApiOperationException;

/**
 * 成长视图的取数 —— 只读 levels / titles / xp.rules / leaderboard / profile。
 *
 * 五个接口各自独立成败：规则读不到不该让等级表跟着消失。所以逐项取、逐项记错，
 * 页面按块降级 —— 与 /console/system 同一套做法。
 *
 * 经验规则不「先调再吞 403」：那会把「你没权限」与「接口坏了」混成同一句失败。
 * 先用同一个决策器问一次，没权限就干脆不调，页面明说「看不到」。
 */
final class MemberQuery
{
    public const LIMIT = 10;
    public const MAX_OFFSET = 500;

    /**
     * @param array<string, mixed>|null $identity
     * @param array<string, mixed> $query
     * @return array{ok: bool, status: int, error: string, offset: int, user: string,
     *               levels: array<string, mixed>|null, titles: array<string, mixed>|null,
     *               rules: array<string, mixed>|null, board: array<string, mixed>|null,
     *               profile: array<string, mixed>|null, profile_state: string, profile_error: string,
     *               can: array<string, bool>, fail: array<string, string>}
     */
    public static function fetch(Kernel $kernel, ?array $identity, array $query): array
    {
        $raw = trim((string) ($query['offset'] ?? '0'));
        if ($raw === '') $raw = '0';
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return self::fail(422, 'offset 必须是非负整数，当前为「' . $raw . '」。');
        }
        $offset = (int) $raw;
        if ($offset > self::MAX_OFFSET) {
            return self::fail(422, 'offset 最大 ' . self::MAX_OFFSET . '：'
                . '再往后的名次没有展示价值，而且深翻页对排行榜是最坏的查询。');
        }
        $user = trim((string) ($query['user'] ?? ''));
        if (strlen($user) > 64) {
            return self::fail(422, '查询的用户标识过长（上限 64 字节）。');
        }

        $out = ['ok' => true, 'status' => 200, 'error' => '', 'offset' => $offset, 'user' => $user,
            'levels' => null, 'titles' => null, 'rules' => null, 'board' => null, 'profile' => null,
            'profile_state' => 'idle', 'profile_error' => '',
            'can' => self::capabilities($kernel, $identity), 'fail' => []];

        $container = $kernel->container();
        if (!$container->has(ApiRegistryInterface::class)) {
            return self::fail(503, '接口清单未装配，成长数据无法读取。');
        }
        $registry = $container->resolve(ApiRegistryInterface::class);

        $out['levels'] = self::call($registry, 'member.levels', [], $out['fail'], 'levels');
        // 头衔目录默认只返回 visible=1：后台必须显式 include_hidden，
        // 否则「我建的头衔不见了」会被当成删除成功。
        $out['titles'] = self::call($registry, 'member.titles', ['include_hidden' => true],
            $out['fail'], 'titles');
        if ($out['can']['rule']) {
            $out['rules'] = self::call($registry, 'member.xp.rules', [], $out['fail'], 'rules');
        }
        $out['board'] = self::call($registry, 'member.leaderboard',
            ['limit' => self::LIMIT, 'offset' => $offset], $out['fail'], 'board');

        if ($user !== '') {
            self::lookup($registry, $user, $out);
        }
        return $out;
    }

    /**
     * 页面能看到什么 —— 与接口层同一个决策器，缺席即 fail-closed。
     *
     * @param array<string, mixed>|null $identity
     * @return array<string, bool>
     */
    public static function capabilities(Kernel $kernel, ?array $identity): array
    {
        $can = ['rule' => false, 'award' => false, 'grant' => false];
        $container = $kernel->container();
        if (!$container->has(AccessControlInterface::class)) return $can;
        $acl = $container->resolve(AccessControlInterface::class);
        if (!$acl instanceof AccessControlInterface) return $can;
        $can['rule']  = $acl->allows($identity, 'member.xp.rule.manage');
        $can['award'] = $acl->allows($identity, 'member.xp.award');
        $can['grant'] = $acl->allows($identity, 'member.title.grant');
        return $can;
    }

    /**
     * @param array<string, mixed> $out
     * @return void
     */
    private static function lookup(ApiRegistryInterface $registry, string $user, array &$out): void
    {
        try {
            $out['profile'] = $registry->execute('member.profile', ['user_id' => $user]);
            $out['profile_state'] = 'found';
        } catch (ApiOperationException $e) {
            // 40403 是「查无此人」，其余是「查不了」：前者是答案，后者是故障。
            $out['profile_state'] = $e->apiCode === 40403 ? 'missing' : 'failed';
            $out['profile_error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $out['profile_state'] = 'failed';
            $out['profile_error'] = $e->getMessage();
        }
    }

    /**
     * @param array<string, mixed> $in
     * @param array<string, string> $fail
     * @return array<string, mixed>|null
     */
    private static function call(ApiRegistryInterface $registry, string $operation, array $in,
        array &$fail, string $key): ?array
    {
        try {
            return $registry->execute($operation, $in);
        } catch (\Throwable $e) {
            $fail[$key] = $e->getMessage();
            return null;
        }
    }

    /**
     * @return array{ok: false, status: int, error: string, offset: int, user: string,
     *               levels: null, titles: null, rules: null, board: null, profile: null,
     *               profile_state: string, profile_error: string,
     *               can: array<string, bool>, fail: array<string, string>}
     */
    private static function fail(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $message, 'offset' => 0,
            'user' => '', 'levels' => null, 'titles' => null, 'rules' => null, 'board' => null,
            'profile' => null, 'profile_state' => 'idle', 'profile_error' => '',
            'can' => ['rule' => false, 'award' => false, 'grant' => false], 'fail' => []];
    }
}
