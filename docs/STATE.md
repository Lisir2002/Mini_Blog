# Mini Blog 状态系统（v0.12 · 第 15 层）

> 一句话：**错误有码，但没有态**——状态系统补的是「现在到哪一步、卡在谁手里、为什么、下一步能做什么」。

## 1. 它解决什么问题

框架原本有业务码（`40301`、`40901`、`42201`）、HTTP 状态、安装步骤的 `done` 布尔、表格行的 `ok` 标记。这些都能表达「错」或「对」，但表达不了：

| 缺失的表达 | 真实场景 |
| --- | --- |
| 进行中 | 建表跑了 3/5 步，用户不知道还要多久 |
| 等人 | 安装器等站长填数据库；发布等作者确认 |
| 等系统 | 等队列、等第三方回调、等锁释放 |
| 为什么 | 只显示「操作失败」，不说明是权限、网络还是输入问题 |
| 下一步 | 没有重试 / 返回 / 联系管理员的出口 |
| 可追踪 | 用户报障说不出是哪一次请求 |

`systems/Lifecycle/StateMachine` 管的是**单元生命周期**（`absent → installed → active`），属于装卸元层；状态系统管的是**交互反馈**。两者共享「状态机」这个思路，但语义、生命周期、消费者都不同，不合并。

## 2. 五态：四个相位 + 一个持有者维度

五态不是五个并列枚举，而是 `phase` 与 `held_by` 的组合：

| 状态 | `phase` | `held_by` | 含义 | HTTP | 典型场景 |
| --- | --- | --- | --- | --- | --- |
| **进行态** | `running` | `null` | 正在执行，不要重复提交 | 202 | 建表、迁移、发布 |
| **完成态** | `done` | `null` | 已结束且成功 | 200 | 保存成功、升级完成 |
| **错误态** | `failed` | `null` | 已结束且失败，带业务码 | 4xx / 5xx | 校验失败、权限不足、数据库不可用 |
| **等待态** | `waiting` | `system` | 等外部条件，通常不需要人介入 | 202 | 等队列、等第三方回调 |
| **交互态** | `waiting` | `user` | 等人做决定或补输入 | 202 | 确认删除、填数据库、选择分支 |
| （待启动） | `pending` | `null` | 已受理、尚未开始 | 202 | 异步任务刚入队 |

为什么这样分：如果五个并列，遇到「正在跑但停下来等人确认」就必须二选一；拆成两个字段后，它天然是 `running` 之后进入 `waiting + user`，语义不丢。

```text
pending ──▶ running ──▶ done
              │  ▲
              │  └──────────────┐
              ▼                 │
           waiting ─────────────┘
           ├─ held_by=system  → 等待态
           └─ held_by=user    → 交互态
              │
              ▼
           failed
```

## 3. 状态信封

一个状态就是一个稳定结构，主题、模型、日志读的是同一份：

```json
{
  "phase": "failed",
  "held_by": null,
  "scope": "action",
  "id": null,
  "code": 50001,
  "key": "state.error.internal",
  "title": "操作没有完成",
  "message": "服务器内部错误，本次请求未被处理。",
  "hint": "可以重试；若持续失败，请把追踪编号提供给管理员。",
  "progress": null,
  "actions": [
    {"key": "retry", "label": "重试", "kind": "primary", "href": "", "method": "POST"}
  ],
  "evidence": {
    "trace_id": "9f1c2a7b4e6d8013",
    "at": "2026-11-09T18:30:00+08:00",
    "retryable": true
  },
  "revision": 0
}
```

| 字段 | 说明 |
| --- | --- |
| `phase` | `pending` / `running` / `waiting` / `done` / `failed` |
| `held_by` | 仅 `waiting` 非空：`system`（等待态）/ `user`（交互态） |
| `scope` | `page`（整页）/ `action`（单次提交）/ `task`（跨请求长任务） |
| `id` | `scope=task` 时必填，其余为 `null` |
| `code` | 仅 `failed` 非零；沿用既有业务码，不新造一套 |
| `key` | 文案键，机器可分支、主题可覆写 |
| `title` / `message` / `hint` | 说明三件套：发生了什么 / 为什么 / 该怎么办 |
| `progress` | `{done,total,unit,label}`；只有进行态与等待态有意义 |
| `actions` | 出路：最多 4 个，`key` 唯一，只允许站内链接 |
| `evidence` | 证据：追踪编号、发生时间、能否重试 |
| `revision` | 乐观锁版本；非持久化状态恒为 0 |

