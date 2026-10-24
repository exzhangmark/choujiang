# 服务器部署 + 更新 操作清单

> 适用系统：Ubuntu 20.04+ / Debian 11+ 的 VPS。架构：**Workerman(PHP) 127.0.0.1:8080 + Caddy 反代 + Let's Encrypt 自动 HTTPS**。
> 仓库：`https://github.com/exzhangmark/choujiang`（私有，分支 `master`）。

---

## 一、首次部署（只需做一次）

### 1.1 准备项（打勾）

- [ ] 一台 Ubuntu/Debian 的 VPS，有公网 IP
- [ ] （可选）一个域名：DNS A 记录指向服务器 IP，且 **80/443 端口可从公网访问**（证书签发需要）
- [ ] 一个 GitHub **只读** Personal Access Token（`repo` 权限即可），用于服务器拉取代码
- [ ] 通过服务商 **VNC 控制台**（或 SSH）以 **root** 登录服务器

> ⚠️ 建议：**服务器专用一个只读 token**，不要复用你平时有写权限的主账号 token。聊天里暴露过的 token 用完即吊销。

### 1.2 在服务器上执行（推荐：先用 token 克隆，再跑脚本）

```bash
# 1. 装 git（若未装）
apt-get update && apt-get install -y git curl

# 2. 用 token 克隆仓库（token 会写入服务器 git remote，供后续一键更新使用）
git clone https://exzhangmark:你的服务器只读token@github.com/exzhangmark/choujiang.git /var/www/lottery

# 3. 运行一键部署脚本
cd /var/www/lottery
DOMAIN=lucky.example.com bash deploy.sh
```

- **有域名**：把 `DOMAIN` 换成你的域名 → Caddy 自动签发 HTTPS，访问 `https://域名/`
- **无域名**：省略 `DOMAIN=` → 仅启用 HTTP（`http://服务器IP/`），适合测试
- 脚本会自动完成：装 PHP 8.2 + 扩展、Composer、Caddy；写 systemd 服务 `lottery.service`；写 sudoers（让 Web 后台可免密重启）；放行防火墙 80/443/22

> 备用方式：若你已把 `deploy.sh` 下载到服务器，也可不先克隆，而是：
> `GIT_REPO=https://github.com/exzhangmark/choujiang.git GIT_TOKEN=你的token DOMAIN=lucky.example.com bash deploy.sh`
> （此时脚本会自己用 token 克隆并写入 remote）

### 1.3 验证部署

```bash
systemctl status lottery      # 应显示 active (running)
systemctl status caddy        # 应显示 active (running)
curl -sS -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/   # 应返回 200
```

- 浏览器打开 `https://你的域名/admin`（或 `http://服务器IP/admin`）
- **默认后台密码：`admin888`**，登录后请立即到「站点设置」修改
- 打开 `/` 前台，默认应提示「您还没有抽奖机会，请联系客服」

---

## 二、日常更新（一键更新）

### 2.1 本地改代码 → 推送

```bash
# 在本机（或 WorkBuddy）提交并推送
git add -A
git commit -m "描述改动"
git push origin master
```

### 2.2 服务器上一键更新

1. 登录后台 `/admin`
2. 左侧导航点「**系统更新**」
3. 点「🚀 **一键更新**」按钮
4. 等待下方日志出现 **`[update] done`**（按钮会每隔 2 秒自动刷新进度；失败则显示 `[update] failed`）
5. 刷新页面即可生效

> 按钮在后台触发 `update.sh`：依次 `git pull --ff-only` → `composer install` → 修正目录权限 → `sudo systemctl restart lottery`。
> 数据（`data/lottery.db`）和上传图片（`public/assets/uploads/`）被 gitignore，更新不会覆盖。

### 2.3 手动更新（可选，在服务器上 root 执行）

```bash
cd /var/www/lottery
bash update.sh
tail -f data/update.log      # 实时看进度
```

---

## 三、故障排查

| 现象 | 排查命令 / 处理 |
|------|----------------|
| lottery 服务未启动 | `journalctl -u lottery -n 50` |
| 打不开网页 / 502 | `journalctl -u caddy -n 50`；确认 `systemctl is-active caddy lottery` |
| 一键更新卡住 | `tail -f /var/www/lottery/data/update.log` |
| git pull 失败 | 多半是服务器 remote 没有有效 token：重新 `git remote set-url origin https://exzhangmark:新token@github.com/exzhangmark/choujiang.git` |
| HTTPS 证书签发失败 | 确认域名 DNS A 指向正确、80 端口公网可达；Let's Encrypt 有频率限制，别频繁重试 |
| 转盘不转 / 结果不对 | 转盘只是动画，真实结果由链接预设决定。详见 `README.md` 常见问题 |
| 忘记后台密码 | 删除 `data/lottery.db` 并重启（会清空所有数据，先备份！） |

---

## 四、安全清单（部署后务必做）

- [ ] 登录后台立即修改默认密码 `admin888`
- [ ] 服务器使用**专用只读 token** 拉取代码；聊天里出现过的 token 尽快到 GitHub 吊销
- [ ] 服务器防火墙只放行 80 / 443 / 22（脚本已用 ufw 处理；云厂商安全组也要同步）
- [ ] 定期备份数据库：`cp /var/www/lottery/data/lottery.db /备份目录/lottery-$(date +%F).db`

---

## 五、回滚（如需）

```bash
cd /var/www/lottery
git log --oneline -5                 # 找到要回退到的提交哈希
git reset --hard <旧提交哈希>
systemctl restart lottery
```

---

## 六、常用命令速查

```bash
systemctl status lottery             # 查看抽奖服务状态
systemctl restart lottery            # 手动重启抽奖服务
journalctl -u lottery -f             # 跟踪抽奖服务日志
systemctl status caddy              # 查看反代状态
ufw status                          # 查看防火墙
```
