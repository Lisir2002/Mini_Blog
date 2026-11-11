# 接口消费方矩阵

`tools/consumers.php` 回答一个问题：**哪个接口有人用，哪些只是「存在」？**

## 为什么需要

前几版反复出现同一种断裂，而且是靠人肉翻 `api/registry.json` 才发现的：

| 版本 | 能力已实现 | 断裂 |
| --- | --- | --- |
| v0.29 | `comments.queue` / `comments.moderate` | 举报进了队列，站上没人看得见 |
| v0.25 | `content.posts` 的 `keyword` | 搜索从上线起 500，前台也没有入口 |
| v0.28 | 四态与五个生命周期接口 | 页面上一个字都写不了，只能 curl |
| v0.33 | `attachments.list` | 附件传了，文章页不显示 |

每一次都是「能力完备、闭环缺失」。人工检查不可持续，所以把它变成一个可重跑的查询。

## 怎么用

```bash
php tools/consumers.php          # 全量矩阵
php tools/consumers.php --zero   # 只看零消费
php tools/consumers.php --json   # 机器可读（守卫用）
```

输出把消费方分成两类：

- **确证调用**（`<-`）：源码里出现 `execute('op')`，或前端脚本引用了该接口路径；
- **字符串引用**（`<- ~`）：操作名作为字面量出现，但没有直接 `execute` ——
  典型是 `$intent === 'reply' ? 'comments.reply' : 'comments.add'` 这类动态分派。
  标成引用而不是消费，是为了不把「提到过」当成「用上了」。

**零消费**只列两类都没有的 operation。

## 扫描范围

只扫「会真正调用接口的地方」：`pages/`、主题的 `assets` 脚本、`tools/`。
系统层自己 `registerOperation` 不算消费方 —— 那是实现，不是使用。
测试与旅程脚本也不计入：它们调用接口是为了验证，不是因为站点需要这个能力。

## 怎么读结果

- 零消费不都是缺陷：`system.*`、`lifecycle.*`、`permission.*` 属于运维面，
  由 CLI / 监控 / 安装器使用，本来就没有前台页面；
- 但**对外承诺过的能力**出现在零消费里，就是闭环断了。v0.33 之前
  `comments.queue`、`content.transition`、`attachments.list` 都在这一栏。

## 证据

`49` 组守卫：矩阵覆盖全部 operation、本轮新接的页面确实被算作消费方、
零消费与确证消费互斥、`--zero` 不混入已消费段。
