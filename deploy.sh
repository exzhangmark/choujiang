#!/usr/bin/env bash
#
# 万圣节大转盘抽奖系统 —— Ubuntu/Debian 一键部署脚本（不使用 Cloudflare）
#
# 架构：Workerman(PHP) 监听 127.0.0.1:8080  →  Caddy 反代并自动签发 HTTPS
#   - 提供域名 DOMAIN：Caddy 自动用 Let's Encrypt 签发证书，访问 https://域名
#   - 不提供域名：Caddy 仅监听 :80 提供纯 HTTP（http://服务器IP）
#
# 用法（在 Ubuntu/Debian 服务器上以 root 执行）：
#   PROJECT_DIR=/var/www/lottery DOMAIN=lucky.example.com bash deploy.sh
#   DOMAIN=            # 留空 = 仅 HTTP
#   GIT_REPO=         # 可选：填写 git 地址则自动克隆到 PROJECT_DIR
#   GIT_TOKEN=        # 可选：GitHub PAT（仅 repo 读权限），写入 remote 使一键更新可免交互 pull
#
set -euo pipefail

# ===================== 可配置变量（可用环境变量覆盖） =====================
PROJECT_DIR="${PROJECT_DIR:-/var/www/lottery}"
DOMAIN="${DOMAIN:-}"
GIT_REPO="${GIT_REPO:-}"
GIT_BRANCH="${GIT_BRANCH:-master}"
# 可选：GitHub Personal Access Token（仅 repo 读权限即可）。
# 提供后，deploy.sh 会把 token 写入 git remote URL，使 update.sh 的 git pull 免交互。
GIT_TOKEN="${GIT_TOKEN:-}"
PORT="${PORT:-8080}"
WORKERS="${WORKERS:-1}"          # SQLite 建议保持 1；换 MySQL 后可调大
RUN_USER="${RUN_USER:-www-data}"
PHP_VER="8.2"
# =========================================================================

log()  { echo -e "\033[32m[部署]\033[0m $*"; }
warn() { echo -e "\033[33m[注意]\033[0m $*"; }
die()  { echo -e "\033[31m[错误]\033[0m $*" >&2; exit 1; }

# ---- 前置检查 ----
[[ $EUID -eq 0 ]] || die "请使用 root 执行（sudo bash deploy.sh）"
if [[ -f /etc/os-release ]]; then
  . /etc/os-release
  [[ "${ID:-}" == "ubuntu" || "${ID:-}" == "debian" ]] || die "本脚本仅支持 Ubuntu / Debian，当前系统: ${ID:-未知}"
else
  die "无法识别操作系统"
fi
PHP_BIN="$(command -v php || true)"
if [[ -z "$PHP_BIN" ]]; then PHP_BIN="/usr/bin/php"; fi

# ---- 1. 安装 PHP 8.2 + 扩展、Composer ----
install_php() {
  log "安装 PHP ${PHP_VER} 及扩展…"
  apt-get update -y
  apt-get install -y software-properties-common ca-certificates lsb-release apt-transport-https gnupg2 curl unzip git
  if ! command -v php >/dev/null 2>&1 || ! php -v 2>/dev/null | grep -q "${PHP_VER}"; then
    # 添加 sury PHP 源
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
      > /etc/apt/sources.list.d/php.list
    apt-get update -y
    apt-get install -y "php${PHP_VER}-cli" "php${PHP_VER}-sqlite3" "php${PHP_VER}-mbstring" "php${PHP_VER}-ctype"
  fi
  PHP_BIN="$(command -v php)"
  php -v | head -1
  log "PHP 就绪：${PHP_BIN}"

  if ! command -v composer >/dev/null 2>&1; then
    log "安装 Composer…"
    EXPECTED="$(curl -sSL https://composer.github.io/installer.sig)"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    [[ "$EXPECTED" == "$ACTUAL" ]] || die "Composer 安装器校验失败"
    php composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
  fi
  composer --version | head -1
}

