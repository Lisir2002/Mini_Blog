<?php
declare(strict_types=1);
namespace Wx\Systems\Emoji;

use Wx\Contracts\DatabaseInterface;
use Wx\Systems\Database\Schema;

/**
 * 表情目录的 MySQL 实现：内置表在代码里，自定义表在库里。
 *
 * 两者分开存放是有意的：内置表随版本升级而变化，必须可回滚、可 diff；
 * 自定义表是站点数据，升级不能覆盖。合并只发生在读取时。
 */
final class PdoEmojiCatalog implements EmojiCatalogInterface
{
    public function __construct(private DatabaseInterface $db) {}

    public function ensureSchema(): void { Schema::apply($this->db, __DIR__ . '/migrations'); }

    public function find(string $code): ?array
    {
        $code = strtolower($code);
        $builtin = BuiltinEmoji::find($code);
        if ($builtin !== null) {
            return ['code' => $code, 'kind' => $builtin['kind'], 'value' => $builtin['value'],
                'attachment_id' => 0, 'custom' => false];
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT code,kind,value,attachment_id FROM ' . $this->table() . ' WHERE code = :code'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function all(): array
    {
        $out = [];
        foreach (BuiltinEmoji::all() as $code => $emoji) {
            $out[$code] = ['code' => $code, 'kind' => $emoji['kind'], 'value' => $emoji['value'],
                'attachment_id' => 0, 'custom' => false];
        }
        foreach ($this->custom() as $row) {
            $out[$row['code']] = $row;
        }
        ksort($out);
        return $out;
    }

    public function custom(): array
    {
        $rows = $this->db->query('SELECT code,kind,value,attachment_id FROM ' . $this->table() . ' ORDER BY code ASC');
        return array_map($this->hydrate(...), $rows);
    }

    public function register(array $emoji): bool
    {
        $code = strtolower((string) ($emoji['code'] ?? ''));
        if ($code === '' || BuiltinEmoji::has($code)) return false;
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO ' . $this->table()
            . ' (code,kind,value,attachment_id,created_at) VALUES (:code,:kind,:value,:attachment_id,:created_at)'
        );
        $stmt->execute([
            ':code' => $code,
            ':kind' => (string) ($emoji['kind'] ?? 'image'),
            ':value' => (string) ($emoji['value'] ?? ''),
            ':attachment_id' => (int) ($emoji['attachment_id'] ?? 0),
            ':created_at' => date('Y-m-d H:i:s'),
        ]);
        return $stmt->rowCount() > 0;
    }

    public function remove(string $code): bool
    {
        $code = strtolower($code);
        if (BuiltinEmoji::has($code)) return false;
        $stmt = $this->db->pdo()->prepare('DELETE FROM ' . $this->table() . ' WHERE code = :code');
        $stmt->execute([':code' => $code]);
        return $stmt->rowCount() > 0;
    }

    public function dropSchema(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS ' . $this->table());
    }

    private function hydrate(array $row): array
    {
        $id = (int) $row['attachment_id'];
        $value = (string) $row['value'];
        // 图片表情的可渲染值就是本站下载地址；绝不接受库里存外链，读出来也只会是站内路径。
        if ($value === '' && $id > 0) $value = '/attach/download?id=' . $id;
        return [
            'code' => (string) $row['code'],
            'kind' => (string) $row['kind'],
            'value' => $value,
            'attachment_id' => $id,
            'custom' => true,
        ];
    }

    private function table(): string { return $this->db->prefix() . 'emojis'; }
}
