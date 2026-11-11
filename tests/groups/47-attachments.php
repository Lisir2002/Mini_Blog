<?php
declare(strict_types=1);

use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;
use Wx\Pages\Post\PostAttachments;
use Wx\Systems\Lifecycle\Fs;

/**
 * 47 · 文章页附件：三种状态可区分，私有附件不上公开页
 *
 * 这一组回答「附件传了看不见」：
 *  1. 没有附件 → empty（明确写「确实没有」，不是加载失败）；
 *  2. 有附件 → ok（名称即下载链接，带类型 / 大小 / 内容指纹）；
 *  3. 读取失败 → unavailable（写出原因，不冒充「没有附件」）；
 *  4. 私有附件在匿名读路径上不可见 —— 可见性由系统层判定，模板不参与。
 */
echo "\n=== 47. 文章页附件：三态与可见性 ===\n";

check('读取失败：unavailable，且说明原因', function (): void {
    $broken = new class implements ApiRegistryInterface {
        public function endpoints(): array { return []; }
        public function find(string $id): ?array { return null; }
        public function match(string $method, string $path): ?array { return null; }
        public function registerOperation(string $operation, callable $handler): void {}
        public function execute(string $operation, array $input): mixed {
            throw new RuntimeException('存储不可用');
        }
    };
    $r = PostAttachments::load($broken, 'any');
    truthy(($r['state'] ?? '') === 'unavailable', '失败时状态不是 unavailable');
    truthy(str_contains((string) $r['message'], '存储不可用'), '没有带上失败原因');
    truthy(($r['items'] ?? []) === [], '失败时不该给出条目');
});

$site = tempSite('attach');
$prefix = 'ia_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, ['theme' => ['active' => 'glass'], 'api' => ['expose_write' => true]]);
$k->boot();
$admin = adminHeaders();

/** @return array{0:int, 1:string} */
$get = static function (string $path, array $q = []) use ($k): array {
    $r = $k->handle(new Request('GET', $path, $q));
    return [$r->status(), $r->body()];
};
$upload = static function (string $name, string $bytes, string $visibility = 'public') use ($k, $admin): int {
    $r = $k->handle(new Request('POST', '/attach/upload',
        ['post_slug' => 'first-post', 'name' => $name, 'visibility' => $visibility],
        $admin, [], $bytes, '127.0.0.1'));
    return $r->status();
};
$state = static function (string $html): string {
    return preg_match('/data-attachments-state="([^"]*)"/', $html, $m) ? (string) $m[1] : '';
};

check('没有附件：empty，明确说明「确实没有」', function () use ($get, $state): void {
    [$s, $b] = $get('/post', ['slug' => 'first-post']);
    truthy($s === 200, '文章页应 200，实际 ' . $s);
    truthy($state($b) === 'empty', '没有附件时状态不是 empty：' . $state($b));
    truthy(str_contains($b, '本文没有附件'), '没有明确说明确实没有附件');
});

check('上传后：ok，名称即下载链接，带类型与指纹', function () use ($get, $state, $upload): void {
    truthy($upload('notes.txt', 'hello attachment') === 200, '上传失败');
    [$s, $b] = $get('/post', ['slug' => 'first-post']);
    truthy($s === 200, '文章页应 200，实际 ' . $s);
    truthy($state($b) === 'ok', '有附件时状态不是 ok：' . $state($b));
    truthy(str_contains($b, 'notes.txt'), '没有渲染附件名');
    truthy(str_contains($b, '/attach/download?id='), '附件没有下载链接');
    truthy(str_contains($b, 'TXT'), '没有显示类型');
    truthy(str_contains($b, 'sha256'), '没有显示内容指纹');
});

check('私有附件：公开页看不到（可见性由系统层判定）', function () use ($get, $state, $upload): void {
    truthy($upload('secret.txt', 'top secret', 'private') === 200, '私有附件上传失败');
    [$s, $b] = $get('/post', ['slug' => 'first-post']);
    truthy($s === 200, '文章页应 200，实际 ' . $s);
    truthy(!str_contains($b, 'secret.txt'), '私有附件出现在了公开文章页');
    truthy($state($b) === 'ok', '公开附件应仍可见：' . $state($b));
});

check('主题不判断权限：模板里没有可见性分支', function () use ($root): void {
    foreach (['Glass', 'GlassFront'] as $theme) {
        $src = (string) file_get_contents($root . '/themes/' . $theme . '/templates/partials/attachments.php');
        truthy(!str_contains($src, 'visibility'), $theme . ' 的附件模板在做可见性判断');
        truthy(!str_contains($src, 'identity'), $theme . ' 的附件模板读了身份');
    }
});

dropTables($prefix);
Fs::removeDir($site);
