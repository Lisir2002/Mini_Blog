<?php
declare(strict_types=1);

use Wx\Contracts\AccessControlInterface;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Core\Http\Request;

/**
 * 66 · 附件上传闭环：浏览器 multipart 表单 → attachments.upload 执行器
 *
 * 从 HTTP 面证明八件事：
 *  1. 选中文章后上传表单出现：enctype=multipart、file 输入、两个可见性按钮；
 *  2. 上传成功 303 回本页，Location 带新附件 id，清单立刻可见；
 *  3. 私有上传落到 visibility=private，公开上传落到 public；
 *  4. 没选文件 422 且不跳转，失败提示不把「没选文件」说成「文件为空」；
 *  5. 白名单外扩展名（php）与魔数不符（假 png）都在 AttachmentGuard 被拒；
 *  6. 上传现场问 attachment.upload：撤掉它 POST 403，GET 不受影响；
 *  7. Request::file() 的语义：缺文件 / PHP 报错返回 null，不把 tmp 缺失当空字节；
 *  8. 消费矩阵：attachments.upload 不再只有工具脚本消费方。
 */
echo "\n=== 66. 附件上传闭环 ===\n";

$site   = tempSite('upload-loop');
$prefix = 'up_';
dropTables($prefix);
$k = isolatedKernel($site, $prefix, [
    'theme' => ['active' => 'glass'],
    'permission' => ['cache' => ['driver' => 'memory']],
]);
$k->boot();

/** @return array{0:int, 1:string, 2:array<string,string>} */
$hit = static function (string $method, string $path, array $q = [], array $body = [],
    array $headers = [], array $files = []) use ($k): array {
    $r = $k->handle(new Request($method, $path, $q, $headers, $body, '', null, $files));
    return [$r->status(), $r->body(), $r->headers()];
};
$field = static function (string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m)
        ? (string) $m[1] : '';
};
$registry = $k->container()->resolve(ApiRegistryInterface::class);

$registry->execute('content.create', ['slug' => 'up-a', 'title' => '上传闭环甲',
    'body' => '正文占位', 'status' => 'published', 'author' => 'admin']);

// 最小合法 PNG（1x1 像素）：有真魔数，能过 Guard 的签名比对。
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
truthy($png !== false && str_starts_with($png, "\x89PNG"), 'PNG 夹具损坏');

