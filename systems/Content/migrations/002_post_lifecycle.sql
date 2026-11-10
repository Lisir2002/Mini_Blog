-- 归属：systems/Content/migrations/
-- v0.28 内容生命周期：回收站、定时发布、置顶权重。
-- deleted_at 与 status 正交：回收站里的文章仍然记得它是草稿还是已发布，
-- 恢复时才能回到原状态；把「已删除」做成第五个状态会让恢复变成猜。
ALTER TABLE wx_content_posts ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE wx_content_posts ADD COLUMN IF NOT EXISTS publish_at DATETIME NULL;
ALTER TABLE wx_content_posts ADD COLUMN IF NOT EXISTS pinned TINYINT NOT NULL DEFAULT 0;
ALTER TABLE wx_content_posts ADD COLUMN IF NOT EXISTS sort_weight INT NOT NULL DEFAULT 0;
-- 列表主查询：status = ? AND deleted_at IS NULL AND (publish_at IS NULL OR publish_at <= ?) ORDER BY id
ALTER TABLE wx_content_posts ADD INDEX IF NOT EXISTS idx_live (status, deleted_at, id);
ALTER TABLE wx_content_posts ADD INDEX IF NOT EXISTS idx_deleted (deleted_at);
ALTER TABLE wx_content_posts ADD INDEX IF NOT EXISTS idx_publish_at (publish_at);
