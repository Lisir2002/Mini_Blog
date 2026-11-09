-- 归属：systems/Security/migrations/（令牌与 CSRF 表归安全系统层自持，核心零表）
-- 明文令牌只在签发响应里出现一次，库里只存 SHA-256 摘要，并带过期与撤销标记
CREATE TABLE IF NOT EXISTS wx_user_tokens (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  token_hash  CHAR(64)      NOT NULL UNIQUE,
  user_id     BIGINT UNSIGNED NOT NULL,
  username    VARCHAR(64)   NOT NULL,
  role        VARCHAR(32)   NOT NULL,
  scopes      VARCHAR(255)  NOT NULL DEFAULT '',
  expires_at  DATETIME      NOT NULL,
  revoked     TINYINT(1)    NOT NULL DEFAULT 0,
  created_at  DATETIME      NOT NULL,
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CSRF 令牌落库 + 过期 + 一次性使用，替代 v0.1 的进程内静态字符串
CREATE TABLE IF NOT EXISTS wx_csrf_tokens (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  token_hash  CHAR(64)  NOT NULL UNIQUE,
  expires_at  DATETIME  NOT NULL,
  used        TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME  NOT NULL,
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
