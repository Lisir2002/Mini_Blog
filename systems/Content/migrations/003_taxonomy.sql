-- 归属：systems/Content/migrations/
-- v0.28 分类与标签：一张术语表 + 一张关系表，用 kind 区分「分类」与「标签」。
--
-- 为什么不是两张表：两者的读写路径完全相同（都是 slug / 名称 / 多对多绑定），
-- 分成两套会得到两份几乎逐字相同的 SQL 与两份要同步修的缺陷。
-- 需要「一篇文章只能有一个主分类」时，约束加在业务层，而不是靠拆表来暗示。
--
-- 为什么不建外键：表前缀是多站点隔离手段，外键名是 schema 级唯一，
-- 同一库里两个前缀会撞名。孤儿由 RelationRegistry 登记 + data.php verify 巡检，
-- 删除术语时在事务里显式清理关系（见 ContentTermStore::delete）。
CREATE TABLE IF NOT EXISTS wx_content_terms (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  kind        VARCHAR(16)   NOT NULL DEFAULT 'tag',
  slug        VARCHAR(191)  NOT NULL,
  name        VARCHAR(128)  NOT NULL,
  description VARCHAR(500)  NOT NULL DEFAULT '',
  created_at  DATETIME      NOT NULL,
  updated_at  DATETIME      NOT NULL,
  UNIQUE KEY uk_kind_slug (kind, slug),
  KEY idx_kind_name (kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wx_content_term_relations (
  post_id    BIGINT UNSIGNED NOT NULL,
  term_id    BIGINT UNSIGNED NOT NULL,
  created_at DATETIME        NOT NULL,
  PRIMARY KEY (post_id, term_id),
  KEY idx_term (term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
