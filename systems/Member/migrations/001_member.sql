-- 归属：systems/Member/migrations/（表与 SQL 归成员系统层自持，核心零表、零 SQL）
-- v0.10 增量：头衔 / 经验 / 等级。三组数据彼此独立：
--   profiles + xp_events 是「成长刻度」，levels + xp_rules 是「刻度定义」，titles 是「身份标签」。
-- 头衔刻意不建任何到 permission_* 表的外键或列：标签不参与授权。
-- Schema::apply 每次 boot 重放全部 *.sql，因此建表语句必须幂等。
CREATE TABLE IF NOT EXISTS wx_member_profiles (
  user_id    VARCHAR(64) PRIMARY KEY,
  xp         BIGINT      NOT NULL DEFAULT 0,
  level      INT         NOT NULL DEFAULT 1,
  updated_at DATETIME    NOT NULL,
  INDEX idx_rank (xp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wx_member_xp_events (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    VARCHAR(64)  NOT NULL,
  action     VARCHAR(64)  NOT NULL,
  amount     INT          NOT NULL,
  event_key  VARCHAR(128) NOT NULL,
  note       VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME     NOT NULL,
  UNIQUE KEY uq_event (event_key),
  INDEX idx_user_action (user_id, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wx_member_levels (
  level  INT         PRIMARY KEY,
  name   VARCHAR(64) NOT NULL,
  min_xp BIGINT      NOT NULL,
  color  VARCHAR(16) NOT NULL DEFAULT '',
  UNIQUE KEY uq_min_xp (min_xp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wx_member_xp_rules (
  action      VARCHAR(64)  PRIMARY KEY,
  amount      INT          NOT NULL,
  daily_cap   INT          NOT NULL DEFAULT 0,
  enabled     TINYINT      NOT NULL DEFAULT 1,
  description VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wx_member_titles (
  title_key   VARCHAR(64)  PRIMARY KEY,
  name        VARCHAR(64)  NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  color       VARCHAR(16)  NOT NULL DEFAULT '',
  priority    INT          NOT NULL DEFAULT 0,
  visible     TINYINT      NOT NULL DEFAULT 1,
  min_level   INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wx_member_user_titles (
  user_id    VARCHAR(64) NOT NULL,
  title_key  VARCHAR(64) NOT NULL,
  granted_by VARCHAR(64) NOT NULL DEFAULT '',
  granted_at DATETIME    NOT NULL,
  PRIMARY KEY (user_id, title_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
