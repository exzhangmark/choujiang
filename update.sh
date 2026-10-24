#!/usr/bin/env bash
#
# 一键更新脚本（由后台“系统更新”按钮触发，以 www-data 身份运行）
# 流程：git pull → composer install → 修正权限 → 重启 lottery 服务
#
set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG="$PROJECT_DIR/data/update.log"
GIT=/usr/bin/git
COMPOSER=/usr/local/bin/composer
PHP=/usr/bin/php
SYSTEMCTL=/usr/bin/systemctl
RUN_USER=www-data

# 日志同时输出到文件（后台读取）
exec > >(tee -a "$LOG") 2>&1
echo "[update] $(date) start"

cd "$PROJECT_DIR" || { echo "[update] failed: cannot cd $PROJECT_DIR"; exit 1; }

# 1. 拉取最新代码（需服务器对该仓库有 pull 权限）
if [ -d .git ]; then
  $GIT config --global --add safe.directory "$PROJECT_DIR" 2>/dev/null || true
  echo "[update] git pull..."
  if ! $GIT pull --ff-only 2>&1; then
    echo "[update] git pull 失败（可能无网络或未配置仓库权限），将继续"
  fi
else
  echo "[update] 未检测到 git 仓库，跳过代码拉取（请自行上传新代码）"
fi

# 2. 更新依赖
if [ -f composer.json ]; then
  echo "[update] composer install..."
  $COMPOSER install --no-interaction --no-dev --optimize-autoloader 2>&1 || echo "[update] composer install 失败"
fi

# 3. 修正目录权限
chown -R "$RUN_USER:$RUN_USER" "$PROJECT_DIR" 2>/dev/null || true
chmod -R ug+rwX "$PROJECT_DIR/data" "$PROJECT_DIR/public/assets/uploads" 2>/dev/null || true

# 4. 重启服务（www-data 通过 sudoers 免密执行 systemctl）
echo "[update] restart service..."
if command -v "$SYSTEMCTL" >/dev/null 2>&1; then
  sudo "$SYSTEMCTL" restart lottery 2>&1 || echo "[update] systemctl restart 失败"
else
  "$PHP" "$PROJECT_DIR/start.php" restart -d 2>/dev/null || true
fi

echo "[update] done"