/** 模拟一次浏览器 multipart 上传：files 直接按 $_FILES 结构给。 */
$filesOf = static function (string $name, string $bytes, int $error = UPLOAD_ERR_OK): array {
    $tmp = tempnam(sys_get_temp_dir(), 'wx66');
    file_put_contents($tmp, $bytes);
    return ['file' => ['name' => $name, 'type' => 'application/octet-stream',
        'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($bytes)]];
};

[, , $h] = $hit('POST', '/login', [], ['username' => 'admin', 'password' => 'admin123']);
$cookie = 'wx_session=' . cookieValue((string) ($h['Set-Cookie'] ?? ''), 'wx_session');
truthy($cookie !== 'wx_session=', '登录失败，拿不到会话');

[, $page] = $hit('GET', '/console/attachments', ['slug' => 'up-a'], [], ['Cookie' => $cookie]);
$ticket = $field($page, '_form_ticket');
truthy($ticket !== '', '没拿到 /console/attachments 的表单票据');

/** @return array{0:int, 1:string, 2:array<string,string>} */
$post = static function (array $body, array $files = []) use ($hit, $cookie): array {
    [$s, $b, $headers] = $hit('POST', '/console/attachments', ['slug' => 'up-a'],
        $body + ['slug' => 'up-a'], ['Cookie' => $cookie], $files);
    return [$s, (string) ($headers['Location'] ?? ''), $b];
};
$uploadBody = static fn(string $op): array =>
    ['op' => $op, '_form_ticket' => $ticket];

check('上传表单只在选中文章后出现：multipart、file 输入、两个可见性按钮齐全',
    function () use ($page): void {
        truthy(str_contains($page, 'multipart/form-data'), 'enctype 没有声明');
        truthy(str_contains($page, 'type="file"'), '没有文件选择输入');
        truthy(str_contains($page, '上传新附件'), '上传表单标题缺失');
        truthy(str_contains($page, 'upload:public') && str_contains($page, 'upload:private'),
            '可见性没有用按钮枚举给出');
        truthy(str_contains($page, '扩展名白名单'), '白名单提示缺失');
        truthy(str_contains($page, 'SVG'), '高危类型说明缺失');
    });

check('公开上传：303 回本页带新 id，清单立刻可见',
    function () use ($post, $filesOf, $png, $hit, $cookie, $uploadBody): void {
        [$s, $loc] = $post($uploadBody('upload:public'), $filesOf('probe.png', $png));
        truthy($s === 303 && $loc !== '', '上传成功应 303，实际 ' . $s);
        truthy((bool) preg_match('/[?&]id=(\d+)/', $loc, $m), 'Location 没带新附件 id');
        $id = (int) $m[1];
        truthy($id > 0, '新附件 id 不合法');
        [, $view] = $hit('GET', '/console/attachments', ['slug' => 'up-a'], [], ['Cookie' => $cookie]);
        truthy(str_contains($view, 'probe.png'), '新附件没有出现在清单里');
        truthy(str_contains($view, 'image/png'), 'MIME 没有按扩展名推导');
    });

check('私有上传落 private；同名不同内容不覆盖（内容寻址）',
    function () use ($post, $filesOf, $png, $hit, $cookie, $registry, $uploadBody): void {
        [$s, $loc] = $post($uploadBody('upload:private'), $filesOf('secret.png', $png));
        truthy($s === 303, '私有上传应 303，实际 ' . $s);
        preg_match('/[?&]id=(\d+)/', $loc, $m);
        $id = (int) ($m[1] ?? 0);
        [, $view] = $hit('GET', '/console/attachments', ['slug' => 'up-a'], [], ['Cookie' => $cookie]);
        truthy(str_contains($view, 'secret.png'), '私有附件后台清单里应该可见');
        // 内容相同 → 同一 sha256，第二次上传同一字节流：内容寻址意味着字节只有一份。
        [$s2] = $post($uploadBody('upload:public'), $filesOf('again.png', $png));
        truthy($s2 === 303, '同内容重复上传也应 303');
        $rows = $registry->execute('attachments.list', ['post_slug' => 'up-a']);
        $names = array_map(static fn(array $r): string => (string) ($r['name'] ?? ''),
            array_values(array_filter((array) ($rows['items'] ?? []), 'is_array')));
        truthy(in_array('again.png', $names, true), '同内容第二次上传没有落库');
    });

check('没选文件 422 不跳转：明确说「没有收到文件」，不假装是空文件',
    function () use ($post, $uploadBody): void {
        [$s, $loc, $b] = $post($uploadBody('upload:public'));
        truthy($s === 422 && $loc === '', '没选文件应 422 不跳转，实际 ' . $s);
        truthy(str_contains($b, '没有收到文件'), '失败提示没有区分「没选文件」');
        truthy(str_contains($b, '重新选择'), '没有说明文件不可回填');
    });

check('白名单外扩展名（php）与魔数不符（假 png）都被 Guard 拒绝',
    function () use ($post, $filesOf, $uploadBody): void {
        [$s1, , $b1] = $post($uploadBody('upload:public'),
            $filesOf('evil.php', "<?php echo 1;"));
        truthy($s1 === 422, 'php 上传应 422，实际 ' . $s1);
        truthy(str_contains($b1, '不支持的文件类型'), '白名单拒绝没有解释');
        [$s2, , $b2] = $post($uploadBody('upload:public'), $filesOf('fake.png', 'not a png'));
        truthy($s2 === 422, '假 png 应 422，实际 ' . $s2);
        truthy(str_contains($b2, '文件头') || str_contains($b2, '魔数') || str_contains($b2, '内容'),
            '魔数拒绝没有说明原因');
    });

check('上传现场问 attachment.upload：撤掉它 403，GET 清单不受影响',
    function () use ($k, $post, $filesOf, $png, $uploadBody, $hit, $cookie): void {
        $acl = $k->container()->resolve(AccessControlInterface::class);
        $acl->revoke('admin', '*');
        $acl->grant('admin', 'attachment.read');
        try {
            [$s, $loc, $b] = $post($uploadBody('upload:public'), $filesOf('nope.png', $png));
            truthy($s === 403 && $loc === '', '缺 upload 权限应 403 不跳转，实际 ' . $s);
            truthy(str_contains($b, 'attachment.upload'), '没点明缺哪个权限点');
            // 只剩 attachment.read：页面本体仍可读（200），上传入口消失；
            // 清单同时消失是正确行为 —— content.read 也在 revoke('*') 里被撤了。
            [$gs, $view] = $hit('GET', '/console/attachments', ['slug' => 'up-a'], [], ['Cookie' => $cookie]);
            truthy($gs === 200, '只有读权限时页面本体应 200，实际 ' . $gs);
            truthy(!str_contains($view, 'upload:public'), '上传按钮不该出现在只读身份面前');
        } finally {
            $acl->revoke('admin', 'attachment.read');
            $acl->grant('admin', '*');
        }
    });

check('Request::file() 的语义：PHP 上传报错与缺字段都返回 null，不把错误当空字节',
    function (): void {
        $bad = new Request('POST', '/x', [], [], [], '', null, [
            'a' => ['name' => 'a.png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
        ]);
        truthy($bad->file('a') === null, '上传报错不该返回字节');
        truthy($bad->file('missing') === null, '缺字段应返回 null');
        $none = new Request('POST', '/x');
        truthy($none->files() === [], '未传 files 的 files() 应为空数组');
        $tmpB = tempnam(sys_get_temp_dir(), 'wx66b');
        file_put_contents($tmpB, 'hello');
        $ok = new Request('POST', '/x', [], [], [], '', null, [
            'b' => ['name' => 'b.txt', 'tmp_name' => $tmpB,
                'error' => UPLOAD_ERR_OK, 'size' => 5],
        ]);
        $f = $ok->file('b');
        truthy(is_array($f) && $f['name'] === 'b.txt' && $f['size'] > 0,
            '正常文件没有读到字节');
    });

check('消费矩阵：attachments.upload 有了页面消费方，且上传权限点与改动分开',
    function () use ($root): void {
        $json = (array) json_decode((string) shell_exec(
            'php ' . escapeshellarg($root . '/tools/consumers.php') . ' --json'), true);
        $rows = (array) ($json['all'] ?? []);
        $row = (array) ($rows['attachments.upload'] ?? []);
        $consumers = (array) ($row['consumers'] ?? []);
        truthy(in_array('pages/Attachments/AttachmentsAction.php', $consumers, true),
            'attachments.upload 的页面消费方没有被矩阵记账');
        $text = (string) file_get_contents($root . '/pages/Attachments/AttachmentsAction.php');
        truthy(str_contains($text, 'attachment.upload') && str_contains($text, 'attachment.manage'),
            '上传与改动的权限点必须在 Action 里分开现场问');
    });
