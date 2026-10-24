<?php
/**
 * 全局配置
 * 所有路径相关的常量都集中在这里，方便在不同服务器上部署。
 */
if (!defined('BASE_DIR')) {
    define('BASE_DIR', __DIR__);
}
define('PUBLIC_DIR', BASE_DIR . '/public');
define('DATA_DIR', BASE_DIR . '/data');
define('UPLOAD_DIR', PUBLIC_DIR . '/assets/uploads');
define('UPLOAD_URL', '/assets/uploads');

// 运行时目录（SQLite 数据库、上传图片）确保存在
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}
define('DB_FILE', DATA_DIR . '/lottery.db');

// 会话/后台登录 Cookie 名称
define('ADMIN_COOKIE', 'wb_lottery_admin');
define('ADMIN_COOKIE_TTL', 60 * 60 * 24 * 7); // 7 天
