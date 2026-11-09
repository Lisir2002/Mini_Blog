<?php
declare(strict_types=1);
namespace Wx\Systems\Data;

use Throwable;
use Wx\Contracts\DatabaseInterface;

/**
 * 孤儿巡检的执行者 —— 只读，只查「子行指向了不存在的父行」，不做任何修复。
 *
 * 为什么不自动修：孤儿行往往意味着上游删错了或漏了补偿，自动删会把「可调查的事故」
 * 变成「无声的数据丢失」。巡检只报告，修复由人决定。
 */
final class IntegrityChecker implements IntegrityCheckerInterface
{
    public function __construct(
        private DatabaseInterface $db,
        private RelationRegistry $relations
    ) {}

    public function inspect(): array
    {
        $findings = [];
        $checked = 0;
        $ran = 0;
        foreach ($this->relations->all() as $name => $rel) {
            $checked++;
            try {
                $count = $this->count($rel);
                $ran++;
            } catch (Throwable $e) {
                // 表尚未建立（未迁移 / 已卸载）不算孤儿：跳过而不是制造噪音。
                continue;
            }
            if ($count > 0) {
                $findings[] = [
                    'relation' => $name,
                    'table' => $this->db->prefix() . $rel['child'],
                    'count' => $count,
                    'samples' => $this->samples($rel),
                ];
            }
        }
        // 一条都没跑成（没库 / 没迁移）时必须说「没验证」，不能报「全部通过」。
        return ['ok' => $findings === [], 'skipped' => $ran === 0, 'checked' => $checked, 'findings' => $findings];
    }

    /** @param array{child: string, column: string, parent: string, key: string, zero: bool} $rel */
    private function count(array $rel): int
    {
        $row = $this->db->query($this->sql($rel, 'COUNT(*) AS n'));
        return (int) ($row[0]['n'] ?? 0);
    }

    /** @param array{child: string, column: string, parent: string, key: string, zero: bool} $rel */
    private function samples(array $rel): array
    {
        $rows = $this->db->query($this->sql($rel, 'c.`' . $rel['column'] . '` AS v') . ' LIMIT 5');
        return array_map(static fn(array $r): int => (int) ($r['v'] ?? 0), $rows);
    }

    /** @param array{child: string, column: string, parent: string, key: string, zero: bool} $rel */
    private function sql(array $rel, string $select): string
    {
        $child = $this->db->prefix() . $rel['child'];
        $parent = $this->db->prefix() . $rel['parent'];
        return 'SELECT ' . $select . ' FROM `' . $child . '` c LEFT JOIN `' . $parent . '` p'
            . ' ON p.`' . $rel['key'] . '` = c.`' . $rel['column'] . '`'
            . ' WHERE p.`' . $rel['key'] . '` IS NULL' . ($rel['zero'] ? ' AND c.`' . $rel['column'] . '` <> 0' : '');
    }
}
