-- 迁移台账：记录「哪些脚本已经在当前数据库上执行过」。
-- 这是版本系统的事实源，而不是代码里的版本号判断。
-- id = owner/文件名；checksum 用来发现「已执行的脚本被改动」。
CREATE TABLE IF NOT EXISTS wx_schema_migrations (
    id         VARCHAR(191) NOT NULL,
    owner      VARCHAR(64)  NOT NULL,
    checksum   CHAR(64)     NOT NULL,
    batch      INT          NOT NULL DEFAULT 0,
    applied_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_schema_migrations_batch (batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
