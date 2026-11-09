<?php
declare(strict_types=1);
namespace Wx\Systems\State;

/**
 * 状态值对象 —— 「现在处于哪个相位、卡在谁手里、下一步能做什么」的不可变快照。
 *
 * 五个相位：pending / running / waiting / done / failed。
 * 等待与交互不是两个相位，而是 waiting 的两种持有者：
 *   held_by=system → 等待态（等队列、网络、外部依赖）
 *   held_by=user   → 交互态（等确认、授权、补输入）
 *
 * 三条硬不变量在构造时裁决，任何页面 / 系统层都无法绕过：
 *   1. 只有 failed 可以携带业务码，且必须是 4xx / 5xx；
 *   2. 只有 waiting 可以携带 held_by；
 *   3. 终态（done / failed）由上层 StateTasks 拒绝再次流转。
 */
final class State
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const WAITING = 'waiting';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    public const PHASES  = [self::PENDING, self::RUNNING, self::WAITING, self::DONE, self::FAILED];
    public const SCOPES  = ['page', 'action', 'task'];
    public const HOLDERS = ['system', 'user'];
    public const KINDS   = ['primary', 'secondary', 'danger', 'link'];
    public const MAX_ACTIONS = 4;

    /** @param array<string, mixed> $f */
    private function __construct(private array $f) {}

    /** @param array<string, mixed> $fields */
    public static function create(array $fields): self
    {
        return new self(self::normalize($fields));
    }

    public function phase(): string { return $this->f['phase']; }
    public function heldBy(): ?string { return $this->f['held_by']; }
    public function scope(): string { return $this->f['scope']; }
    public function code(): int { return $this->f['code']; }
    public function revision(): int { return $this->f['revision']; }
    public function retryable(): bool { return $this->f['retryable']; }
    public function isTerminal(): bool { return in_array($this->f['phase'], [self::DONE, self::FAILED], true); }

    /** @return array{key: string, title: string, message: string, hint: string} */
    public function text(): array
    {
        return ['key' => $this->f['key'], 'title' => $this->f['title'],
            'message' => $this->f['message'], 'hint' => $this->f['hint']];
    }    /** @return array{done: int, total: int, unit: string, label: string}|null */
    public function progress(): ?array { return $this->f['progress']; }

    /** @return list<array<string, mixed>> */
    public function actions(): array { return $this->f['actions']; }

    /** @return array{id: ?string, trace_id: string, at: string} */
    public function meta(): array
    {
        return ['id' => $this->f['id'], 'trace_id' => $this->f['trace_id'], 'at' => $this->f['at']];
    }

    /** 状态到 HTTP 的推导只有一处：done=200，进行中=202，失败取业务码前三位。 */
    public function httpStatus(): int
    {
        if ($this->f['phase'] === self::DONE) return 200;
        if ($this->f['phase'] !== self::FAILED) return 202;
        $http = intdiv($this->f['code'], 100);
        return $http >= 400 && $http < 600 ? $http : 500;
    }

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return self::create(array_replace($this->raw(), $changes));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'phase'    => $this->f['phase'],
            'held_by'  => $this->f['held_by'],
            'scope'    => $this->f['scope'],
            'id'       => $this->f['id'],
            'code'     => $this->f['code'],
            'key'      => $this->f['key'],
            'title'    => $this->f['title'],
            'message'  => $this->f['message'],
            'hint'     => $this->f['hint'],
            'progress' => $this->f['progress'],
            'actions'  => $this->f['actions'],
            'evidence' => [
                'trace_id'  => $this->f['trace_id'],
                'at'        => $this->f['at'],
                'retryable' => $this->f['retryable'],
            ],
            'revision' => $this->f['revision'],
        ];
    }

    /** UTF-8 安全的定长截断：不依赖 mbstring，也不会把中文字符切成半个。 */
    private static function cut(string $value, int $limit): string
    {
        if ($value === '' || strlen($value) <= $limit) return $value;
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? substr($value, 0, $limit) : implode('', array_slice($chars, 0, $limit));
    }

    /** @return array<string, mixed> */
    private function raw(): array
    {
        $f = $this->f;
        return ['phase' => $f['phase'], 'held_by' => $f['held_by'], 'scope' => $f['scope'], 'id' => $f['id'],
            'code' => $f['code'], 'key' => $f['key'], 'title' => $f['title'], 'message' => $f['message'],
            'hint' => $f['hint'], 'progress' => $f['progress'], 'actions' => $f['actions'],
            'trace_id' => $f['trace_id'], 'at' => $f['at'], 'retryable' => $f['retryable'],
            'revision' => $f['revision']];
    }

    /**
     * 归一化 + 裁决。所有非法组合在此处一次性拒绝，避免同一规则散落到各个页面。
     * @param array<string, mixed> $f
     * @return array<string, mixed>
     */
    private static function normalize(array $f): array
    {
        $phase = (string) ($f['phase'] ?? self::PENDING);
        if (!in_array($phase, self::PHASES, true)) {
            throw new StateException('未知状态相位：' . $phase, 42201);
        }

        $held = $f['held_by'] ?? null;
        if ($phase === self::WAITING) {
            if (!in_array($held, self::HOLDERS, true)) {
                throw new StateException('等待态必须声明 held_by=system|user', 42201);
            }
        } elseif ($held !== null) {
            throw new StateException('只有等待态可以声明 held_by', 42201);
        }

        $code = (int) ($f['code'] ?? 0);
        if ($phase === self::FAILED) {
            if ($code < 400) throw new StateException('失败态必须携带 4xx / 5xx 业务码', 42201);
        } elseif ($code !== 0) {
            throw new StateException('只有失败态可以携带业务码', 42201);
        }

        $retryable = (bool) ($f['retryable'] ?? false);
        if ($retryable && $phase !== self::FAILED) {
            throw new StateException('只有失败态可以标记为可重试', 42201);
        }

        $scope = (string) ($f['scope'] ?? 'action');
        if (!in_array($scope, self::SCOPES, true)) {
            throw new StateException('未知状态作用域：' . $scope, 42201);
        }
        $id = isset($f['id']) && $f['id'] !== '' ? (string) $f['id'] : null;
        if ($scope === 'task' && $id === null) {
            throw new StateException('长任务状态必须携带任务 id', 42201);
        }

        $progress = self::progressOf($f['progress'] ?? null);
        if ($phase === self::DONE && $progress !== null && $progress['done'] !== $progress['total']) {
            throw new StateException('完成态的进度必须已走满', 42201);
        }

        $title = trim((string) ($f['title'] ?? ''));
        if ($title === '') throw new StateException('状态必须有面向人的标题', 42201);

        return [
            'phase'     => $phase,
            'held_by'   => $held,
            'scope'     => $scope,
            'id'        => $id,
            'code'      => $code,
            'key'       => (string) ($f['key'] ?? ''),
            'title'     => self::cut($title, 80),
            'message'   => self::cut(trim((string) ($f['message'] ?? '')), 500),
            'hint'      => self::cut(trim((string) ($f['hint'] ?? '')), 300),
            'progress'  => $progress,
            'actions'   => self::actionsOf($f['actions'] ?? []),
            'trace_id'  => (string) ($f['trace_id'] ?? bin2hex(random_bytes(8))),
            'at'        => (string) ($f['at'] ?? date(DATE_ATOM)),
            'retryable' => $retryable,
            'revision'  => max(0, (int) ($f['revision'] ?? 0)),
        ];
    }

    /** @return array{done: int, total: int, unit: string, label: string}|null */
    private static function progressOf(mixed $raw): ?array
    {
        if ($raw === null) return null;
        if (!is_array($raw)) throw new StateException('进度必须是对象', 42201);
        $total = (int) ($raw['total'] ?? 0);
        $done  = (int) ($raw['done'] ?? 0);
        if ($total <= 0 || $done < 0 || $done > $total) {
            throw new StateException('进度必须满足 0 ≤ done ≤ total 且 total > 0', 42201);
        }
        return [
            'done'  => $done,
            'total' => $total,
            'unit'  => self::cut((string) ($raw['unit'] ?? ''), 8),
            'label' => self::cut((string) ($raw['label'] ?? ''), 60),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function actionsOf(mixed $raw): array
    {
        if (!is_array($raw)) throw new StateException('动作清单必须是数组', 42201);
        $out = [];
        foreach ($raw as $action) {
            if (!is_array($action)) throw new StateException('动作必须是对象', 42201);
            $key   = (string) ($action['key'] ?? '');
            $label = (string) ($action['label'] ?? '');
            if ($key === '' || $label === '') throw new StateException('动作必须有 key 与 label', 42201);
            if (isset($out[$key])) throw new StateException('动作 key 重复：' . $key, 42201);
            $kind = (string) ($action['kind'] ?? 'secondary');
            if (!in_array($kind, self::KINDS, true)) throw new StateException('未知动作类型：' . $kind, 42201);
            $href = (string) ($action['href'] ?? '');
            if ($href !== '' && !preg_match('#^(/|\#)#', $href)) {
                throw new StateException('动作链接只允许站内路径', 42201);
            }
            $method = strtoupper((string) ($action['method'] ?? 'GET'));
            if (!in_array($method, ['GET', 'POST'], true)) {
                throw new StateException('动作只支持 GET / POST', 42201);
            }
            $out[$key] = ['key' => $key, 'label' => self::cut($label, 40), 'kind' => $kind,
                'href' => $href, 'method' => $method];
        }
        if (count($out) > self::MAX_ACTIONS) {
            throw new StateException('一个状态最多 ' . self::MAX_ACTIONS . ' 个动作', 42201);
        }
        return array_values($out);
    }
}
