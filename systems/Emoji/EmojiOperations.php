<?php
declare(strict_types=1);
namespace Wx\Systems\Emoji;

use InvalidArgumentException;
use Wx\Contracts\ApiRegistryInterface;
use Wx\Systems\Api\ApiOperationException;
use Wx\Systems\Attachment\AttachmentRepositoryInterface;

/**
 * 表情接口 —— 列目录、登记自定义表情、删除自定义表情。
 *
 * 自定义表情只接受本站附件 id：拒绝任何 http(s) 外链。
 * 外链表情会让浏览者的每一次浏览都变成对第三方的请求（追踪像素），
 * 也能被换成任意内容；把图片收进本站，等于把这块攻击面关上。
 */
final class EmojiOperations
{
    public function __construct(
        private EmojiCatalogInterface $catalog,
        private AttachmentRepositoryInterface $attachments
    ) {}

    public function register(ApiRegistryInterface $registry): void
    {
        $registry->registerOperation('emojis.list', function (array $in): array {
            $kind = (string) ($in['kind'] ?? '');
            $items = [];
            foreach ($this->catalog->all() as $emoji) {
                if ($kind !== '' && (string) $emoji['kind'] !== $kind) continue;
                $items[] = $this->present($emoji);
            }
            return ['total' => count($items), 'items' => $items];
        });

        $registry->registerOperation('emojis.register', function (array $in): array {
            $code = strtolower(trim((string) ($in['code'] ?? '')));
            if (!BuiltinEmoji::validCode($code)) {
                throw new InvalidArgumentException('短码只能是 2–32 位小写字母、数字、下划线、加号或短横线');
            }
            if (BuiltinEmoji::has($code)) {
                throw new ApiOperationException('该短码已被内置表情占用', 40901);
            }
            $attachmentId = (int) ($in['attachment_id'] ?? 0);
            if ($attachmentId <= 0) throw new InvalidArgumentException('attachment_id 必填且必须为正整数');
            $file = $this->attachments->find($attachmentId);
            if ($file === null || ($file['deleted_at'] ?? null) !== null) {
                throw new ApiOperationException('引用的附件不存在', 40403);
            }
            if (!str_starts_with((string) ($file['mime'] ?? ''), 'image/')) {
                throw new InvalidArgumentException('表情只能引用图片类附件');
            }
            $ok = $this->catalog->register(['code' => $code, 'kind' => 'image',
                'value' => '', 'attachment_id' => $attachmentId]);
            if (!$ok) throw new ApiOperationException('短码已被占用', 40901);
            return ['code' => $code, 'kind' => 'image', 'attachment_id' => $attachmentId, 'created' => true];
        });

        $registry->registerOperation('emojis.delete', function (array $in): array {
            $code = strtolower(trim((string) ($in['code'] ?? '')));
            if ($code === '') throw new InvalidArgumentException('code 必填');
            if (BuiltinEmoji::has($code)) {
                throw new ApiOperationException('内置表情不可删除', 40901);
            }
            if (!$this->catalog->remove($code)) {
                throw new ApiOperationException('自定义表情不存在', 40403);
            }
            return ['code' => $code, 'deleted' => true];
        });
    }

    /** @param array<string, mixed> $emoji @return array<string, mixed> */
    private function present(array $emoji): array
    {
        $id = (int) ($emoji['attachment_id'] ?? 0);
        return [
            'code' => (string) $emoji['code'],
            'kind' => (string) $emoji['kind'],
            'value' => (string) ($emoji['value'] ?? ''),
            'attachment_id' => $id,
            'custom' => (bool) ($emoji['custom'] ?? false),
            'url' => $id > 0 ? '/attach/download?id=' . $id : '',
        ];
    }
}
