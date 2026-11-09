-- 归属：systems/Security/migrations/（令牌表归安全系统层自持）
-- v0.9 增量：区分 API 令牌与页面会话令牌。两者共用同一张表、同一套过期与撤销语义，
-- 差别只在载体（Authorization 头 vs HttpOnly Cookie）与用途（接口 vs 页面 GET）。
-- Schema::apply 每次 boot 重放全部 *.sql，因此必须幂等（MariaDB 10.11 支持 ADD COLUMN IF NOT EXISTS）。
ALTER TABLE wx_user_tokens ADD COLUMN IF NOT EXISTS kind VARCHAR(16) NOT NULL DEFAULT 'api';
