-- 归属：systems/Comment/migrations/
-- 002 的逆操作：移除树形列与索引。同样幂等，回退两次也不会报错。
SET @wx_has_tree := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wx_comments' AND COLUMN_NAME = 'parent_id'
);
SET @wx_ddl := IF(@wx_has_tree = 1,
  'ALTER TABLE wx_comments
     DROP INDEX idx_post_id,
     DROP INDEX idx_root,
     DROP COLUMN parent_id,
     DROP COLUMN root_id,
     DROP COLUMN path,
     DROP COLUMN depth,
     DROP COLUMN author_id,
     DROP COLUMN ip_hash,
     DROP COLUMN deleted_at',
  'DO 0');
PREPARE wx_stmt FROM @wx_ddl;
EXECUTE wx_stmt;
DEALLOCATE PREPARE wx_stmt;
