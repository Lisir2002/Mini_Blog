<?php
declare(strict_types=1);
namespace Wx\Systems\Attachment;

use InvalidArgumentException;

/**
 * 附件类型守卫 —— 白名单 + 魔数 + 编码，三道校验都必须在服务端完成。
 *
 * 刻意不读客户端声明的 Content-Type：它由请求方自由填写，只能当提示，不能当证据。
 * 类型一律由「扩展名 → 白名单 → 魔数是否匹配」推导，声明与内容不一致即拒绝。
 * SVG / HTML / JS / PHP 不在白名单：它们能在浏览器里执行，比"允许上传"危险得多。
 */
final class AttachmentGuard
{
    /** 扩展名 => 对外 MIME。MIME 只从这里取，绝不回显客户端声明。 */
    private const TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf',
        'zip' => 'application/zip', 'txt' => 'text/plain', 'md' => 'text/markdown',
        'csv' => 'text/csv', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
    ];

    /** 二进制类型的文件头；同一扩展名允许多个合法签名。 */
    private const SIGNATURES = [
        'png'  => ['89504e47'],
        'jpg'  => ['ffd8ff'],
        'jpeg' => ['ffd8ff'],
        'gif'  => ['47494638'],
        'pdf'  => ['25504446'],
        'zip'  => ['504b0304', '504b0506'],
        'mp3'  => ['494433', 'fffb', 'fff3'],
    ];

    /** 文本类型没有魔数，改用「无 NUL 字节 + 合法 UTF-8」判定，同样能挡住改名的可执行文件。 */
    private const TEXT = ['txt', 'md', 'csv'];

    public function __construct(private int $maxBytes = 8388608) {}

    /**
     * @return array{name: string, ext: string, mime: string, bytes: int, sha256: string}
     * @throws InvalidArgumentException 空文件 / 超限 / 类型不在白名单 / 内容与声明不符
     */
    public function inspect(string $filename, string $content): array
    {
        $bytes = strlen($content);
        if ($bytes === 0) throw new InvalidArgumentException('文件内容为空');
        if ($bytes > $this->maxBytes) {
            throw new InvalidArgumentException('文件超出 ' . intdiv($this->maxBytes, 1024) . ' KB 上限');
        }

        $name = self::displayName($filename);
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) {
            throw new InvalidArgumentException('不支持的文件类型：' . ($ext === '' ? '无扩展名' : $ext));
        }
        $this->assertContent($ext, $content);

        return [
            'name'   => $name,
            'ext'    => $ext,
            'mime'   => self::TYPES[$ext],
            'bytes'  => $bytes,
            'sha256' => hash('sha256', $content),
        ];
    }

    public static function mimeOf(string $ext): string
    {
        return self::TYPES[strtolower($ext)] ?? 'application/octet-stream';
    }

    public static function allowed(string $ext): bool
    {
        return isset(self::TYPES[strtolower($ext)]);
    }

    /** 展示名只用于「下载时叫什么」，不参与存储路径；在这里就把路径分隔符与控制字符清掉。 */
    public static function displayName(string $filename): string
    {
        // 先归一化分隔符再取最后一段：绝不能「把斜杠删掉」，
        // 否则 ../../etc/cover.png 会变成 etccover.png —— 看似安全，语义已错。
        $name = str_replace('\\', '/', trim($filename));
        $name = (string) basename($name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name, ". \t\n\r");
        if ($name === '') $name = 'file';
        $chars = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, 0, 120));
    }

    private function assertContent(string $ext, string $content): void
    {
        if (in_array($ext, self::TEXT, true)) {
            if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
                throw new InvalidArgumentException('文本文件包含二进制内容或非法编码');
            }
            return;
        }
        if ($ext === 'webp') {
            if (substr($content, 0, 4) !== 'RIFF' || substr($content, 8, 4) !== 'WEBP') {
                throw new InvalidArgumentException('文件内容不是有效的 WEBP');
            }
            return;
        }
        if ($ext === 'mp4') {
            if (substr($content, 4, 4) !== 'ftyp') {
                throw new InvalidArgumentException('文件内容不是有效的 MP4');
            }
            return;
        }
        $head = bin2hex(substr($content, 0, 8));
        foreach (self::SIGNATURES[$ext] ?? [] as $signature) {
            if (str_starts_with($head, $signature)) return;
        }
        throw new InvalidArgumentException('文件内容与扩展名不符：' . $ext);
    }
}
