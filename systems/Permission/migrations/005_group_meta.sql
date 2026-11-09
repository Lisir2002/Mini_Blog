-- 归属：systems/Permission/migrations/（组是权限系统层的结构数据）
-- v0.10 增量：组的展示属性。站长自定义「组」时不仅需要名字，还需要颜色与排序，
-- 否则控制台只能按字典序排列，无法体现「站长心里的顺序」。
-- 这两列只影响展示，不参与决策：颜色不进 ACL，排序不进角色集合。
ALTER TABLE wx_permission_groups
  ADD COLUMN IF NOT EXISTS color VARCHAR(16) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0;
