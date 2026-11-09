# 本地开发环境

本文记录这台开发机上**已经就绪**的运行环境、SSH 接入方式与浏览器配置。环境由系统层维护，**不属于项目代码**；项目本身仍然只依赖 PHP + MySQL，不因本文增加任何运行时依赖。

---

## 一、运行时版本

| 组件 | 版本 | 位置 / 说明 |
| --- | --- | --- |
| PHP | 8.3.6（cli） | `php`，扩展含 PDO MySQL、mbstring、openssl |
| MariaDB | 10.11 | `127.0.0.1:3306`，`service mariadb start` 启动 |
| Node.js | 22.13.1 | `node` / `pnpm` |
| Git | 已初始化 | 仓库根 `/workspace`，默认分支 `main` |
| 中文字体 | Noto Sans / Serif CJK | 截图与 PDF 中文不缺字 |
| Emoji 字体 | Noto Color Emoji 2.047 | 彩色 emoji 正常渲染 |

验证：

```bash
php -v && php -m | grep -E 'pdo_mysql|mbstring'
mysql -uroot -e 'select version()'
node -v && git -C /workspace log --oneline -1
```

---

## 二、SSH 远程接入

### 2.1 当前配置

| 项 | 值 |
| --- | --- |
| 服务端 | OpenSSH 9.6p1（`openssh-server`） |
| 监听 | `0.0.0.0:22` |
| 配置文件 | `/etc/ssh/sshd_config.d/10-workspace.conf`（drop-in，不改主配置） |
| 认证 | **仅公钥**；`PermitRootLogin prohibit-password`，密码登录已关闭 |
| 账号 | `root` |
| 私钥 | `/root/.ssh/wanxiang_dev`（0600） |
| 公钥 | `/root/.ssh/wanxiang_dev.pub`（已写入 `authorized_keys`） |
| 指纹 | `SHA256:vgqAU7x6I0d3D63O57NK4Zj9w2oqZn/3PsZDEMw9kyQ` |

放行的能力：`AllowTcpForwarding yes`、`AllowAgentForwarding yes`、SFTP 子系统 —— VS Code Remote-SSH 与端口转发可直接使用。未开启 X11 与 tun 隧道。

### 2.2 连接方式

本机自测（已验证通过）：

```bash
ssh -i /root/.ssh/wanxiang_dev root@127.0.0.1
```

从开发机外部连接时使用沙箱地址与映射端口：

```bash
ssh -i <下载的私钥> -p <平台映射端口> root@<平台域名或地址>
```

> **可达性说明（诚实标注）**：沙箱的 22 端口目前只保证**机内可达**，平台的 HTTP 预览通道（`*.sandbox.cloudstudio.club`）不转发 SSH。要从公网直连，需要在平台侧为该实例做 TCP 端口映射；映射完成后用上面的命令即可，无需改动 sshd。VS Code 等工具若走平台自带通道，则不需要额外映射。

### 2.3 休眠 / 重启后恢复

沙箱休眠会清掉 `/run/sshd` 与进程，配置和密钥保留。恢复只需一条命令（脚本在 `/usr/local/bin`，**不在项目内**）：

```bash
wx-ssh-setup
```

它是幂等的：缺 `/run/sshd` 就建、缺密钥就生成、公钥没授权就补、进程没起就起，最后打印监听状态。重复执行不会产生重复的 `authorized_keys` 条目。

### 2.4 安全边界

- 禁用密码与键盘交互认证，爆破面为零；新增设备必须显式把公钥追加到 `/root/.ssh/authorized_keys`。
- 私钥只存在于本机与用户手中，**不入库**（`.gitignore` 已排除 `config/local.php`、`storage/install.*`；密钥本身在 `/root` 之外的项目目录之外）。
- 如需临时开放密码登录，改 drop-in 里的 `PasswordAuthentication yes` 并 `sshd -t && service ssh reload`；用完改回。

---

## 三、浏览器

