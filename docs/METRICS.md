# 统计系统（metrics）

> 第 18 个系统层，slug `metric`。目标不是「装一个看板」，而是把「站点现在发生了什么」变成**可查询、可复核、可解释**的事实。
> 设计约束沿用第一性原理：核心零业务、系统层只提供契约与操作、呈现归主题、每条规矩配可重跑守卫。

---

## 1. 边界：统计层不做什么

| 不做 | 原因 | 替代 |
| --- | --- | --- |
| 不输出 HTML / CSS / JS | 零呈现是硬约束 H1；看板归主题 | 后台由主题消费四个只读接口 |
| 不读业务表 | 统计层不认识「文章 / 评论 / 账号」 | 业务层通过 `metric.collect` 自报存量 |
| 不存单请求明细 | 一天千万行，查询要扫、清理要锁、备份要哭 | 明细归日志；指标只留小时聚合 |
| 不阻塞请求 | 统计是旁路，不能把业务弄挂 | 写入失败 → 标记 degraded + `error_log`，请求照常返回 |
| 不猜测未知指标 | 拼错的名字会变成永远查不到的黑洞 | 白名单 + 显式丢弃计数 `metric_dropped` |

删掉 `systems/Metric/` 后，业务语义与其它层装配完全不变：采集全部挂在既有钩子上，没有一处业务代码调用统计层。

---

## 2. 三类指标

| 类型 | 语义 | 写入方式 | 读法 | 例 |
| --- | --- | --- | --- | --- |
| `counter` | 只增不减的累计量 | `hits += delta` | 窗口内求和 | 请求数、错误数、事件数 |
| `gauge` | 某一时刻的存量 | 同一小时**覆盖**，不累加 | 取最后一次 `total` | 文章数、评论数、账号数 |
| `histogram` | 观测值落在哪个桶 | 每个观测一行，`hits=1`、`total=值` | 桶累计 → 分位数 | 请求耗时、SQL 条数 |

**为什么仪表必须覆盖**：如果把「文章总数」当计数器累加，每采集一次就翻倍，看板会给出一个荒谬但看起来很确定的数字。这是典型的「安静地错」，所以在 `MetricRecorder` 里按类型分派，而不是让调用方自己记住。

**分位数是桶内近似**：桶上界为 5 / 10 / 25 / 50 / 100 / 250 / 500 / 1000 / 2500 / 5000 / 10000 / +Inf（毫秒或条数）。P95 返回的是「第 95% 个样本落在哪个桶的上界」，响应显式带 `approx=true`，不把 5ms 桶的答案说成精确值。

---

## 3. 指标字典

内置 **18** 个指标，完整清单以 `GET /api/v1/stats/catalog` 为准（字段：`name / kind / unit / labels / source / desc`）。按来源分四组：

| 通道 | 指标 | 类型 | 标签 |
| --- | --- | --- | --- |
| HTTP | `http_requests` | counter | `method,status` |
| HTTP | `http_errors` / `http_client_errors` | counter | `method` |
| HTTP | `http_duration_ms` | histogram | `method` |
| API | `api_calls` | counter | `operation,code` |
| API | `api_duration_ms` | histogram | `operation` |
| 数据 | `db_queries` / `db_duration_ms` | histogram | 无 |
| 业务 | `content_posts` / `comment_total` / `user_accounts` | gauge | `status`（账号无标签） |
| 业务事件 | `content_created` / `comment_created` / `comment_deleted` / `comment_moderated` / `attachment_uploaded` / `member_xp_awarded` | counter | 视事件而定 |

另有自监控指标 `metric_dropped{reason}`：记录「本该采集却被拒绝」的次数。**没有它，指标缺失永远是无声的。**

---

## 4. 存储模型

一张表，半小时粒度，靠唯一键 UPSERT 累加：

```sql
CREATE TABLE wx_metric_points (
  bucket_at  DATETIME     NOT NULL,   -- 小时对齐
  name       VARCHAR(64)  NOT NULL,
  dimension  VARCHAR(255) NOT NULL,   -- 规范化标签串，如 method=get,status=200
  kind       VARCHAR(16)  NOT NULL,   -- counter / gauge / histogram
  le         VARCHAR(16)  NOT NULL,   -- 直方图桶上界；其它类型为空
  hits       BIGINT       NOT NULL,   -- 次数 / 命中数
  total      DOUBLE       NOT NULL,   -- 观测值之和；gauge 存数值本身
  updated_at DATETIME     NOT NULL,
  PRIMARY KEY (bucket_at, name, dimension, kind, le)
)
```

- **维度字符串必须确定性**：`MetricLabels::format()` 按键排序、值小写并收窄字符集。标签顺序不同却写成两行，会让求和静默偏低。
- **一次请求一次事务**：6 个采样点要么全落，要么全不落，不会出现「请求记了、耗时没记」的半条数据。
- **表体量**：`指标数 × 维度数 × 24 × 保留天数`。默认 30 天，由 `prune` 裁剪；不按请求数增长。
- **降级**：库不可用时切 `MemoryMetricStore`，`durable=false`。这样「统计为 0」与「库连不上导致统计为 0」不会长成同一张脸。

---

## 5. 采集链路

```
kernel.shutdown ──> http_requests / http_errors / http_duration_ms
api.executed   ──> api_calls / api_duration_ms
kernel.shutdown ──> db_queries / db_duration_ms（读既有 QueryProfiler）
业务事件        ──> content_created / comment_* / attachment_uploaded / member_xp_awarded
metric.collect ──> content_posts / comment_total / user_accounts（存量，按需查询）
```

