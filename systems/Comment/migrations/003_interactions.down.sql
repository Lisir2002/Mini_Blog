-- 归属：systems/Comment/migrations/
-- 003 的逆操作：删掉两张台账表与编辑留痕列，全部幂等。
DROP TABLE IF EXISTS wx_comment_votes;
DROP TABLE IF EXISTS wx_comment_reports;
SET @wx_has_edit := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wx_comments' AND COLUMN_NAME = 'edit_count'
);
SET @wx_ddl := IF(@wx_has_edit = 1,
  'ALTER TABLE wx_comments DROP COLUMN edited_at, DROP COLUMN edit_count',
  'DO 0');
PREPARE wx_stmt FROM @wx_ddl;
EXECUTE wx_stmt;
DEALLOCATE PREPARE wx_stmt;