三套浏览器各有明确分工，版本都已与驱动对齐：

| 用途 | 实现 | 版本 | 命令 |
| --- | --- | --- | --- |
| **模型操作（默认）** | `agent-browser` CLI + 自带 Chrome | 0.27.0 / Chrome 151.0.7922.76 | `agent-browser open <url>` |
| **脚本化回归 / 截图** | Playwright 1.58.1 + 自带 Chromium | Chromium 145.0.7632.6（revision 1208） | `chromium.launch()` |
| **真实 Chrome 环境** | 系统 Google Chrome + chromedriver | Chrome 144.0.7559.132 / 驱动同为 144 | `channel: 'chrome'` |

- Playwright 浏览器缓存在 `/root/.cache/ms-playwright/`（`DEPENDENCIES_VALIDATED` 已通过），不需要再跑 `playwright install`。
- `chromedriver` 位于 `/usr/local/bin/chromedriver`，与系统 Chrome **大版本严格一致**（144）。
- 系统 Chrome 以 root 运行时必须带 `--no-sandbox`；Playwright 会自动处理，手工命令行需要显式加。
- 无头截图的中文与彩色 emoji 均已验证，不再出现「豆腐块」。

常用验证：

```bash
agent-browser --version && chromedriver --version && google-chrome --version
google-chrome --headless=new --no-sandbox --disable-gpu \
  --screenshot=/tmp/shot.png --window-size=1280,800 http://127.0.0.1:8090/architecture.html
```

> 容器内没有 D-Bus，Chrome 启动时会打印 `Failed to connect to the bus`；这是无害告警，截图与页面渲染不受影响。

### 3.1 与本项目的配合

```bash
# 接口（headless 模式，返回 JSON）
php -S 127.0.0.1:8080 -t public
curl -s http://127.0.0.1:8080/api/v1/system/info

# 文档站（架构页等静态文件）
php -S 127.0.0.1:8090 -t docs
```

浏览器打开 `http://127.0.0.1:8090/architecture.html` 可核对最新架构图；打开 `http://127.0.0.1:8080/` 得到的是 JSON —— 框架默认零呈现，样式全部来自主题包。

---

## 四、日常命令

```bash
php tests/run.php                    # 全量守卫（当前 240 项 / 47 组）
php tools/audit-responsibility.php   # 单文件职责审计，非 0 退出即失败
php tools/data.php verify            # 只读孤儿巡检（stats 看表体量）
php tools/gen-api-docs.php           # 重新生成 67 份接口文档
git status && git log --oneline -5   # 版本基线
```

环境层面的排障顺序：`wx-ssh-setup` → `service mariadb status` → `php -v` → `agent-browser --version`。

---

## 五、版本控制与远程

| 项 | 值 |
| --- | --- |
| 远程 | `origin` → <https://github.com/Lisir2002/Mini_Blog.git> |
| 分支 | `main`，跟踪 `origin/main` |
| 可见性 | Public |
| 凭据 | GitHub CLI OAuth（`~/.config/gh/hosts.yml`），git 通过 `gh auth git-credential` 取用 |
| 当前规模 | 328 个文件 / 3 次提交 |

**不入库的内容**（`.gitignore` 已覆盖）：`storage/` 除骨架外的全部运行时产物、`storage/install.token`、`storage/install.lock`、`config/local.php`、`docs/responsibility-report.json`。

日常推送：

```bash
git add -A
git commit -m "type: 说明"
git push origin main
```

**凭据说明**：OAuth 令牌保存在沙箱的 `~/.config/gh/hosts.yml`（明文，权限靠目录 0700 保护），不要把它或 `/root/ssh-access/wanxiang_dev` 复制进仓库。换机器时重新执行 `gh auth login` 即可，不要把 `hosts.yml` 当配置分发。

> 仓库是公开的：`admin/admin123` 一类只出现在文档与测试夹具里，**部署前务必改掉默认账号**；真实站点配置写在 `config/local.php`，该文件已被忽略。

