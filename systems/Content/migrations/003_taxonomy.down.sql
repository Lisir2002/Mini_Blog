-- 归属：systems/Content/migrations/
-- 003 的逆操作。关系表先删：即使将来补上外键，这个顺序也不会撞依赖。
DROP TABLE IF EXISTS wx_content_term_relations;
DROP TABLE IF EXISTS wx_content_terms;
