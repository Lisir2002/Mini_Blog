# 附件库（`/console/attachments`）

> v0.43 接通 `attachments.update` / `attachments.delete` / `attachments.download` 三个零消费接口，
> 并补上「选中某篇文章」的浏览路径 —— 没有它，附件库根本无从谈起。

## 1. 为什么必须先选文章

`attachments.list` 的 `post_slug` 是**必填**的，系统里没有「列出全站附件」的接口。
所以「后台附件库」不是一个列表页，而是三段式：

```
选文章（content.posts, status=any） → 看这篇的附件（attachments.list） → 选一条来改（?id=）
```

把这段路径补上，才谈得上删除 / 改名 / 受控下载。文章清单来自 `content.posts`，
最多 20 篇；回收站里的文章不在这个清单里（要用 `content.trash`，本页不做）。

两个容易说错的地方：

- 列表按**当前身份**取数（传 `_identity`）。`attachments.list` 会把私有附件过滤掉，
  文章页刻意传 `null`（公开读路径），后台页照抄就会让站长以为「附件不见了」；
- `total` 是**当前身份可见的附件数**，不是库内总数。页面标签写「当前身份可见 N 条」，
  空态说明「也可能被挂到别的文章、或已经软删除」——系统没有跨文章清点附件的接口。

## 2. 五个动作，一张表

`?id=` 选中一条附件后，渲染一张表（`AttachmentsForms`），五个提交按钮即状态机：

| 按钮 | 接口 | 参数 | 页面层额外校验 |
| --- | --- | --- | --- |
| 改名 | `attachments.update` | `name` | 非空、≤120 字符；正文按名字引用旧名时要求手写确认 |
| 设为公开 | `attachments.update` | `visibility=public` | 枚举由按钮给出，不接受自由输入 |
| 设为私有 | `attachments.update` | `visibility=private` | 同上 |
| 改挂载文章 | `attachments.update` | `post_slug` | 目标文章必须 `content.post` 读得到 |
| 删除 | `attachments.delete` | `id` | 手写 id 确认 + 正文引用检查 |

`op` 只能由按钮给出，缺失一律 422 —— 默认成某个动作等于让「没点按钮的请求」悄悄改配置。
可见性尤其不能让人手填：接口把**任何非 `private` 的字符串**都落成 `public`，
把 `Private` 打成 `privat` 会静默公开一个私有附件，且不报错。

## 3. 正文引用检查（删之前必须问）

`attachments.delete` 是软删除：`listForPost` 按 `deleted_at IS NULL` 过滤，
`AttachmentBinary::download` 也按 `deleted_at` 返回 404。
文章正文里若还写着 `/attach/download?id=N`，删完就是一个坏链接，而接口层完全不检查正文。

`AttachmentsRefs` 做两种**可解释的字面匹配**：

- `by_url`：正文含 `/attach/download?id=N`（id 是下载的唯一定址方式）；
- `by_name`：正文含附件展示名，且名字 ≥ 4 字符（避免把常见词误判成引用）。

| 动作 | 命中引用时 | 理由 |
| --- | --- | --- |
| 删除 | **硬挡 422** | 软删后链接 404、列表消失，且没有撤销入口 |
| 改名 | 命中 `by_name` 时要求手写确认，并在结果里提醒旧名仍在正文 | 改名不影响 URL，但按名字引用会断；不阻断合理操作 |
| 改挂载 | 不拦，但结果里提醒 | URL 按 id 不变，链接仍可用，只是不再属于这篇文章的清单 |

这是**启发式，不是结构化引用表**：正文可能是 Markdown / HTML / 纯文本，系统里没有引用关系。
所以它只用来「拦住明显会坏的操作」，不能反过来证明「没有引用」。
因此 **读不到正文时一律 fail-closed**：删除 / 改名直接拒绝，并明说「查不到不等于没引用」。
这条边界由守卫覆盖：页面在 `post_state !== found` 时不渲染任何写入口。

## 4. 页面层挡住的另外两类脏数据

1. **改挂到不存在的文章**。`attachments.update` 只 `trim()` 一下 `post_slug` 就写库，
   而系统没有「列出全站附件」的接口 —— 挂错之后这条附件再也找不回来。
   页面先调 `content.post(target_slug)` 确认存在（回收站 / 未到点的定时文章同样算不存在），
   读不到就 422 拒绝；
