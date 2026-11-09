<?php
declare(strict_types=1);
namespace Wx\Systems\State;

use PDO;

/**
 * 长任务状态的 MySQL 实现。
 *
 * revision 是乐观锁：每次写入必须带着「我读到的版本」，
 * 更新条件里带上它，命中 0 行就说明别人先改了 —— 立即 40901，绝不静默覆盖。
 */
final class PdoStateStore implements StateStoreInterface
{
    public function __construct(private \Wx\Contracts\DatabaseInterface $db) {}

    public function role(): string { return 'pdo_state_store'; }

    public function ensureSchema(): void
    {
        \Wx\Systems\Database\Schema::apply($this->db, __DIR__ . '/migrations');
    }

    public function put(State $state, string $kind = '', string $owner = '', int $ttl = 0): State
    {
        $meta = $state->meta();
        $id = (string) ($meta['id'] ?? '');
        if ($id === '') throw new StateException('持久化状态必须携带 id', 42201);

        $now = time();
        $stamp = date('Y-m-d H:i:s', $now);
        $expires = $ttl > 0 ? date('Y-m-d H:i:s', $now + $ttl) : null;
        $a = $state->toArray();
        $progress = $a['progress'] === null ? null : json_encode($a['progress'], JSON_UNESCAPED_UNICODE);
        $actions = json_encode($a['actions'], JSON_UNESCAPED_UNICODE);
        $held = $a['held_by'];

        if ($state->revision() === 0) {
            $sql = 'INSERT INTO ' . $this->table()
                . ' (id,kind,owner,phase,held_by,scope,code,copy_key,title,message,hint,progress,actions,'
                . 'trace_id,happened_at,retryable,revision,created_at,updated_at,expires_at)'
                . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)';
            $this->db->exec($sql, [
                $id, $kind, $owner, $a['phase'], $held, $a['scope'], $a['code'], $a['key'],
                $a['title'], $a['message'], $a['hint'], $progress, $actions,
                $a['evidence']['trace_id'], $a['evidence']['at'], (int) $a['evidence']['retryable'],
                $stamp, $stamp, $expires,
            ]);
            return $state->with(['revision' => 1]);
        }

        $sql = 'UPDATE ' . $this->table()
            . ' SET phase=?,held_by=?,code=?,copy_key=?,title=?,message=?,hint=?,progress=?,actions=?,'
            . 'trace_id=?,happened_at=?,retryable=?,revision=?,updated_at=?,expires_at=?'
            . ' WHERE id=? AND revision=?';
        $changed = $this->db->exec($sql, [
            $a['phase'], $held, $a['code'], $a['key'], $a['title'], $a['message'], $a['hint'],
            $progress, $actions, $a['evidence']['trace_id'], $a['evidence']['at'],
            (int) $a['evidence']['retryable'], $state->revision() + 1, $stamp, $expires,
            $id, $state->revision(),
        ]);
        if ($changed === 0) {
            throw new StateException('状态已被其他请求推进，本次不覆盖：' . $id, 40901);
        }
        return $state->with(['revision' => $state->revision() + 1]);
    }

    public function find(string $id): ?State
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM ' . $this->table() . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function sweep(int $now): int
    {
        return $this->db->exec(
            'DELETE FROM ' . $this->table() . ' WHERE expires_at IS NOT NULL AND expires_at < ?',
            [date('Y-m-d H:i:s', $now)]
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): State
    {
        $progress = json_decode((string) ($row['progress'] ?? ''), true);
        $actions = json_decode((string) ($row['actions'] ?? ''), true);
        return State::create([
            'phase'     => (string) $row['phase'],
            'held_by'   => $row['held_by'] === null || $row['held_by'] === '' ? null : (string) $row['held_by'],
            'scope'     => (string) ($row['scope'] ?? 'task'),
            'id'        => (string) $row['id'],
            'code'      => (int) $row['code'],
            'key'       => (string) $row['copy_key'],
            'title'     => (string) $row['title'],
            'message'   => (string) $row['message'],
            'hint'      => (string) $row['hint'],
            'progress'  => is_array($progress) ? $progress : null,
            'actions'   => is_array($actions) ? $actions : [],
            'trace_id'  => (string) $row['trace_id'],
            'at'        => (string) $row['happened_at'],
            'retryable' => (bool) $row['retryable'],
            'revision'  => (int) $row['revision'],
        ]);
    }

    private function table(): string
    {
        return $this->db->prefix() . 'state_tasks';
    }
}
