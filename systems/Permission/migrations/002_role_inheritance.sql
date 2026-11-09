-- 归属：systems/Permission/migrations/（表与 SQL 归权限系统层自持，核心零表、零 SQL）
-- v0.7 增量：角色继承表 + 资源 ACL 的字段维度。
-- Schema::apply 每次 boot 都重放全部 *.sql，因此两条语句必须幂等。
-- 表前缀占位 wx_ 在装载时替换为 config.database.prefix
CREATE TABLE IF NOT EXISTS wx_permission_role_parents (
  role        VARCHAR(64)  NOT NULL,
  parent_role VARCHAR(64)  NOT NULL,
  created_at  DATETIME     NOT NULL,
  UNIQUE KEY uk_role_parent (role, parent_role),
  INDEX idx_role (role),
  INDEX idx_parent (parent_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE wx_permission_acl ADD COLUMN IF NOT EXISTS field VARCHAR(64) NOT NULL DEFAULT '';
