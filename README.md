# 万圣节大转盘抽奖系统（PHP + Workerman）

一个无需 Apache/Nginx 的纯 PHP + Workerman 抽奖应用：大转盘抽奖、奖项/价值/名称/多图全部后台可配、访客通过专属链接获得预设抽奖次数（转盘仅做动画展示，结果由后台预设决定并记录到奖品栏）。

---

## 一、功能对照需求

| 需求 | 实现 |
|------|------|
| 1. 大转盘抽奖 | 前端 Canvas 转盘，旋转动画 + 指针停在预设奖项 |
| 2. 奖项等级/名称/价值/多图后台设置 | 后台「奖项设置」可增删改；前台只渲染占位（所有文案来自数据库） |
| 3. 默认无抽奖次数，提示联系客服 | 无有效链接访问 `/` 显示「您还没有抽奖机会」+ 客服联系方式（后台设置） |
| 4. 后台生成抽奖链接，可设次数与每次预设奖品 | 后台「抽奖链接生成」：设 N 次、每次抽中哪个奖项；链接发出后访客获得对应机会，结果存入奖品栏 |
| 5. PHP + Workerman，域名解析到服务器 | Workerman 作为独立 HTTP 服务，由 Caddy 反代并自动签发 HTTPS（详见第四节） |
| 6. 万圣节抽奖须知 | 后台「站点设置」可编辑须知文案，前台「抽奖活动须知」折叠展示 |

**特等奖限量逻辑**：奖项可标记「限量」（如特等奖总量 1）。当该奖项已被某链接抽走后，其它预设它的链接会自动退回兜底奖（参与奖），并在结果里标记 `exhausted`。

---

## 二、环境要求

- PHP ≥ 8.1（已测试 8.2）
- 已安装 Composer
- PHP 扩展：`pdo_sqlite`、`gd`（图片处理可选）
- 一台可长期运行 PHP 进程的服务器/VPS（无需 Nginx/Apache，由 Caddy 反代并自动签发 HTTPS 证书，详见第四节）

---

## 三、安装与启动

```bash
# 1. 安装依赖（会拉取 workerman/workerman）
composer install

# 2. 启动（前台）
php start.php start
# 或守护进程（后台）
php start.php start -d
# 停止 / 重启
php start.php stop
php start.php restart -d
```

- 默认监听端口 `8080`（可用环境变量 `PORT` 覆盖）。
- 首次访问会自动在 `data/lottery.db` 建立 SQLite 库，并写入示例奖项、默认客服号、万圣节须知文案。
- **后台地址**：`/admin`，默认密码 `admin888`（请在「站点设置」中立即修改）。

### 本地快速预览

```bash
PORT=8088 php start.php start -d
# 前台: http://127.0.0.1:8088/
# 后台: http://127.0.0.1:8088/admin
```

---

## 四、部署到服务器（Ubuntu/Debian 一键脚本 + Caddy）

> ⚠️ **架构要点**：Cloudflare 边缘网络只运行 JavaScript，**无法运行 PHP**；因此本系统在你的 VPS 上用 **Workerman 作为独立 HTTP 服务**（监听 `127.0.0.1:8080`），前面用 **Caddy** 做反向代理并自动向 Let's Encrypt 申请 HTTPS 证书。无需 Nginx/Apache。

### 一键部署（服务器上以 root 执行）

`deploy.sh` 会自动完成：安装 PHP 8.2 + 扩展、Composer、Caddy；写入 systemd 服务（`lottery.service`）与 Caddyfile；配置 sudoers 让 Web 后台可免密重启服务；放行防火墙。

```bash
# 方式一：直接克隆远程仓库（推荐，便于后续一键更新）
GIT_REPO=https://github.com/你的用户名/choujiang.git \
GIT_BRANCH=master \
DOMAIN=lucky.your-domain.com \
bash deploy.sh

# 方式二：代码已手动上传到目录（不依赖 git）
PROJECT_DIR=/var/www/lottery DOMAIN=lucky.your-domain.com bash deploy.sh
```

- 提供 `DOMAIN`：Caddy 自动签发 HTTPS，访问 `https://域名/` 与 `https://域名/admin`。
- 留空 `DOMAIN`：仅启用 HTTP（`http://服务器IP/`），适合无域名或测试。
- 端口/进程数可用 `PORT`（默认 8080）、`WORKERS`（默认 1，SQLite 建议保持 1）覆盖。

### 手动启动（不跑脚本时）

```bash
PORT=8080 php start.php start -d     # 守护进程
systemctl status lottery             # 查看服务状态
journalctl -u lottery -f             # 查看日志
```

### Caddyfile（脚本自动生成，可手动调整）

```caddyfile
your-domain.com {
    encode gzip
    reverse_proxy 127.0.0.1:8080
}
```

---

