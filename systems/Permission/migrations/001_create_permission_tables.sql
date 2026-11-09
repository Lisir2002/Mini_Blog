-- 归属：systems/Permission/migrations/（表与 SQL 归权限系统层自持，核心零表、零 SQL）
-- 表前缀占位 wx_ 在装载时替换为 config.database.prefix
CREATE TABLE IF NOT EXISTS wx_permission_roles (
  role        VARCHAR(64)  PRIMARY KEY,
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wx_permission_grants (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  role        VARCHAR(64)  NOT NULL,
  permission  VARCHAR(128) NOT NULL,
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uk_role_perm (role, permission),
  INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wx_permission_acl (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  resource_type VARCHAR(64)  NOT NULL,
  resource_id   VARCHAR(128) NOT NULL,
  subject_type  VARCHAR(16)  NOT NULL DEFAULT 'role',
  subject_id    VARCHAR(128) NOT NULL DEFAULT '',
  capability    VARCHAR(64)  NOT NULL,
  allow         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL,
  INDEX idx_res (resource_type, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wx_permission_audit (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  actor       VARCHAR(128) NOT NULL DEFAULT '',
  action      VARCHAR(128) NOT NULL,
  resource    VARCHAR(128) NOT NULL DEFAULT '',
  decision    VARCHAR(16)  NOT NULL,
  reason      VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL,
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
