-- 归属：systems/User/migrations/（表与 SQL 归用户系统层自持，核心零表）
-- 表前缀占位 wx_ 在装载时替换为 config.database.prefix
CREATE TABLE IF NOT EXISTS wx_user_accounts (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name  VARCHAR(64)  NOT NULL DEFAULT '',
  role          VARCHAR(32)  NOT NULL DEFAULT 'subscriber',
  created_at    DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
