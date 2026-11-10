-- 归属：systems/Content/migrations/
-- 002 的逆操作：加了什么就删什么，顺序与 up 相反，IF EXISTS 保证重复回退不报错。
ALTER TABLE wx_content_posts
  DROP INDEX IF EXISTS idx_publish_at,
  DROP INDEX IF EXISTS idx_deleted,
  DROP INDEX IF EXISTS idx_live,
  DROP COLUMN IF EXISTS sort_weight,
  DROP COLUMN IF EXISTS pinned,
  DROP COLUMN IF EXISTS publish_at,
  DROP COLUMN IF EXISTS deleted_at;