2. **展示名被静默截断**。`AttachmentGuard::displayName()` 会把名字切到 120 字符且不报错，
   「改名成功」会掩盖名字变了。页面先数字符数（mbstring 缺席时用 `preg_split('//u')` 兜底，
   保持字符数而非字节数口径），超长直接 422。

另外补了一个字段缺口：`attachments.list` 的 `present()` 原先不返回 `uploader_id`，
后台无法解释「为什么这条能改 / 不能改」。v0.43 补上（不改权限点总数，仍是 49）。

## 5. 受控下载：只给链接，不代理字节

`/attach/download` 是**唯一不经 JSON 信封的二进制通道**：`AttachmentRoute` 复用 `RequestGuard`
后直接把字节（或分块流）写进响应，私有附件每次都二次鉴权，并带
`Content-Security-Policy: default-src 'none'; sandbox` 与 `X-Content-Type-Options: nosniff`。

页面**绝不 `execute('attachments.download')`** —— 那会把文件字节或磁盘路径读进页面层，
既破坏分层又可能把大文件整个驻留内存。正确接法是渲染 `/attach/download?id=N` 链接：

- 每行一个下载链接，图片额外给 `&disposition=inline` 内联预览（仍带 CSP sandbox）；
- 私有附件标注「需登录，无 `attachment.read` 会 403」；
- 守卫用一条哨兵字符串证明页面 HTML 里**没有**文件字节。

消费矩阵为此新增了「链接消费」识别：只认 `execute()` 的话，这类接口会被永远列成零消费，
等于逼着下一个人去写一个错误的调用方。现在页面里出现非 `/api/v1` 前缀的接口路径字面量，
也算作消费方（与 JS 的 `path:` 落在同一个 `@path` 桶）。

## 6. 权限：读一个点，写另一点

页面声明 `attachment.read`（与 `member.read` / `content.read` 同构：**能看不等于能改**），
票据按它签发；五个写动作在 `AttachmentsAction` 里现场问 `attachment.manage`，
`AttachmentOperations` 内部还会再判一次（含「上传者本人 + `attachment.edit.own` / `delete.own`」）。

读写分离不是形式主义：若页面级与写动作都声明 `attachment.manage`，
守卫已经挡住的越权请求根本到不了 Action，页面层的二次裁决就退化成重复检查、也无法被守卫验证。
`59` 组「撤掉 manage 只留 read：GET 仍 200、POST 403」证明的是**纵深防御真的存在**。

## 7. PRG 与结果回报

成功一律 **303** 回到当前文章（改挂载时回到新文章清单，且不把旧 `id` 带回去渲染「找不到」）。
**失败绝不 303**：表单回填，页面上给出失败提示条。

结果按 `outcome` 分开说：`rename` / `public` / `private` / `move` / `delete` / `already`。
`already=true`（此前已删除）单独给一句「本次没有变化」，不混进「删除成功」。
每条结果都带上处置信息，例如改名说明「下载 URL 不变」、删除说明「字节仍在磁盘，
等引用计数为 0 才回收」、改挂载说明「原文章清单里不再列出它」。

表单的 `action` 带回 `?slug=&id=`：页面靠 GET 查询参数选文章与附件，
POST 走同一个 URL 才能重新取到同一份清单；票据只绑路径、不绑查询串，不受影响。

## 8. 测试

`tests/groups/59-attachments-runtime.php`（10 条）：匿名 / 文章清单 / 选中文章后的
清单、下载链接、私有标注、正文引用标注与「页面不含文件字节」；`op` 必须由按钮给出；
改名与超长拒绝、按名字引用改名要确认；可见性两个枚举与匿名清单过滤；
改挂载目标存在性；删除前正文引用硬挡与清掉引用后成功；权限隔离（read 可看、manage 才能写）；
方法边界与失败回填。

隔离站点 + 独立表前缀，上传的字节落在 `/tmp` 的 storage，结束不触碰工作区数据。

## 9. 边界

- 不做跨文章清点 / 全站搜索（没有对应接口）；
- 不做精确引用解析（只做字面匹配，读不到正文就拒绝删除）；
- 不做字节回收、不做撤销删除（软删后的恢复没有接口）；
- 不代理下载字节，不做上传表单（上传是独立的二进制通道）；
- 不做批量操作；文章清单最多 20 篇，深翻页留给下一版。