# ---- 2. 准备项目代码 ----
# 若提供 GIT_TOKEN 且仓库为 https，则把 token 嵌入 remote URL，使后续 git pull 免交互
auth_repo() {
  local repo="$1"
  if [[ -n "$GIT_TOKEN" && "$repo" == https://* ]]; then
    echo "${repo/https:\/\//https:\/\/${GIT_TOKEN}@}"
  else
    echo "$repo"
  fi
}

setup_project() {
  if [[ -n "$GIT_REPO" ]]; then
    local AUTH_REPO
    AUTH_REPO="$(auth_repo "$GIT_REPO")"
    log "克隆项目：${GIT_REPO} (${GIT_BRANCH}) → ${PROJECT_DIR}"
    if [[ -d "$PROJECT_DIR/.git" ]]; then
      git -C "$PROJECT_DIR" pull --ff-only
    else
      rm -rf "$PROJECT_DIR"
      git clone -b "$GIT_BRANCH" "$AUTH_REPO" "$PROJECT_DIR"
    fi
    # 若当初用 token 克隆，origin 已含 token；否则补写一次，确保 update.sh 能 pull
    if [[ -n "$GIT_TOKEN" ]]; then
      git -C "$PROJECT_DIR" remote set-url origin "$AUTH_REPO" 2>/dev/null || true
    fi
  else
    [[ -d "$PROJECT_DIR" && -f "$PROJECT_DIR/start.php" ]] || die "未指定 GIT_REPO，且 ${PROJECT_DIR} 不存在 start.php，请先把代码放到该目录"
  fi
  log "Composer 安装依赖…"
  ( cd "$PROJECT_DIR" && composer install --no-interaction --no-dev --optimize-autoloader )
  # 目录权限
  mkdir -p "$PROJECT_DIR/data" "$PROJECT_DIR/public/assets/uploads"
  chown -R "${RUN_USER}:${RUN_USER}" "$PROJECT_DIR"
  chmod -R ug+rwX "$PROJECT_DIR/data" "$PROJECT_DIR/public/assets/uploads"
}

# ---- 3. 安装 Caddy ----
install_caddy() {
  if command -v caddy >/dev/null 2>&1; then
    log "Caddy 已存在：$(caddy version)"
    return
  fi
  log "安装 Caddy（自动 HTTPS 反代）…"
  curl -sSLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
    | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -sSLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    | tee /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -y
  apt-get install -y caddy
  caddy version
}

# ---- 4. 配置 sudoers：允许 www-data 免密重启 lottery（一键更新按钮依赖） ----
setup_sudoers() {
  local f="/etc/sudoers.d/lottery-www-data"
  log "写入 sudoers（www-data 免密执行 systemctl restart/status lottery）…"
  cat > "$f" <<EOF
# 允许 Web 后台“一键更新”在无密码情况下重启 lottery 服务
${RUN_USER} ALL=(root) NOPASSWD: ${SYSTEMCTL} restart lottery
${RUN_USER} ALL=(root) NOPASSWD: ${SYSTEMCTL} status lottery
EOF
  chmod 0440 "$f"
  # 校验语法，避免写错导致 sudo 整体失效
  if command -v visudo >/dev/null 2>&1; then
    visudo -cf "$f" && log "sudoers 语法校验通过" || warn "sudoers 语法校验失败，请检查 $f"
  fi
}

# ---- 5. 写配置：systemd 单元 + Caddyfile ----
write_configs() {
  log "写入 systemd 服务单元…"
  cat > /etc/systemd/system/lottery.service <<EOF
[Unit]
Description=Halloween Lottery (PHP Workerman)
After=network.target

[Service]
Type=simple
User=${RUN_USER}
Group=${RUN_USER}
WorkingDirectory=${PROJECT_DIR}
Environment=PORT=${PORT}
Environment=WORKERS=${WORKERS}
ExecStart=${PHP_BIN} ${PROJECT_DIR}/start.php
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable --now lottery.service
  sleep 2
  if ! systemctl is-active --quiet lottery.service; then
    warn "lottery 服务未运行，查看：journalctl -u lottery -n 50"
    systemctl status lottery.service --no-pager || true
  else
    log "lottery 服务已启动"
  fi

  log "写入 Caddy 配置…"
  if [[ -n "$DOMAIN" ]]; then
    cat > /etc/caddy/Caddyfile <<EOF
${DOMAIN} {
    encode gzip
    reverse_proxy 127.0.0.1:${PORT}
}
EOF
    warn "请确保域名 ${DOMAIN} 的 DNS A 记录已指向本机公网 IP，且 80/443 端口可访问，否则证书签发会失败"
  else
    cat > /etc/caddy/Caddyfile <<EOF
:80 {
    encode gzip
    reverse_proxy 127.0.0.1:${PORT}
}
EOF
    warn "未提供 DOMAIN，仅启用 HTTP（http://服务器IP）。如需 HTTPS 请重新运行并指定 DOMAIN="
  fi
  systemctl enable --now caddy
  sleep 2
  systemctl is-active --quiet caddy && log "Caddy 已启动" || warn "Caddy 未运行，查看：journalctl -u caddy -n 50"
}

# ---- 6. 防火墙 ----
open_firewall() {
  if command -v ufw >/dev/null 2>&1 && ufw status | grep -q "Status: active"; then
    log "放行 80/443/22…"
    ufw allow 80/tcp; ufw allow 443/tcp; ufw allow 22/tcp
  else
    warn "未检测到激活的 ufw，请自行确认 80/443 端口在安全组/防火墙已放行"
  fi
}

# ---- 收尾 ----
finish() {
  IP="$(curl -sSL --max-time 5 ifconfig.me || hostname -I | awk '{print $1}')"
  echo
  echo "==================== 部署完成 ===================="
  if [[ -n "$DOMAIN" ]]; then
    echo "  前台：  https://${DOMAIN}/"
    echo "  后台：  https://${DOMAIN}/admin   （默认密码 admin888，请尽快修改）"
  else
    echo "  前台：  http://${IP}/"
    echo "  后台：  http://${IP}/admin   （默认密码 admin888，请尽快修改）"
  fi
  echo "  项目目录：${PROJECT_DIR}"
  echo "  进程管理： systemctl status lottery / caddy"
  echo "  查看日志： journalctl -u lottery -f"
  echo "================================================="
}

main() {
  log "开始部署（系统: ${PRETTY_NAME:-Linux}, PHP 目标版本 ${PHP_VER}）"
  install_php
  setup_project
  install_caddy
  write_configs
  setup_sudoers
  open_firewall
  finish
}

main "$@"
