<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

/**
 * 附件字节存储 —— 内容寻址：路径完全由 SHA-256 生成，文件名不参与任何一段路径。
 *
 * 这一条决定同时解决了四类问题：
 *   路径穿越（文件名根本不进路径）、双扩展名（没有扩展名可供解析）、
 *   覆盖攻击（同名不同内容会落到不同哈希）、直接执行（目录不在 webroot，文件无扩展名）。
 */
final class AttachmentStorage
{
    public function __construct(private string $root) {}

    /** @return string 写入的相对路径（<前2位>/<次2位>/<sha256>） */
    public function put(string $bytes, string $sha256): string
    {
        $relative = $this->relative($sha256);
        $target = $this->root . '/' . $relative;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('附件目录不可写：' . $dir);
        }
        // 原子落盘：先写临时文件再 rename，避免并发下读到半个文件。
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.part';
        if (file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('附件写入失败');
        }
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('附件落盘失败');
        }
        @chmod($target, 0644);
        return $relative;
    }

    public function read(string $sha256): ?string
    {
        $path = $this->root . '/' . $this->relative($sha256);
        if (!is_file($path)) return null;
        $bytes = file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    /** 供流式下载使用：只给已校验哈希对应的绝对路径，不做任何读取。 */
    public function path(string $sha256): ?string
    {
        $path = $this->root . '/' . $this->relative($sha256);
        return is_file($path) ? $path : null;
    }

    public function size(string $sha256): ?int
    {
        $path = $this->path($sha256);
        if ($path === null) return null;
        $size = @filesize($path);
        return $size === false ? null : (int) $size;
    }

    public function exists(string $sha256): bool
    {
        return is_file($this->root . '/' . $this->relative($sha256));
    }

    /** 仅在引用计数为 0 时由回收流程调用；请求路径永不直接删字节。 */
    public function delete(string $sha256): bool
    {
        $path = $this->root . '/' . $this->relative($sha256);
        return is_file($path) && @unlink($path);
    }

    /** 哈希必须是 64 位十六进制，否则一律视为非法 —— 这是路径安全的唯一入口。 */
    private function relative(string $sha256): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('附件哈希非法');
        }
        return substr($sha256, 0, 2) . '/' . substr($sha256, 2, 2) . '/' . $sha256;
    }
}