- HTTP 耗时取「进入 `handle` → 渲染完成」，由内核传入；**不用 `REQUEST_TIME_FLOAT`**，否则 CLI / 测试会把进程启动时间记成请求耗时。
- 采样先在本进程按 `name|dimension|le` 合并，`kernel.shutdown` 时一次落库。
- 四个通道可分别关闭：`config.metric.channels.http/api/db/business`。关闭后业务语义不变，只是对应指标不再增长。

---

## 6. 查询接口

| 接口 | 路径 | 回答什么问题 |
| --- | --- | --- |
| `stats.overview` | `GET /api/v1/stats/overview` | 请求量、错误率、分位耗时、接口概览、业务存量 |
| `stats.series` | `GET /api/v1/stats/series` | 单个指标的趋势；未知指标返回 42201，不返回空序列 |
| `stats.operations` | `GET /api/v1/stats/operations` | 哪个 operation 忙 / 失败 / 慢 |
| `stats.catalog` | `GET /api/v1/stats/catalog` | 有哪些指标、什么类型、允许哪些标签 |

- `window` 只接受 `1h` ~ `30d`；`0h`、`31d`、`abc` 一律 42201，**不回退默认值**。
- 序列最多 2000 点，超出 `truncated=true`；操作排行最多 200 条，同样显式声明截断。
- 全部要求 `admin` + `metric.read` 权限。统计能还原流量结构，不是公开数据。

---

## 7. 扩展方式：业务存量与自定义指标

**业务存量**由归属系统自报，统计层不认识业务表：

```php
$kernel->hooks()->filter('metric.collect', function (array $samples): array {
    $samples[] = ['name' => 'content_posts', 'labels' => ['status' => 'published'], 'value' => $repo->count('published')];
    return $samples;
});
```

**自定义指标**（插件）在装配期追加字典，形状不合格的定义会被 `MetricCatalog::sanitize()` 丢弃：

```php
$kernel->hooks()->filter('metric.catalog', function (array $catalog): array {
    $catalog['newsletter_sent'] = [
        'kind' => 'counter', 'unit' => '封', 'labels' => ['list'],
        'source' => 'plugin:newsletter', 'desc' => '通讯订阅发送数',
    ];
    return $catalog;
});
```

字典是白名单：不在字典里的名字写不进去，并累加 `metric_dropped{reason=unknown_metric}`。

---

## 8. 基数治理

标签值来自运行期，必须收窄：小写、只保留 `[a-z0-9._:-]`、最长 48 字符、空值记为 `none`。

| 风险 | 治理 |
| --- | --- |
| 把用户 ID / URL 放进标签 | 标签键必须先在字典声明；未声明的标签被忽略并计入 `label_ignored` |
| 拼错指标名 | 白名单拒绝 + `unknown_metric` 计数 |
| 类型用错（把存量当计数器） | `kind_mismatch` 拒绝，不写入 |
| 维度数量缓慢膨胀 | `php tools/metrics.php verify` 对「同指标同小时 > 1000 维度」直接报 FAIL |

---

## 9. 运维命令

```bash
php tools/metrics.php report 24h    # 文本概览（可重跑证据，不截图）
php tools/metrics.php verify        # 存储是否持久 / 字典是否完整 / 基数是否失控
php tools/metrics.php prune 30      # 删除 30 天前的聚合点（建议每日定时任务）
```

`verify` 在以下情况退出码为 1：存储降级为内存、三类指标不齐、任一指标单小时维度数超过 1000。**「查不到」必须能变成「知道为什么查不到」。**

---

## 10. 失败模式（不安静地错）

| 故障 | 表现 | 是否影响业务 | 如何发现 |
| --- | --- | --- | --- |
| 数据库不可用 | `durable=false`，只有本进程数据 | 否 | overview 的 `collector.durable` / `metrics verify` |
| 写入失败 | `degraded=true`、`last_error` 保留原因 | 否 | 响应 `collector` / SAPI 日志 `metric flush failed` |
| 未知指标 | 不写入，`metric_dropped{reason=unknown_metric}` +1 | 否 | catalog 对照 / 序列 42201 |
| 标签未声明 | 该标签被忽略，`label_ignored` +1 | 否 | `metric_dropped` 序列 |
| 序列过长 | `truncated=true`，只返回上限内点数 | 否 | 响应字段 |
| 窗口非法 | 42201 | 否 | 调用方立即失败，而不是拿到 24h 的假数据 |

---

## 11. 代价

- 每请求固定成本：**1 次事务 + N 条 UPSERT**（默认 6~8 条），与业务查询量无关。
- 查询成本：overview 为 6 次聚合查询；operations 为 2 次聚合查询 + 内存分组。
- 关闭方式：`metric.enabled=false`（采集全停，接口仍可读历史）或按通道关闭。
- 这张表**不参与**业务事务：指标写入使用独立连接事务，业务回滚不会回滚统计。

---

## 12. 守卫

`tests/groups/34-metrics.php` 覆盖 12 项：

1. 字典三类齐备、名字 / 标签 / 描述合法，坏定义被拒绝；
2. 计数器累加、仪表覆盖、直方图分桶语义；
3. 未知指标 / 类型不符 / 非法标签全部计入 `metric_dropped`；
4. 分位数与均值（1..100 样本）；
5. 窗口解析拒绝非法值；
6. HTTP 埋点（200 / 404 / 5xx）；
7. API 埋点（调用量 / 业务码 / 分位耗时）；
8. 业务存量字段最小化；
9. 序列未知指标 42201、升序、`truncated` 显式；
10. 未认证 40101；
11. 持久化写入 / 读回 / 只裁旧数据 / `durable` 标识；
12. 写入失败不反噬业务且保留原因。

事实对账把本文的「**18** 个内置指标」纳入 `php tools/facts.php verify`，新增指标不同步文档会直接报漂移。
