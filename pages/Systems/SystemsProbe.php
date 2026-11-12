<?php
declare(strict_types=1);
namespace Wx\Pages\Systems;

/**
 * 升级可观测的探查区块 —— 完整性核对 / 快照清单 / 长任务状态。
 *
 * 与 SystemsBlocks 分开：主体区块任何时刻都渲染（降级也要看得见状态），
 * 探查区块只在 GET 查询真实触发时出现。三类结果各有独立的可判定文案：
 * no-manifest 必须明说「未比对」（不许伪装成绿灯）、快照空态要说因果
 * （从未快照，回退无目标）、任务相位五态分开着色。
 *
 * @consumes-viewport lifecycle.verify / lifecycle.releases / state.task.status 的呈现层
 */
final class SystemsProbe
{
    /**
     * @param array<string, mixed> $data SystemsQuery::fetch 的返回值
     * @return list<array<string, mixed>>
     */
    public static function build(array $data): array
    {
        $out = [];
        $verify = $data['verify'] ?? null;
        if (is_array($verify)) $out[] = self::verify($verify, (string) ($data['probe']['verify'] ?? ''));
        $releases = $data['releases'] ?? null;
        if (is_array($releases)) $out[] = self::releases($releases, (string) ($data['probe']['releases'] ?? ''));
        $task = $data['task'] ?? null;
        if (is_array($task)) $out[] = self::task($task);
        return $out;
    }

    /** @param array<string, mixed> $verify @return array<string, mixed> */
    private static function verify(array $verify, string $unit): array
    {
        $mismatch = array_values(array_map('strval', (array) ($verify['mismatch'] ?? [])));
        $mode = (string) ($verify['mode'] ?? '?');
        $ok = (bool) ($verify['ok'] ?? false);
        $value = '扫描 ' . (int) ($verify['files'] ?? 0) . ' 个文件';
        if ($mode === 'no-manifest') {
            // 「没有清单」与「全部匹配」是两件事：前者根本没比对，不能写成绿灯。
            $value .= ' · 没有 manifest.sha256 清单，未做摘要比对。';
            $tone = 'info';
            $state = 'empty';
        } else {
            $value .= $ok ? ' · 摘要全部匹配。' : ' · 有 ' . count($mismatch) . ' 个文件不一致：'
                . implode('、', array_slice($mismatch, 0, 3))
                . (count($mismatch) > 3 ? ' 等' : '') . '。';
            $tone = $ok ? 'ok' : 'warn';
            $state = $ok ? 'ok' : 'degraded';
        }
        return ['type' => 'notice', 'id' => 'sys-verify', 'tone' => $tone, 'state' => $state,
            'title' => '完整性核对：' . $unit, 'value' => $value];
    }

    /** @param array<string, mixed> $releases @return array<string, mixed> */
    private static function releases(array $releases, string $unit): array
    {
        $rows = [];
        foreach ((array) ($releases['releases'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $rows[] = ['k' => (string) ($r['version'] ?? '?'),
                'v' => number_format((int) ($r['bytes'] ?? 0) / 1024, 1) . ' KB',
                'ok' => true, 'note' => '快照：回退时可作为目标版本'];
        }
        if ($rows === []) {
            return ['type' => 'notice', 'id' => 'sys-releases', 'tone' => 'info',
                'state' => 'empty', 'title' => '快照列表：' . $unit,
                'value' => '版本仓库里没有这个单元的快照：从未走过 install / upgrade 的快照步骤，'
                    . '回退也就没有目标版本可选。'];
        }
        return ['type' => 'table', 'id' => 'sys-releases',
            'title' => '快照列表（' . count($rows) . '） · ' . $unit, 'rows' => $rows];
    }

    /** @param array<string, mixed> $task @return array<string, mixed> */
    private static function task(array $task): array
    {
        $phase = (string) ($task['phase'] ?? '?');
        [$tone, $state] = match ($phase) {
            'done' => ['ok', 'ok'],
            'failed' => ['warn', 'degraded'],
            default => ['info', 'degraded'],
        };
        $value = '相位 ' . $phase . ' · 持有者 ' . (string) ($task['held_by'] ?? 'null');
        if ((int) ($task['code'] ?? 0) !== 0) $value .= ' · 业务码 ' . (int) $task['code'];
        return ['type' => 'notice', 'id' => 'sys-task', 'tone' => $tone, 'state' => $state,
            'title' => '长任务：' . (string) ($task['id'] ?? ''), 'value' => $value];
    }
}
