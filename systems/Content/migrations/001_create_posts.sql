-- 归属：systems/Content/migrations/（表与 SQL 归系统层自持，核心零表、零 SQL）
-- 表前缀占位 wx_ 会在装载时替换为 config.database.prefix
CREATE TABLE IF NOT EXISTS wx_content_posts (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  slug        VARCHAR(191)  NOT NULL UNIQUE,
  title       VARCHAR(255)  NOT NULL,
  body        MEDIUMTEXT    NOT NULL,
  excerpt     VARCHAR(500)  NOT NULL DEFAULT '',
  status      VARCHAR(16)   NOT NULL DEFAULT 'published',
  author      VARCHAR(64)   NOT NULL DEFAULT '',
  created_at  DATETIME      NOT NULL,
  updated_at  DATETIME      NOT NULL,
  INDEX idx_status_id (status, id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