## 五、使用流程

1. 后台 `/admin` 登录 → 「奖项设置」添加/编辑奖项（等级、名称、价值、转盘颜色、图片可传多张、是否限量）。
2. 「站点设置」填写客服联系方式（微信/Line 等）和抽奖须知文案。
3. 「抽奖链接生成」：填写备注，选择抽奖次数（1–10），为**每一次**指定预设奖项 → 生成专属链接。
4. 把链接发给客户；客户打开即获得对应抽奖次数，转盘动画展示后结果落入「我的奖品栏」并自动保存（含客户填写的姓名/消费金额）。
5. 客户截图发给客服，客服按系统记录核对发放。

---

## 六、后台一键更新（自动更新服务器）

为了免去每次更新都要登录服务器（VNC/SSH）的麻烦，后台提供了「系统更新」按钮：

- **位置**：后台 → 左侧导航「系统更新」→ 点击「🚀 一键更新」。
- **做了什么**：后台以 `www-data` 身份在后台脱离请求执行 `update.sh`，依次完成 `git pull --ff-only` → `composer install` → 修正目录权限 → `sudo systemctl restart lottery`。页面每 2 秒轮询 `/admin?view=update_log` 显示进度，直到出现 `[update] done` 或 `[update] failed`。
- **数据安全**：`data/`（SQLite 库）与 `public/assets/uploads/`（图片）已被 `.gitignore` 忽略，更新不会覆盖你的奖项数据与客户上传图片。

### 前置条件：服务器必须能 `git pull`

按钮的本质是「让服务器拉取远程最新代码」，所以**服务器仓库需能免交互拉取 GitHub**。两种配置方式（二选一）：

**方式 A（推荐，最省事）— 部署时写入 Token**
部署脚本已支持 `GIT_TOKEN`：把仅有 `repo` 读权限的 GitHub Personal Access Token 作为环境变量传入，`deploy.sh` 会把 token 写进 git remote URL，之后 `update.sh` 的 `git pull` 即可免交互执行。

```bash
GIT_REPO=https://github.com/你的用户名/choujiang.git \
GIT_TOKEN=ghp_xxxxxxxxxxxx \
DOMAIN=lucky.your-domain.com \
bash deploy.sh
```

**方式 B — 服务器部署 SSH 部署公钥**
在服务器生成 SSH 密钥，把公钥添加为 GitHub 仓库的 Deploy Key（只读）。首次部署用 `GIT_REPO=git@github.com:用户名/choujiang.git` 克隆即可。

### 更新流程

1. 在本机（或 WorkBuddy）把改动提交并 `git push` 到 GitHub 远程仓库。
2. 登录后台 → 系统更新 → 点「一键更新」。
3. 等待日志出现 `[update] done`，刷新页面即生效。

> 若服务器无外网或 remote 未配置权限，`update.sh` 会跳过 `git pull` 并提示「未配置仓库权限」，此时可手动上传新代码后重启服务。

---

## 七、目录结构

```
choujiang/
├─ start.php              # Workerman 启动入口
├─ config.php             # 路径常量
├─ composer.json
├─ deploy.sh             # Ubuntu/Debian 一键部署（PHP+Caddy+systemd+sudoers）
├─ update.sh             # 一键更新脚本（git pull + 重启，由后台按钮触发）
├─ src/
│  ├─ Db.php              # SQLite 数据层（settings/prizes/links/draws）
│  ├─ App.php             # 路由 + 静态资源
│  ├─ Frontend.php        # 前台页（无链接→联系客服 / 有链接→转盘）
│  ├─ Admin.php           # 后台（登录/奖项/设置/链接）
│  ├─ Api.php             # /api/link /api/draw /api/customer
│  └─ Json.php            # JSON 响应辅助
├─ public/assets/
│  ├─ css/style.css      # 前台样式
│  ├─ css/admin.css       # 后台样式
│  ├─ js/wheel.js         # 转盘绘制与旋转动画
│  ├─ js/frontend.js      # 前台抽奖逻辑
│  └─ js/admin.js         # 后台交互
├─ data/                 # SQLite 库（运行时生成，已 gitignore）
└─ README.md
```

---

## 八、常见问题

- **转盘不转/结果不对？** 转盘只是动画，真实结果来自 `/api/draw`（由链接预设决定）。若指针未停在该奖项，检查 `wheel.js` 的 `prizeIndexById` 映射。
- **多进程？** SQLite 单文件建议 `WORKERS=1`（`start.php` 已默认），需要更大并发请改用 MySQL 并增加 worker 数。
- **图片不显示？** 上传文件存于 `public/assets/uploads/`，确保该目录可写且能被静态路由访问。
- **忘记后台密码？** 删除 `data/lottery.db` 并重启，密码会重置为 `admin888`（会清空所有数据，请先备份）。
