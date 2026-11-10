-- 归属：systems/Metric/migrations/（指标表归统计系统层自持，核心零表）
-- 粒度：一小时 × 指标 × 维度 × 类型 × 分桶上界 一行，靠 UPSERT 累加。
-- 不存单请求明细：那是日志的职责；这里只留聚合，避免表体量随流量线性膨胀。
CREATE TABLE IF NOT EXISTS wx_metric_points (
  bucket_at  DATETIME     NOT NULL,
  name       VARCHAR(64)  NOT NULL,
  dimension  VARCHAR(255) NOT NULL DEFAULT '',
  kind       VARCHAR(16)  NOT NULL,
  le         VARCHAR(16)  NOT NULL DEFAULT '',
  hits       BIGINT       NOT NULL DEFAULT 0,
  total      DOUBLE       NOT NULL DEFAULT 0,
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (bucket_at, name, dimension, kind, le),
  KEY idx_metric_name (name, bucket_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
