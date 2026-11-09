-- 归属：systems/Emoji/migrations/（表与 SQL 归表情系统层自持，核心零表）
-- 只存自定义表情；内置短码在代码里，随版本升级且可 diff。图片一律引用本站附件 id，不存外链。
CREATE TABLE IF NOT EXISTS wx_emojis (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code          VARCHAR(32)  NOT NULL UNIQUE,
  kind          VARCHAR(16)  NOT NULL DEFAULT 'image',
  value         VARCHAR(255) NOT NULL DEFAULT '',
  attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL,
  INDEX idx_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