### 三条硬不变量

在 `State::create()` 里一次性裁决，页面与系统层都无法绕过：

1. **只有失败态可以携带业务码**，且必须是 4xx / 5xx；
2. **只有等待态可以携带 `held_by`**；
3. **只有失败态可以标记 `retryable`**。

另外：完成态的进度必须走满；`scope=task` 必须有 `id`；动作最多 4 个且链接必须是 `/` 或 `#` 开头（挡掉 `javascript:` 与站外跳转）。

## 4. 文案：键是契约，中文是兜底，外观归主题

| 层 | 负责 | 位置 |
| --- | --- | --- |
| 核心（状态层） | 提供 `key` + 默认中文（`state.*`） | `systems/State/StateCopy.php` |
| 主题 / 插件 | 通过 `state.copy` 钩子覆写任意条目 | 主题包 `register()` |
| 页面 | 就地覆写 `title` / `message` / `hint` / `actions` | `StateFactory::…(['text' => [...]])` |

内置键：

```text
state.pending                已受理
state.running                正在处理
state.waiting.system         等待外部条件
state.waiting.user           需要你确认
state.done                   已完成
state.error.validation       填写内容有误
state.error.auth             需要先登录
state.error.permission       没有执行权限
state.error.not_found        内容不存在
state.error.conflict         状态已经变化
state.error.rate_limit       操作太频繁
state.error.unavailable      依赖暂时不可用
state.error.internal         操作没有完成
state.install.preflight_failed
state.install.need_input
state.install.installed
```

覆写示例：

```php
$kernel->hooks()->filter('state.copy', static function (array $table): array {
    $table['state.error.permission']['message'] = '这个操作需要「站点管理员」角色。';
    return $table;
}, 10);
```

## 5. 三种作用域

| 作用域 | 生命周期 | 载体 | 典型用法 |
| --- | --- | --- | --- |
| `page` | 一次渲染 | `payload.state` | 空列表、无权限、安装未就绪 |
| `action` | 一次提交 | `payload.state` 或接口信封 | 表单校验、保存失败、需要确认 |
| `task` | 跨请求 | `wx_state_tasks` 表 / 内存 | 安装、迁移、发布、批量导入 |

`page` 与 `action` **不落库**：它们是这一次响应的事实，刷新就该重算。只有 `task` 需要跨请求，才进入 `StateTasks`。

## 6. 长任务：乐观锁与终态不可逆

表 `wx_state_tasks` 归状态层自持（`systems/State/migrations/`），核心零表、零 SQL。

| 规则 | 实现 |
| --- | --- |
| 新建 | `revision=0` → INSERT，写入后 `revision=1` |
| 推进 | `UPDATE … WHERE id=? AND revision=?`；命中 0 行 → `40901` |
| 终态 | `done` / `failed` 之后 `StateTasks::advance()` 直接拒绝 |
| 过期 | `expires_at`；`sweep()` 清理，默认 TTL 86400 秒（`config.state.task_ttl`） |
| 无库 | 自动退化为 `MemoryStateStore`，同进程内可见，跨进程不可见 |

并发语义是「**宁可报冲突，也不静默覆盖**」：两个 worker 同时推进同一任务，后到的那个必须重新读取最新状态再决定。

## 7. 呈现：主题必须给的可访问性语义

主题拿到 `payload.state` 后按自己的设计渲染，但必须满足：

| 要求 | 具体 |
| --- | --- |
| 错误用 alert | `role="alert"` + `aria-live="assertive"` |
| 其余用 status | `role="status"` + `aria-live="polite"` |
| 进行中标记 | `aria-busy="true"` |
| 进度可读 | `<progress value max>` 或 `aria-valuenow/min/max` |
| 动作可操作 | 真实链接或表单；无 JS 时也能完成 |
| 焦点归位 | 失败后焦点回到出错控件或状态标题 |
| 不依赖颜色 | 文字说明必须能独立表达状态 |
| 尊重减弱动画 | `prefers-reduced-motion` 下停用脉冲 |

