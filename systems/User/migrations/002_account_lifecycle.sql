-- 归属：systems/User/migrations/
-- v0.27 账号生命周期：状态、失败计数、锁定、最后登录、更新时间。
-- Schema::apply 每次 boot 重放目录下全部 *.sql，因此每条语句都必须幂等。
ALTER TABLE wx_user_accounts ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'active';
ALTER TABLE wx_user_accounts ADD COLUMN IF NOT EXISTS failed_attempts INT NOT NULL DEFAULT 0;
ALTER TABLE wx_user_accounts ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL;
ALTER TABLE wx_user_accounts ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL;
ALTER TABLE wx_user_accounts ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;
ALTER TABLE wx_user_accounts ADD INDEX IF NOT EXISTS idx_status (status);
