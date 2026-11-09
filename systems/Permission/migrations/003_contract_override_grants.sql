-- 归属：systems/Permission/migrations/（表与 SQL 归权限系统层自持，核心零表、零 SQL）
-- v0.8 增量：契约覆盖授权台账。运行时由具备 system.contract.override 权限的身份登记，
-- 装配期在 permission.contract_override.require_grant=true 时据此裁决（fail-closed）。
-- Schema::apply 每次 boot 重放全部 *.sql，因此建表语句必须幂等。
CREATE TABLE IF NOT EXISTS wx_permission_contract_overrides (
  contract    VARCHAR(191) NOT NULL,
  owner       VARCHAR(96)  NOT NULL,
  granted_by  VARCHAR(96)  NOT NULL DEFAULT '',
  reason      VARCHAR(255) NOT NULL DEFAULT '',
  active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  UNIQUE KEY uk_contract_owner (contract, owner),
  INDEX idx_contract (contract)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
