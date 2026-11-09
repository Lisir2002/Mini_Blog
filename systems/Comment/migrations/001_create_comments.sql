-- 归属：systems/Comment/migrations/（表与 SQL 归评论系统层自持，核心零表）
-- 表前缀占位 wx_ 在装载时替换为 config.database.prefix
CREATE TABLE IF NOT EXISTS wx_comments (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_slug   VARCHAR(191) NOT NULL,
  author      VARCHAR(64)  NOT NULL DEFAULT '',
  body        TEXT         NOT NULL,
  status      VARCHAR(16)  NOT NULL DEFAULT 'pending',
  created_at  DATETIME     NOT NULL,
  INDEX idx_post (post_slug, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
