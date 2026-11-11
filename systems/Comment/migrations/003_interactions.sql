-- 归属：systems/Comment/migrations/
-- 003 互动与治理：点赞 / 举报的去重台账，以及评论的编辑留痕列。
-- 去重靠联合主键，不靠「先查再插」：并发下两个请求同时查到「没点过」，
-- 唯一键会让第二条 INSERT IGNORE 返回 0 行，计数仍然只加一次。
CREATE TABLE IF NOT EXISTS wx_comment_votes (
  comment_id  BIGINT UNSIGNED NOT NULL,
  voter_key   VARCHAR(40) NOT NULL,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (comment_id, voter_key),
  INDEX idx_voter (voter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wx_comment_reports (
  comment_id    BIGINT UNSIGNED NOT NULL,
  reporter_key  VARCHAR(40) NOT NULL,
  reason        VARCHAR(32) NOT NULL DEFAULT '',
  created_at    DATETIME NOT NULL,
  PRIMARY KEY (comment_id, reporter_key),
  INDEX idx_reports_comment (comment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- 编辑窗口与乐观锁都依赖这两列。用 information_schema 探测，避免二次启动重复 ADD COLUMN。
SET @wx_has_edit := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wx_comments' AND COLUMN_NAME = 'edit_count'
);
SET @wx_ddl := IF(@wx_has_edit = 0,
  'ALTER TABLE wx_comments
     ADD COLUMN edited_at DATETIME NULL,
     ADD COLUMN edit_count INT UNSIGNED NOT NULL DEFAULT 0',
  'DO 0');
PREPARE wx_stmt FROM @wx_ddl;
EXECUTE wx_stmt;
DEALLOCATE PREPARE wx_stmt;
