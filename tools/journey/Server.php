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
     * @return array{status:int, protocol:string, headers:array<string,string>, body:string}
     */
    public function get(string $path = '/'): array
    {
        $ctx = stream_context_create(['http' => [
            'ignore_errors' => true, 'timeout' => 10, 'header' => "Connection: close\r\n",
        ]]);
        $body = @file_get_contents('http://127.0.0.1:' . $this->port . $path, false, $ctx);
        $raw = $http_response_header ?? [];

        $status = 0;
        $protocol = '';
        $headers = [];
        foreach ($raw as $i => $line) {
            if ($i === 0 && preg_match('#^(HTTP/\S+)\s+(\d{3})#', $line, $m) === 1) {
                $protocol = $m[1];
                $status = (int) $m[2];
                continue;
            }
            $at = strpos($line, ':');
            if ($at !== false) {
                $headers[strtolower(trim(substr($line, 0, $at)))] = trim(substr($line, $at + 1));
            }
        }
        return ['status' => $status, 'protocol' => $protocol,
                'headers' => $headers, 'body' => (string) $body];
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
        $cmd = [PHP_BINARY, '-d', 'display_errors=' . $this->displayErrors, '-d', 'log_errors=1',
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
