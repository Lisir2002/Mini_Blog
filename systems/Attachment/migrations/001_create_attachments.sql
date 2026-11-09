-- 归属：systems/Attachment/migrations/（表与 SQL 归附件系统层自持，核心零表）
-- 元数据与字节分离：这里只存"这份文件是谁、叫什么、指向哪个哈希"，字节在 storage/attachments 下。
CREATE TABLE IF NOT EXISTS wx_attachments (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_slug   VARCHAR(191) NOT NULL DEFAULT '',
  name        VARCHAR(255) NOT NULL,
  sha256      CHAR(64)     NOT NULL,
  ext         VARCHAR(16)  NOT NULL,
  mime        VARCHAR(128) NOT NULL,
  bytes       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  visibility  VARCHAR(16)  NOT NULL DEFAULT 'public',
  uploader_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL,
  deleted_at  DATETIME     NULL,
  INDEX idx_post (post_slug, id),
  INDEX idx_hash (sha256),
  INDEX idx_live (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
