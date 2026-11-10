-- 归属：systems/User/migrations/
-- 002 的逆操作。回退必须让「加了什么」原样消失：列与索引一起删，顺序无关。
-- IF EXISTS 保证第二次回退不会因「已经不在了」而报错。
ALTER TABLE wx_user_accounts
  DROP INDEX IF EXISTS idx_status,
  DROP COLUMN IF EXISTS updated_at,
  DROP COLUMN IF EXISTS last_login_at,
  DROP COLUMN IF EXISTS locked_until,
  DROP COLUMN IF EXISTS failed_attempts,
  DROP COLUMN IF EXISTS status;
