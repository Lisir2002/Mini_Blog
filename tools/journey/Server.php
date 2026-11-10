<?php
declare(strict_types=1);
namespace Wx\Tools\Journey;

/**
 * 真实 SAPI 服务器 —— 把「请求」交给 PHP 内置服务器，而不是直接调 Kernel::handle()。
 *
 * 为什么需要它：`handle()` 绕过了 `run()` 的异常边界。构造期故障（配置损坏）
 * 在入口处就抛出，只有真实 SAPI 才能证明浏览器最终看到的是纯文本 500，而不是白屏或堆栈。
 *
 * 只做四件事：拉起进程、等就绪、读一次原始响应、强制回收。不引入外部依赖。
 */
final class Server
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private string $log = '';

    private function __construct(private int $port, private string $docroot, private string $displayErrors) {}

    public static function start(string $docroot, bool $displayErrors = false): self
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        if ($sock === false) throw new \RuntimeException('无法分配本地端口');
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $self = new self($port, $docroot, $displayErrors ? '1' : '0');
        $self->spawn();
        return $self;
    }

    public function port(): int { return $this->port; }
    public function log(): string { return (string) @file_get_contents($this->log); }

    /**
     * 一次真实 HTTP 请求。
     *
     * 用原始 socket 而不是 file_get_contents：HTTP 包装器会开一条「投机预连接」，
     * 内置服务器先 accept 它、发现没有请求再关掉。当两条连接同时在队列里，
     * 拿到响应的顺序就可能与发出的顺序错开 —— 于是「截断配置后应 500」偶尔读到
     * 上一条请求的 200，守卫变成随机通过或随机失败。这类不稳定比没有守卫更糟：
     * 它会让人开始怀疑断言本身，而不是怀疑代码。
     *
     * @return array{status:int, protocol:string, headers:array<string,string>, body:string}
     */
    public function get(string $path = '/'): array
    {
        $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5.0);
        if (!is_resource($fp)) {
            // 连不上就是连不上：返回 0 而不是沿用上一次的响应头。
            return ['status' => 0, 'protocol' => '', 'headers' => [], 'body' => ''];
        }
        stream_set_timeout($fp, 10);
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $raw = (string) stream_get_contents($fp);
        fclose($fp);

        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $lines = explode("\r\n", $head);
        $status = 0;
        $protocol = '';
        $headers = [];
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                if (preg_match('#^(HTTP/\S+)\s+(\d{3})#', $line, $m) === 1) {
                    $protocol = $m[1];
                    $status = (int) $m[2];
                }
                continue;
            }
            $at = strpos($line, ':');
            if ($at !== false) {
                $headers[strtolower(trim(substr($line, 0, $at)))] = trim(substr($line, $at + 1));
            }
        }
        return ['status' => $status, 'protocol' => $protocol, 'headers' => $headers,
                'body' => str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')
                    ? self::dechunk($body) : $body];
    }

    /** 分块编码只在内置服务器选了 chunked 时出现；不还原的话正文会带上长度前缀，逐字断言必错。 */
    private static function dechunk(string $body): string
    {
        $out = '';
        while ($body !== '') {
            $eol = strpos($body, "\r\n");
            if ($eol === false) break;
            $size = (int) hexdec(substr($body, 0, $eol));
            if ($size === 0) break;
            $out .= substr($body, $eol + 2, $size);
            $body = substr($body, $eol + 2 + $size + 2);
        }
        return $out;
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) fclose($pipe);
        }
        $this->pipes = [];
        if (is_resource($this->proc)) {
            proc_terminate($this->proc, 9);
            proc_close($this->proc);
        }
        $this->proc = null;
        if ($this->log !== '') @unlink($this->log);
    }

    private function spawn(): void
    {
        $this->log = sys_get_temp_dir() . '/wx-sapi-' . $this->port . '.log';
        // revalidate_freq=0：内置服务器会启用 opcache，默认每 2 秒才检查一次文件是否改动。
        // 故障注入「写完文件立刻请求」正好落在这个窗口里，拿到的是旧字节码 ——
        // 于是「截断配置应当 500」会随运行时刻随机通过或失败。守卫必须确定，
        // 观测窗口就不能交给一个与业务无关的缓存参数决定。
        $cmd = [PHP_BINARY, '-d', 'display_errors=' . $this->displayErrors, '-d', 'log_errors=1',
                '-d', 'opcache.revalidate_freq=0', '-d', 'opcache.validate_timestamps=1',
                '-S', '127.0.0.1:' . $this->port, '-t', $this->docroot];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $this->log, 'a'], 2 => ['file', $this->log, 'a']];
        $this->proc = proc_open($cmd, $descriptors, $this->pipes);
        if (!is_resource($this->proc)) throw new \RuntimeException('内置服务器启动失败');

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if (is_resource($fp)) {
                fclose($fp);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new \RuntimeException('内置服务器 5 秒内未就绪');
    }
}
