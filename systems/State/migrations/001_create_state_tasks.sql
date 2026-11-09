-- 归属：systems/State/migrations/（表与 SQL 归状态系统层自持，核心零表、零 SQL）
-- 长任务状态：跨请求可见的那一部分状态。页面级 / 动作级状态不落库。
-- revision 是乐观锁，每次推进 +1；终态由 StateTasks 拒绝再次流转。
-- Schema::apply 每次 boot 重放全部 *.sql，因此建表语句必须幂等。
CREATE TABLE IF NOT EXISTS wx_state_tasks (
  id          VARCHAR(64)  PRIMARY KEY,
  kind        VARCHAR(64)  NOT NULL DEFAULT '',
  owner       VARCHAR(64)  NOT NULL DEFAULT '',
  phase       VARCHAR(16)  NOT NULL,
  held_by     VARCHAR(16)  NULL,
  scope       VARCHAR(16)  NOT NULL DEFAULT 'task',
  code        INT          NOT NULL DEFAULT 0,
  copy_key    VARCHAR(64)  NOT NULL DEFAULT '',
  title       VARCHAR(120) NOT NULL,
  message     VARCHAR(600) NOT NULL DEFAULT '',
  hint        VARCHAR(400) NOT NULL DEFAULT '',
  progress    TEXT         NULL,
  actions     TEXT         NULL,
  trace_id    VARCHAR(32)  NOT NULL DEFAULT '',
  happened_at VARCHAR(40)  NOT NULL DEFAULT '',
  retryable   TINYINT      NOT NULL DEFAULT 0,
  revision    INT          NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL,
  updated_at  DATETIME     NOT NULL,
  expires_at  DATETIME     NULL,
  INDEX idx_phase (phase, updated_at),
  INDEX idx_kind (kind, updated_at),
  INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
