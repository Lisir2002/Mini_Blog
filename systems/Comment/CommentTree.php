<?php
declare(strict_types=1);
namespace Wx\Systems\Comment;

/**
 * 评论树协作者 —— 只做结构计算：物化路径、深度、父子装配。
 *
 * 刻意不按 path 字符串排序取树序：路径段位数不等时字典序会把 10 排到 2 前面。
 * 树序由父子关系决定，同层按 id 升序（即时间顺序），path 只用于「取子树」与深度约束。
 */
final class CommentTree
{
    public const ROOT = 0;

    /** 子节点路径：父路径 + 自身 id + 斜杠；根节点为 "/<id>/"。 */
    public static function pathFor(?array $parent, int $id): string
    {
        $base = $parent === null ? '/' : (string) ($parent['path'] ?? '/');
        return rtrim($base, '/') . '/' . $id . '/';
    }

    /** 父节点深度 + 1；根节点为 0。 */
    public static function depthFor(?array $parent): int
    {
        return $parent === null ? 0 : ((int) ($parent['depth'] ?? 0)) + 1;
    }

    /**
     * 扁平列表 → 嵌套树。父节点缺失（被物理清理）时提升为根，绝不让评论凭空消失。
     * 只用一次遍历与引用挂接，不做递归，因此坏数据也不会把请求拖成栈溢出。
     *
     * @param list<array<string, mixed>> $rows 按 id 升序
     * @return list<array<string, mixed>> 每项附加 children
     */
    public static function build(array $rows): array
    {
        $byId  = [];
        $refs  = [];
        foreach ($rows as $row) {
            $row['children'] = [];
            $byId[(int) $row['id']] = $row;
        }
        foreach ($byId as $id => $_) {
            $refs[$id] = &$byId[$id];
        }
        $tree = [];
        foreach ($byId as $id => $row) {
            $parentId = (int) ($row['parent_id'] ?? self::ROOT);
            if ($parentId !== self::ROOT && isset($refs[$parentId]) && $parentId !== $id) {
                $refs[$parentId]['children'][] = &$refs[$id];
                continue;
            }
            $tree[] = &$refs[$id];
        }
        unset($refs, $row);
        return array_values($tree);
    }

    /** 每个节点的直接子节点数，供「N 条回复」展示；派生数据不落库，避免漂移。 */
    public static function replyCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $parentId = (int) ($row['parent_id'] ?? self::ROOT);
            if ($parentId === self::ROOT) continue;
            $counts[$parentId] = ($counts[$parentId] ?? 0) + 1;
        }
        return $counts;
    }
}
