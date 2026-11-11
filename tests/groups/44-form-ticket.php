<?php
declare(strict_types=1);
use Wx\Systems\Page\FormTicket;

/**
 * 44 · 表单票据（纯逻辑）
 *
 * 票据是「页面上的写表单如何在非 GET 下证明身份」的唯一机制。它的失败必须是
 * 显式拒绝，不能返回半个身份：一旦验签在某个维度上「差不多对」就放行，
 * 后台写入口等于对全世界开放。
 */

$dir = sys_get_temp_dir() . '/wx-ticket-' . bin2hex(random_bytes(4));
@mkdir($dir, 0775, true);
$ticket = new FormTicket($dir);

check('往返：签发的票据能取回同一身份，且路径与权限原样保留', function () use ($ticket): void {
    $t = $ticket->sign(7, 'alice', 'editor', 'comment.moderate', '/console/queue');
    truthy(is_string($t) && $t !== '', '签发失败：' . var_export($t, true));
    $hit = $ticket->verify((string) $t, '/console/queue');
    truthy($hit !== null, '合法票据验签失败');
    truthy((int) $hit['identity']['user_id'] === 7, 'user_id 没有往返');
    truthy($hit['identity']['username'] === 'alice', 'username 没有往返');
    truthy($hit['permission'] === 'comment.moderate', '权限点没有往返');
});

check('路径绑定：审核票据拿到内容页去用，必须拒绝', function () use ($ticket): void {
    $t = (string) $ticket->sign(7, 'alice', 'editor', 'comment.moderate', '/console/queue');
    truthy($ticket->verify($t, '/console/content') === null, '票据跨路径复用竟然通过了');
});

check('篡改：改一个字符就失效，且不会因为「仍是合法 base64」而放行', function () use ($ticket): void {
    $t = (string) $ticket->sign(7, 'alice', 'editor', 'comment.moderate', '/console/queue');
    [$payload, $mac] = explode('.', $t, 2);
    $forged = $payload . '.' . strrev($mac);
    truthy($ticket->verify($forged, '/console/queue') === null, '篡改 mac 仍然通过');
    truthy($ticket->verify('', '/console/queue') === null, '空票竟然通过');
    truthy($ticket->verify('abc', '/console/queue') === null, '无分隔符的票竟然通过');
});

check('过期：用同一把密钥签一张过去的票，必须拒绝', function () use ($ticket, $dir): void {
    $key = (string) file_get_contents($dir . '/form-ticket.key');
    $payload = rtrim(strtr(base64_encode((string) json_encode([
        'u' => 7, 'n' => 'alice', 'r' => 'editor',
        'p' => 'comment.moderate', 't' => '/console/queue', 'e' => time() - 5,
    ])), '+/', '-_'), '=');
    $mac = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $key, true)), '+/', '-_'), '=');
    truthy($ticket->verify($payload . '.' . $mac, '/console/queue') === null, '过期票据仍然通过');
});

check('密钥不可得：签发返回 null，绝不退回无签名票据', function (): void {
    $broken = new FormTicket('/proc/wx-no-such-dir-' . bin2hex(random_bytes(4)));
    truthy($broken->sign(1, 'a', 'admin', 'comment.moderate', '/console/queue') === null,
        '不可写目录竟然签出了票据');
    truthy($broken->verify('anything.sig', '/console/queue') === null,
        '没有密钥时验签竟然通过');
});

check('页面清单：审核页声明了 form_ticket，且与 permission 逐字一致', function () use ($root): void {
    $meta = json_decode((string) file_get_contents($root . '/pages/Queue/page.json'), true);
    $access = (array) ($meta['access'] ?? []);
    truthy(($access['form_ticket'] ?? '') !== '', '审核页没有声明 form_ticket');
    truthy(($access['form_ticket'] ?? '') === ($access['permission'] ?? ''),
        'form_ticket 与 permission 不一致：一张审核票据可以挪去别的权限');
});

check('区块协议：带 action 才输出 <form>，不带 action 保持旧的输入行（向后兼容）', function () use ($root): void {
    $tpl = (string) file_get_contents($root . '/themes/Glass/templates/partials/blocks.php');
    $front = (string) file_get_contents($root . '/themes/GlassFront/templates/partials/blocks.php');
    truthy($tpl === $front, '两款 blocks.php 不是逐字同源');
    truthy(str_contains($tpl, "<form class=\"glass-form\""), '缺少真表单分支');
    truthy(str_contains($tpl, "type=\"hidden\""), '缺少 hidden 字段输出');
    truthy(str_contains($tpl, 'name="_form_ticket"') || str_contains($tpl, "['name']"), '缺少票据字段');
});

@unlink($dir . '/form-ticket.key');
@rmdir($dir);
