-- 归属：systems/Comment/migrations/
-- 001 建的是平坦表；本迁移把它补成「相邻表 + 物化路径」。
-- 必须幂等：启动兜底（Schema::apply）与版本升级（MigrationRunner）都会执行本文件，
-- 所以用 information_schema 探测列是否存在，存在则执行 DO 0（空操作），
-- 避免第二次启动因重复 ADD COLUMN 直接失败。
SET @wx_has_tree := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wx_comments' AND COLUMN_NAME = 'parent_id'
);
SET @wx_ddl := IF(@wx_has_tree = 0,
  'ALTER TABLE wx_comments
     ADD COLUMN parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN root_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN path VARCHAR(255) NOT NULL DEFAULT '''',
     ADD COLUMN depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
     ADD COLUMN ip_hash CHAR(32) NOT NULL DEFAULT '''',
     ADD COLUMN deleted_at DATETIME NULL,
     ADD INDEX idx_post_id (post_slug, id),
     ADD INDEX idx_root (root_id)',
  'DO 0');
PREPARE wx_stmt FROM @wx_ddl;
EXECUTE wx_stmt;
DEALLOCATE PREPARE wx_stmt;
