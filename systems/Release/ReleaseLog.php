<?php
declare(strict_types=1);
namespace Wx\Systems\Release;

/**
 * 版本历史 —— 每次升级 / 回退都留一条，append-only。
 *
 * 历史不是给人看的装饰：回退时要知道「哪次升级执行了哪些迁移」，
 * 否则只能整库还原。条目里的 migrations 是回退计划的事实来源。
 */
final class ReleaseLog
{
    private string $file;

    public function __construct(string $dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ReleaseException('无法创建版本历史目录：' . $dir, 50001);
        }
        $this->file = $dir . '/history.json';
    }

    public function role(): string { return 'release_log'; }

    /** @param array<string, mixed> $entry */
    public function append(array $entry): void
    {
        $rows = $this->raw();
        $entry['at'] = (string) ($entry['at'] ?? date(DATE_ATOM));
        $rows[] = $entry;
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($this->file, $json, LOCK_EX) === false) {
            throw new ReleaseException('版本历史写入失败：' . $this->file, 50001);
        }
    }

    /** @return list<array<string, mixed>> 时间倒序 */
    public function all(int $limit = 50): array
    {
        $rows = array_reverse($this->raw());
        return array_slice($rows, 0, max(1, $limit));
    }

    /** @return array<string, mixed>|null */
    public function lastSuccessful(): ?array
    {
        foreach ($this->all(200) as $row) {
            if (($row['result'] ?? '') === 'ok' && ($row['action'] ?? '') === 'upgrade') return $row;
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function raw(): array
    {
        if (!is_file($this->file)) return [];
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? array_values($data) : [];
    }
}
