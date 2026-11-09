<?php
declare(strict_types=1);
namespace Wx\Pages\Install;

/**
 * 安装状态与一次性令牌 —— 安装器唯一的两份持久状态。
 *
 * 锁文件 storage/install.lock：存在即视为已安装，向导拒绝再次执行。
 * 令牌 storage/install.token：首次访问 `/install` 时生成，0600 权限，只在本机可读；
 * 执行任何写操作都必须带上它，防止同机其他进程 / 误开的浏览器页面替站长装站。
 */
final class InstallState
{
    private string $lock;
    private string $tokenFile;

    public function __construct(private string $root)
    {
        $dir = $root . '/storage';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建 storage 目录：' . $dir, 50001);
        }
        $this->lock = $dir . '/install.lock';
        $this->tokenFile = $dir . '/install.token';
    }

    public function installed(): bool { return is_file($this->lock); }

    /** 读取或生成安装令牌；文件权限 0600，不上网、不进日志。 */
    public function token(): string
    {
        if (is_file($this->tokenFile)) {
            $value = trim((string) file_get_contents($this->tokenFile));
            if ($value !== '') return $value;
        }
        $value = bin2hex(random_bytes(16));
        if (@file_put_contents($this->tokenFile, $value) === false) {
            throw new \RuntimeException('无法写入安装令牌', 50001);
        }
        @chmod($this->tokenFile, 0600);
        return $value;
    }

    public function verify(string $token): void
    {
        $expected = $this->token();
        if ($token === '' || !hash_equals($expected, $token)) {
            throw new \RuntimeException('安装令牌无效：请读取 storage/install.token 后重试', 40301);
        }
    }

    /** 安装完成：写锁并删除令牌（令牌只在安装窗口内存在）。 */
    public function markInstalled(): void
    {
        $stamp = json_encode(['installed_at' => date(DATE_ATOM)], JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($this->lock, (string) $stamp) === false) {
            throw new \RuntimeException('无法写入安装锁', 50001);
        }
        @unlink($this->tokenFile);
    }

    /**
     * 五步进度。前两步与最后一步由本类自己持有的状态判定；
     * 管理员与迁移两步的事实存在数据库里，由调用方以 $facts 传入（本类不连库，避免职权越界）。
     *
     * @param array{admin?: bool, migrate?: bool} $facts
     * @return list<array{step: string, title: string, done: bool}>
     */
    public function steps(array $facts = []): array
    {
        return [
            ['step' => 'preflight', 'title' => '环境检查', 'done' => $this->preflightDone()],
            ['step' => 'database', 'title' => '数据库连接', 'done' => $this->localConfigured()],
            ['step' => 'admin', 'title' => '管理员账号', 'done' => (bool) ($facts['admin'] ?? false)],
            ['step' => 'migrate', 'title' => '建表与迁移基线', 'done' => (bool) ($facts['migrate'] ?? false)],
            ['step' => 'finish', 'title' => '完成并加锁', 'done' => $this->installed()],
        ];
    }

    private function preflightDone(): bool
    {
        return PHP_VERSION_ID >= 80100;
    }

    private function localConfigured(): bool
    {
        $file = $this->root . '/config/local.php';
        if (!is_file($file)) return false;
        $data = require $file;
        return is_array($data) && (string) ($data['database']['dsn'] ?? '') !== '';
    }
}