参考实现：`themes/Bare/templates/partials/state.php`、`themes/Bare/assets/state.{css,js}`。CSS/JS 只存在于主题包（规矩 J），核心不产出任何标签。

无 JS 时的行为：

- 进行态与等待态是静态说明；
- 交互态的动作渲染为真实 `<a>` 或 `<form method="post">`；
- 只有「无 href 的按钮」才依赖脚本，且脚本失败不影响页面可读。

## 8. 接入方式

### 页面产出状态

```php
/** @var \Wx\Systems\State\StateFactory $states */
$states = $kernel->container()->resolve(StateFactory::class);

$response->setPayload([
    'title' => '导入文章',
    'state' => $states->running('state.running', [
        'progress' => ['done' => 3, 'total' => 10, 'unit' => '篇', 'label' => '正在写入'],
        'actions'  => [['key' => 'cancel', 'label' => '取消', 'kind' => 'danger', 'href' => '/import', 'method' => 'POST']],
    ])->toArray(),
    'blocks' => [],
]);
```

### 失败统一出口

```php
try {
    $installer->migrate();
} catch (RuntimeException $e) {
    $response->setStatus(500)->setPayload([
        'state' => $states->fromThrowable($e)->toArray(),
    ]);
}
```

`fromThrowable()` 不会把异常原文放进界面：SQL、路径、令牌片段只进日志，界面只给稳定文案与 `trace_id`。

### 长任务

```php
/** @var \Wx\Systems\State\StateTasks $tasks */
$tasks = $kernel->container()->resolve(StateTasks::class);

$task = $tasks->open('release.upgrade', State::create([
    'phase' => 'running', 'scope' => 'task', 'id' => 'upgrade-20261109',
    'title' => '正在升级', 'progress' => ['done' => 0, 'total' => 4, 'unit' => '步'],
]));

$tasks->advance($task->with(['progress' => ['done' => 2, 'total' => 4, 'unit' => '步']]));
```

### 接口读取

`GET /api/v1/state/task?id=…`（`state.task.status`，admin）返回同一份状态信封。任务不存在 `40403`，id 非法 `42201`。

## 9. 未捕获异常的边界

`Kernel::run()` 捕获 `Throwable`，发出 `kernel.exception`：

1. 状态系统订阅该事件，把异常翻译成失败态并返回 `Response`；
2. 核心继续走主题渲染——异常页和普通页用同一套布局，不另做一套；
3. **没有订阅者**（状态系统被停用）时，核心只输出纯文本 `500 Internal Server Error`，不产出任何标签；
4. 如果渲染阶段再次抛异常，同样退回纯文本，避免递归。

`kernel.exception` 被列入裁决型事件（`CollaborationGraph::DECISION_EVENTS`）：没有消费者的装配会被审计报为孤儿。

## 10. 与其它系统的边界

| 系统 | 关系 |
| --- | --- |
| `lifecycle` | 它管单元生命周期；状态系统不参与装卸决策，只表达结果 |
| `api` | 复用同一套业务码；状态信封不替代统一响应信封，`fromEnvelope()` 负责翻译 |
| `release` | 升级 / 回退可把进度写入长任务；状态系统不知道「版本」是什么 |
| `permission` | 权限拒绝由 `403xx` 表达，状态系统只负责把它翻译成可读、可行动的态 |
| `theme` | 主题全权决定长相；状态层只给结构与键 |

## 11. 守卫

| 组 | 覆盖 |
| --- | --- |
| `19a-state-model.php` | 五态组合、业务码约束、动作白名单、进度边界、不可变、文案覆写、异常 / 信封翻译 |
| `19b-state-runtime.php` | 长任务创建/推进/终态拒绝、CAS 冲突、TTL、安装页真实状态、无主题降级、主题片段可访问性、异常出口、停用降级、只读接口 |
| `02-core-static.php` | 核心仍 24 个 PHP 文件、零 CSS/JS |
| `12a/12b-collaboration-*` | `kernel.exception` 有消费者、无孤儿协作 |

运行：

```bash
php tests/run.php
```
