-- 归属：systems/Permission/migrations/（表与 SQL 归权限系统层自持，核心零表、零 SQL）
-- v0.9 增量：主体分组。组只是「角色来源」的扩展，不是第四层权限：
--   组 --绑定--> 角色，用户 --属于--> 组，决策时把组的角色并入主体角色集合。
-- subject_type 保留 role 取值，便于后续把「角色组」做成同一张边表，而不改表结构。
-- Schema::apply 每次 boot 重放全部 *.sql，因此建表语句必须幂等。
CREATE TABLE IF NOT EXISTS wx_permission_groups (
  group_key   VARCHAR(64)  PRIMARY KEY,
  name        VARCHAR(128) NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wx_permission_group_subjects (
  group_key    VARCHAR(64)  NOT NULL,
  subject_type VARCHAR(16)  NOT NULL DEFAULT 'user',
  subject_id   VARCHAR(128) NOT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (group_key, subject_type, subject_id),
  INDEX idx_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wx_permission_group_roles (
  group_key  VARCHAR(64) NOT NULL,
  role       VARCHAR(64) NOT NULL,
  created_at DATETIME    NOT NULL,
  PRIMARY KEY (group_key, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
